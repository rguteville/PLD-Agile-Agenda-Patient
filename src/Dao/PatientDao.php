<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Patient;
use Aura\Domain\RecordFields;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use PDO;

/**
 * Accès à la table Patient. Les colonnes suivent database/schema.sql (camelCase anglais,
 * cf. README « Convention de nommage ») ; les entités du domaine restent en français.
 */
class PatientDao
{
    /** Colonnes d'identité, lues et écrites par toutes les méthodes. */
    private const IDENTITY_COLUMNS = 'idPatient, ipp, lastName, firstName, birthDate, sex, phoneNumber, email';

    /**
     * Colonnes d'identité + champs complémentaires (RecordFields::PATIENT, dont les clés SONT
     * les noms de colonnes). Construite une fois : la liste blanche du catalogue borne ce que
     * le DAO lit et écrit.
     */
    private static ?string $columns = null;

    public function __construct(private PDO $pdo)
    {
    }

    private static function columns(): string
    {
        return self::$columns ??= self::IDENTITY_COLUMNS . ', ' . implode(', ', RecordFields::patientColumns());
    }

    /** @return Patient[] */
    public function findAll(): array
    {
        // Liste : l'identité suffit, les champs complémentaires (TEXT) ne sont pas chargés.
        $rows = $this->pdo->query('SELECT ' . self::IDENTITY_COLUMNS . ' FROM Patient ORDER BY lastName, firstName')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->mapToEntity($r), $rows);
    }

    /**
     * Patients dont le nom, le prénom ou l'IPP contient le terme saisi (AURA-12.1),
     * triés par nom puis prénom. Le terme est passé en paramètre préparé et ses
     * caractères joker (% et _) sont échappés : il est impossible d'injecter du SQL
     * ni d'élargir la recherche avec un joker.
     *
     * @return Patient[]
     */
    public function search(string $terme): array
    {
        $motif = '%' . addcslashes($terme, '\\%_') . '%';

        // Sans émulation des requêtes préparées, un paramètre nommé ne peut servir qu'une fois.
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::IDENTITY_COLUMNS . ' FROM Patient
             WHERE lastName LIKE :nom ESCAPE \'\\\\\'
                OR firstName LIKE :prenom ESCAPE \'\\\\\'
                OR CAST(ipp AS CHAR) LIKE :ipp ESCAPE \'\\\\\'
             ORDER BY lastName, firstName'
        );
        $stmt->execute(['nom' => $motif, 'prenom' => $motif, 'ipp' => $motif]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Patients dont chaque mot recherché commence un mot du nom d'usage, du nom de naissance ou du
     * prénom (PR « admission-recherche-patient »). Les mots sont déjà découpés et nettoyés par
     * PatientSearchService ; ici ils sont passés en paramètres préparés, jokers échappés.
     *
     * Casse et accents : la table est en utf8mb4_unicode_ci (vérifié sur MariaDB 11.8), LIKE et =
     * ignorent donc les deux. Tirets et apostrophes des noms stockés sont remplacés par des espaces
     * avant comparaison (« Jean-Pierre » contient le mot « Pierre »). Tri : nom d'usage égal à l'un
     * des mots d'abord, puis nom, prénom. Avec la date du dernier séjour (admission la plus récente).
     *
     * @param list<string> $words mots non vides
     * @return list<array{patient: Patient, lastAdmission: ?DateTimeImmutable}>
     */
    public function searchByWords(array $words, int $limit): array
    {
        if ($words === []) {
            return [];
        }

        $conditions = [];
        $exact = [];
        $params = [];
        foreach (array_values($words) as $i => $word) {
            $pattern = '% ' . addcslashes($word, '\\%_') . '%';
            // Sans émulation des requêtes préparées, chaque paramètre nommé ne sert qu'une fois.
            $params['ln' . $i] = $pattern;
            $params['fn' . $i] = $pattern;
            $params['bn' . $i] = $pattern;
            $params['ex' . $i] = $word;
            $conditions[] = sprintf(
                "(%s LIKE :ln%d ESCAPE '\\\\' OR %s LIKE :fn%d ESCAPE '\\\\' OR %s LIKE :bn%d ESCAPE '\\\\')",
                self::wordsOf('lastName'),
                $i,
                self::wordsOf('firstName'),
                $i,
                self::wordsOf('birthLastName'),
                $i
            );
            $exact[] = 'lastName = :ex' . $i;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::columns() . ',
                    (SELECT MAX(e.admissionDate) FROM EpisodeOfCare e WHERE e.idPatient = Patient.idPatient)
                        AS lastAdmission
             FROM Patient
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY (' . implode(' OR ', $exact) . ') DESC, lastName, firstName, idPatient
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $results[] = [
                'patient' => $this->mapToEntity($r),
                'lastAdmission' => $r['lastAdmission'] !== null ? new DateTimeImmutable($r['lastAdmission']) : null,
            ];
        }

        return $results;
    }

    /** Date d'admission la plus récente d'un patient (null s'il n'a aucun séjour). */
    public function lastAdmissionDate(int $idPatient): ?DateTimeImmutable
    {
        $stmt = $this->pdo->prepare('SELECT MAX(admissionDate) FROM EpisodeOfCare WHERE idPatient = :idPatient');
        $stmt->execute(['idPatient' => (string) $idPatient]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    /** Expression SQL : la colonne précédée d'un espace, tirets et apostrophes remplacés par des espaces. */
    private static function wordsOf(string $column): string
    {
        return "CONCAT(' ', REPLACE(REPLACE(COALESCE(" . $column . ", ''), '-', ' '), '\\'', ' '))";
    }

    /** Patient par identifiant interne, champs complémentaires compris ; null s'il n'existe pas. */
    public function findById(int $id): ?Patient
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::columns() . ' FROM Patient WHERE idPatient = :id');
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    /** Patient portant cet IPP (unique) ; null s'il n'existe pas. */
    public function findByIpp(string $ipp): ?Patient
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::columns() . ' FROM Patient WHERE ipp = :ipp');
        $stmt->execute(['ipp' => $ipp]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    /** Vrai si un patient porte cet IPP. */
    public function existsByIpp(string $ipp): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM Patient WHERE ipp = :ipp');
        $stmt->execute(['ipp' => $ipp]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Patients portant exactement ce nom, ce prénom et cette date de naissance
     * (reconnaissance d'un patient déjà venu dans le service, sans IPP).
     *
     * @return Patient[]
     */
    public function findByIdentite(string $nom, string $prenom, DateTimeInterface $dateNaissance): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::columns() . ' FROM Patient
             WHERE lastName = :lastName AND firstName = :firstName AND birthDate = :birthDate'
        );
        $stmt->execute([
            'lastName' => $nom,
            'firstName' => $prenom,
            'birthDate' => $dateNaissance->format('Y-m-d'),
        ]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Insère le patient et renvoie son identifiant.
     *
     * Le schéma actuel n'a pas d'AUTO_INCREMENT sur idPatient : l'id est donc
     * calculé ici (plus grand id + 1), dans une transaction qui verrouille la
     * table pour éviter que deux inscriptions simultanées obtiennent le même id.
     * Si une transaction est déjà ouverte (inscription + séjour), on s'y rattache.
     */
    public function insert(Patient $p): int
    {
        return (new Transaction($this->pdo))->run(function () use ($p): int {
            $id = (int) $this->pdo
                ->query('SELECT COALESCE(MAX(CAST(idPatient AS UNSIGNED)), 0) + 1 FROM Patient FOR UPDATE')
                ->fetchColumn();

            $details = self::detailValues($p);
            $stmt = $this->pdo->prepare(
                'INSERT INTO Patient (' . self::columns() . ')
                 VALUES (:idPatient, :ipp, :lastName, :firstName, :birthDate, :sex, :phoneNumber, :email, '
                . implode(', ', array_map(static fn (string $c): string => ':' . $c, array_keys($details))) . ')'
            );
            $stmt->execute([
                'idPatient' => (string) $id,
                'ipp' => $p->getIpp(),
                'lastName' => $p->getNom(),
                'firstName' => $p->getPrenom(),
                'birthDate' => $p->getDateNaissance()?->format('Y-m-d'),
                'sex' => $p->getSexe(),
                'phoneNumber' => $p->getTelephone(),
                'email' => $p->getEmail(),
            ] + $details);

            return $id;
        });
    }

    /**
     * Met à jour toutes les colonnes du patient (AURA-12.2), champs complémentaires compris :
     * l'appelant doit donc transmettre les détails existants s'il ne veut pas les effacer.
     * L'id n'est jamais modifié ; une seule instruction SQL, donc pas de transaction ici.
     */
    public function update(Patient $p): void
    {
        $id = $p->getIdPatient();
        if ($id === null) {
            throw new LogicException('PatientDao::update() requiert un patient déjà enregistré (id non nul).');
        }

        $details = self::detailValues($p);
        $stmt = $this->pdo->prepare(
            'UPDATE Patient
            SET ipp = :ipp, lastName = :lastName, firstName = :firstName, birthDate = :birthDate,
                sex = :sex, phoneNumber = :phoneNumber, email = :email, '
            . self::assignments(array_keys($details)) . '
            WHERE idPatient = :idPatient'
        );
        $stmt->execute([
            'idPatient' => (string) $id,
            'ipp' => $p->getIpp(),
            'lastName' => $p->getNom(),
            'firstName' => $p->getPrenom(),
            'birthDate' => $p->getDateNaissance()?->format('Y-m-d'),
            'sex' => $p->getSexe(),
            'phoneNumber' => $p->getTelephone(),
            'email' => $p->getEmail(),
        ] + $details);
    }

    /**
     * Met à jour seulement les champs complémentaires fournis (clés de RecordFields::PATIENT ;
     * toute autre clé est ignorée). Sert à compléter un patient déjà connu lors d'une
     * nouvelle admission sans toucher à son identité.
     *
     * @param array<string, ?string> $details
     */
    public function updateDetails(int $idPatient, array $details): void
    {
        $details = array_intersect_key($details, RecordFields::PATIENT);
        if ($details === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE Patient SET ' . self::assignments(array_keys($details)) . ' WHERE idPatient = :idPatient'
        );
        $stmt->execute(['idPatient' => (string) $idPatient] + $details);
    }

    /** « col1 = :col1, col2 = :col2 » pour des noms de colonnes issus du catalogue (jamais de la requête). */
    private static function assignments(array $columns): string
    {
        return implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, $columns));
    }

    /**
     * Valeurs des champs complémentaires à écrire, une par colonne du catalogue (null si absente).
     *
     * @return array<string, ?string>
     */
    private static function detailValues(Patient $p): array
    {
        $values = [];
        foreach (RecordFields::patientColumns() as $column) {
            $values[$column] = $p->getDetail($column);
        }

        return $values;
    }

    private function mapToEntity(array $r): Patient
    {
        return new Patient(
            idPatient: (int) $r['idPatient'],
            ipp: $r['ipp'] !== null ? (string) $r['ipp'] : null,
            nom: $r['lastName'],
            prenom: $r['firstName'],
            dateNaissance: $r['birthDate'] !== null ? new DateTimeImmutable($r['birthDate']) : null,
            sexe: $r['sex'],
            telephone: $r['phoneNumber'],
            email: $r['email'],
            details: array_map(
                static fn ($v): ?string => $v === null ? null : (string) $v,
                array_intersect_key($r, RecordFields::PATIENT)
            )
        );
    }
}
