<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Patient;
use Aura\Domain\RecordFields;
use DateTimeImmutable;
use LogicException;
use PDO;

/**
 * Accès à la table Patient, ressource HL7 du même nom (cf. database/schema.sql). Seule traduction :
 * la colonne phoneNumber devient phone dans l'entité. Id VARCHAR(20) calculé par insert()
 * (MAX + 1, FOR UPDATE) ; IPP calculé par nextIpp() quand l'admission n'en fournit pas.
 *
 * AURA-12.3 : un patient supprimé (removedAt renseigné, suppression logique) n'est renvoyé par
 * aucune lecture (liste, recherches, findById, findByIpp). Seuls existsByIpp(),
 * ippOwnerId() et nextIpp() le voient encore : son IPP reste réservé (colonne UNIQUE).
 */
class PatientDao
{
    /** Condition des patients actifs (non supprimés, AURA-12.3), ajoutée à toutes les lectures. */
    private const ACTIVE = 'removedAt IS NULL';

    /** Colonnes d'identité, lues et écrites par toutes les méthodes. */
    private const IDENTITY_COLUMNS = 'idPatient, ipp, lastName, firstName, birthDate, sex, phoneNumber, email';

    /**
     * Colonnes de la liste des patients (findAll(), search()) : l'identité et le décès, affiché dans la
     * liste ; deceased arrive dans les champs complémentaires.
     */
    private const LIST_COLUMNS = self::IDENTITY_COLUMNS . ', deceased';

    /**
     * Colonnes d'identité + champs complémentaires (RecordFields::PATIENT, dont les clés SONT
     * les noms de colonnes). Construite une fois : la liste blanche du catalogue borne ce que
     * le DAO lit et écrit.
     */
    private static ?string $columns = null;

    public function __construct(private PDO $pdo)
    {
    }

    /** Liste des colonnes de $columns, calculée au premier appel puis gardée en cache. */
    private static function columns(): string
    {
        return self::$columns ??= self::IDENTITY_COLUMNS . ', ' . implode(', ', RecordFields::patientColumns());
    }

    /**
     * Patients actifs, par nom puis prénom. Lecture partielle (LIST_COLUMNS) : seul « deceased »
     * figure dans les champs complémentaires, les autres valent null. Ces entités servent à
     * l'affichage : ne jamais les passer à update(), qui effacerait les champs non lus.
     *
     * @return Patient[]
     */
    public function findAll(): array
    {
        // Liste : l'identité et le décès suffisent, les autres champs complémentaires (TEXT) ne sont pas chargés.
        $rows = $this->pdo->query(
            'SELECT ' . self::LIST_COLUMNS . ' FROM Patient WHERE ' . self::ACTIVE . ' ORDER BY lastName, firstName'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->mapToEntity($r), $rows);
    }

    /**
     * Patients dont le nom, le prénom ou l'IPP contient le terme saisi (AURA-12.1),
     * triés par nom puis prénom. Le terme est passé en paramètre préparé et ses
     * caractères joker (% et _) sont échappés : il est impossible d'injecter du SQL
     * ni d'élargir la recherche avec un joker. Patients supprimés exclus ; lecture partielle
     * comme findAll().
     *
     * @return Patient[]
     */
    public function search(string $term): array
    {
        $pattern = '%' . addcslashes($term, '\\%_') . '%';

        // Sans émulation des requêtes préparées, un paramètre nommé ne peut servir qu'une fois.
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::LIST_COLUMNS . ' FROM Patient
             WHERE ' . self::ACTIVE . '
               AND (lastName LIKE :lastName ESCAPE \'\\\\\'
                OR firstName LIKE :firstName ESCAPE \'\\\\\'
                OR CAST(ipp AS CHAR) LIKE :ipp ESCAPE \'\\\\\')
             ORDER BY lastName, firstName'
        );
        $stmt->execute(['lastName' => $pattern, 'firstName' => $pattern, 'ipp' => $pattern]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Patients dont chaque mot recherché commence un mot du nom d'usage, du nom de naissance ou du
     * prénom (recherche d'un patient connu à l'admission). Les mots sont déjà découpés et nettoyés par
     * PatientSearchService ; ici ils sont passés en paramètres préparés, jokers échappés.
     *
     * Casse et accents : la table est en utf8mb4_unicode_ci (vérifié sur MariaDB 11.8), LIKE et =
     * ignorent donc les deux. Tirets et apostrophes des noms stockés sont remplacés par des espaces
     * avant comparaison (« Jean-Pierre » contient le mot « Pierre »). Tri : nom d'usage égal à l'un
     * des mots d'abord, puis nom, prénom. Avec la date du dernier séjour (admission la plus récente).
     * Performance : l'expression calculée empêche l'usage d'un index pour le filtre (balayage de la
     * table Patient, quelques milliers de lignes au plus dans un service) ; les index sur lastName et
     * firstName servent au tri. Patients supprimés exclus.
     *
     * @param list<string> $words mots non vides
     * @param int $limit nombre maximal de résultats (au moins 1)
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
             WHERE ' . self::ACTIVE . ' AND ' . implode(' AND ', $conditions) . '
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

    /** Plus petit IPP attribuable (contrainte CHECK de la colonne Patient.ipp : 100000 à 999999999). */
    public const IPP_MIN = 100000;
    public const IPP_MAX = 999999999;

    /**
     * Prochain IPP libre (plus grand IPP + 1, au moins IPP_MIN), lu en verrouillant la table : à appeler
     * dans la transaction qui insère le patient, comme le calcul de idPatient (deux admissions
     * simultanées n'obtiennent pas le même IPP ; la contrainte UNIQUE reste la garantie finale).
     *
     * @throws LogicException si la plage des IPP est épuisée
     */
    public function nextIpp(): int
    {
        $next = (int) $this->pdo
            ->query('SELECT COALESCE(MAX(ipp), ' . (self::IPP_MIN - 1) . ') + 1 FROM Patient FOR UPDATE')
            ->fetchColumn();
        if ($next > self::IPP_MAX) {
            throw new LogicException('Plus aucun IPP disponible dans la plage autorisée.');
        }

        return max($next, self::IPP_MIN);
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
        // Apostrophe droite et apostrophe typographique (’), comme le découpage de PatientSearchService::words().
        return "CONCAT(' ', REPLACE(REPLACE(REPLACE(COALESCE(" . $column . ", ''), '-', ' '), '\\'', ' '), '’', ' '))";
    }

    /** Patient par identifiant interne, champs complémentaires compris ; null s'il n'existe pas ou est supprimé. */
    public function findById(int $id): ?Patient
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::columns() . ' FROM Patient WHERE idPatient = :id AND ' . self::ACTIVE
        );
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    /**
     * Patient actif portant cet IPP (unique) ; null s'il n'existe pas ou est supprimé. Pour savoir si
     * l'IPP est libre (patients supprimés compris), utiliser existsByIpp() ou ippOwnerId().
     */
    public function findByIpp(string $ipp): ?Patient
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::columns() . ' FROM Patient WHERE ipp = :ipp AND ' . self::ACTIVE);
        $stmt->execute(['ipp' => $ipp]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    /** Vrai si un patient porte cet IPP, même supprimé : l'IPP reste réservé (colonne UNIQUE). */
    public function existsByIpp(string $ipp): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM Patient WHERE ipp = :ipp');
        $stmt->execute(['ipp' => $ipp]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Identifiant du patient qui porte cet IPP, même supprimé ; null si l'IPP est libre. Sert aux
     * contrôles d'unicité avant une modification : l'IPP d'un patient supprimé ne peut pas être
     * réattribué (la base le refuserait).
     */
    public function ippOwnerId(string $ipp): ?int
    {
        $stmt = $this->pdo->prepare('SELECT idPatient FROM Patient WHERE ipp = :ipp');
        $stmt->execute(['ipp' => $ipp]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Verrouille la ligne du patient jusqu'à la fin de la transaction en cours (SELECT … FOR UPDATE).
     * L'admission d'un patient connu et sa suppression prennent ce verrou toutes les deux : elles ne peuvent
     * donc pas s'entrelacer (patient supprimé avec un séjour en cours). À appeler dans une transaction.
     */
    public function lockForUpdate(int $idPatient): void
    {
        $this->pdo->prepare('SELECT idPatient FROM Patient WHERE idPatient = :id FOR UPDATE')
            ->execute(['id' => $idPatient]);
    }

    /**
     * AURA-12.3 : suppression logique (README, « Conventions ») ; la date vient de l'horloge de la base, comme
     * CarePlan.recordedAt (l'arrêt d'une ligne et la fin de séjour prennent, eux, l'heure de PHP).
     * Renvoie false si le patient n'existe pas ou est déjà supprimé : la condition
     * est dans la requête, deux suppressions simultanées ne comptent qu'une fois. Les règles (séjour en
     * cours, soin réalisé) sont vérifiées avant, par PatientDeletionService.
     */
    public function markRemoved(int $idPatient): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE Patient SET removedAt = CURRENT_TIMESTAMP WHERE idPatient = :idPatient AND ' . self::ACTIVE
        );
        $stmt->execute(['idPatient' => (string) $idPatient]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Insère le patient et renvoie son identifiant.
     *
     * Le schéma actuel n'a pas d'AUTO_INCREMENT sur idPatient : l'id est donc
     * calculé ici (plus grand id + 1), dans une transaction qui verrouille la
     * table pour éviter que deux inscriptions simultanées obtiennent le même id.
     * Si une transaction est déjà ouverte (inscription + séjour), on s'y rattache.
     * L'IPP n'est pas calculé ici : l'appelant le fournit ou le prend dans nextIpp().
     *
     * @throws \PDOException IPP déjà pris (UNIQUE) ou hors de la plage du CHECK
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
                'lastName' => $p->getLastName(),
                'firstName' => $p->getFirstName(),
                'birthDate' => $p->getBirthDate()?->format('Y-m-d'),
                'sex' => $p->getSex(),
                'phoneNumber' => $p->getPhone(),
                'email' => $p->getEmail(),
            ] + $details);

            return $id;
        });
    }

    /**
     * Met à jour toutes les colonnes du patient (AURA-12.2), champs complémentaires compris :
     * l'appelant doit donc transmettre les détails existants s'il ne veut pas les effacer.
     * L'id n'est jamais modifié ; une seule instruction SQL, donc pas de transaction ici.
     * Ni removedAt ni l'historique (AuditEvent) ne sont touchés : la traçabilité est le rôle du service.
     *
     * @throws LogicException si le patient n'a pas d'id (jamais enregistré)
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
            'lastName' => $p->getLastName(),
            'firstName' => $p->getFirstName(),
            'birthDate' => $p->getBirthDate()?->format('Y-m-d'),
            'sex' => $p->getSex(),
            'phoneNumber' => $p->getPhone(),
            'email' => $p->getEmail(),
        ] + $details);
    }

    /**
     * Met à jour seulement les champs complémentaires fournis (clés de RecordFields::PATIENT ;
     * toute autre clé est ignorée). Sert à compléter un patient déjà connu lors d'une
     * nouvelle admission sans toucher à son identité. Aucun contrôle des champs réservés au
     * médecin (décès, groupe sanguin) ni trace : AdmissionService ne l'appelle que sans
     * RecordEditService (tests) ; sinon c'est RecordEditService qui écrit et trace.
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

    /**
     * Met à jour UN champ seulement si sa valeur en base est encore celle attendue (contrôle de
     * concurrence optimiste, atomique : la comparaison est faite par l'UPDATE lui-même, <=> traite
     * NULL). Renvoie false si la ligne n'existe pas ou si la valeur a changé entre-temps.
     * Le nom de colonne doit être une clé de RecordFields::PATIENT (liste blanche). Un patient
     * supprimé reste modifiable ici : c'est à l'appelant de le lire d'abord (findById()).
     *
     * @throws LogicException si la colonne n'est pas dans la liste blanche
     */
    public function updateDetailIfUnchanged(int $id, string $column, ?string $new, ?string $expected): bool
    {
        if (!isset((RecordFields::PATIENT)[$column])) {
            throw new LogicException('Colonne non modifiable : ' . $column);
        }
        $stmt = $this->pdo->prepare(
            'UPDATE Patient SET ' . $column . ' = :new WHERE idPatient = :id AND ' . $column . ' <=> :expected'
        );
        $stmt->execute(['new' => $new, 'id' => (string) $id, 'expected' => $expected]);

        return $stmt->rowCount() === 1;
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

    /**
     * Ligne lue vers l'entité. Seuls les champs complémentaires présents dans la ligne sont repris
     * (lecture partielle de findAll() et search() : « deceased » seulement) ; l'IPP INT devient une chaîne.
     *
     * @param array<string, mixed> $r
     */
    private function mapToEntity(array $r): Patient
    {
        return new Patient(
            idPatient: (int) $r['idPatient'],
            ipp: $r['ipp'] !== null ? (string) $r['ipp'] : null,
            lastName: $r['lastName'],
            firstName: $r['firstName'],
            birthDate: $r['birthDate'] !== null ? new DateTimeImmutable($r['birthDate']) : null,
            sex: $r['sex'],
            phone: $r['phoneNumber'],
            email: $r['email'],
            details: array_map(
                static fn ($v): ?string => $v === null ? null : (string) $v,
                array_intersect_key($r, RecordFields::PATIENT)
            )
        );
    }
}
