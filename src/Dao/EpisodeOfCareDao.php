<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\RecordFields;
use Aura\Domain\EpisodeOfCare;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use PDO;
use PDOException;

/**
 * Accès aux séjours : table EpisodeOfCare, ressource HL7 du même nom (cf. database/schema.sql).
 * Seule traduction : la colonne idHealthcareService devient idService dans l'entité.
 *
 * Id VARCHAR(20) et numéro de séjour AAAA-NNNNNN calculés par insert() (MAX + 1, FOR UPDATE, RG1).
 * Un séjour n'est jamais supprimé : il se termine par sa date de sortie (updateDischarge(), fin de
 * séjour ou décès). Les lectures joignent le médecin référent (Practitioner) pour son nom.
 */
class EpisodeOfCareDao
{
    /** Colonnes lues, renommées vers les clés utilisées par mapToEntity() (sans les champs complémentaires). */
    private const BASE_COLUMNS =
        'idEpisodeOfCare, idPatient, episodeNumber, admissionDate, dischargeDate,
         bedNumber, boxNumber, idHealthcareService AS idService, weight, height, precautions,
         idReferringPractitioner, referrer.firstName AS referrerFirstName, referrer.lastName AS referrerLastName';

    /**
     * Séjour + nom de son médecin référent (retours du 2026-11-03), pour l'afficher sans autre requête.
     * Practitioner n'a aucune colonne homonyme de EpisodeOfCare : les colonnes non préfixées restent univoques.
     */
    private const FROM =
        'EpisodeOfCare LEFT JOIN Practitioner referrer
         ON referrer.idPractitioner = EpisodeOfCare.idReferringPractitioner';

    /** Colonnes écrites par insert(), dans l'ordre des paramètres (sans les champs complémentaires). */
    private const BASE_INSERT_COLUMNS =
        'idEpisodeOfCare, idPatient, episodeNumber, admissionDate, dischargeDate, bedNumber, boxNumber,
         idHealthcareService, weight, height, precautions, idReferringPractitioner';

    /** Nombre d'essais d'insertion si un autre séjour prend le même id ou numéro entre-temps. */
    private const INSERT_ATTEMPTS = 3;

    /** Code MariaDB d'une violation de clé unique (« Duplicate entry »). */
    private const DUPLICATE_ERROR = 1062;

    public function __construct(private PDO $pdo)
    {
    }

    /** Colonnes lues : les colonnes de base + les champs complémentaires (RecordFields::EPISODE). */
    private static function columns(): string
    {
        return self::BASE_COLUMNS . ', ' . implode(', ', RecordFields::episodeColumns());
    }

    /** Séjour par identifiant interne, champs complémentaires compris ; null s'il n'existe pas. */
    public function findById(int $id): ?EpisodeOfCare
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::columns() . ' FROM ' . self::FROM . ' WHERE idEpisodeOfCare = :id'
        );
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    /** Séjour par son numéro (AAAA-NNNNNN, cf. insert()) ; null s'il n'existe pas. */
    public function findByNumber(string $number): ?EpisodeOfCare
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::columns() . ' FROM ' . self::FROM . ' WHERE episodeNumber = :number'
        );
        $stmt->execute(['number' => $number]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    /**
     * Tous les séjours d'un patient, tous services confondus, du plus récent au plus ancien.
     *
     * @return EpisodeOfCare[]
     */
    public function findByPatient(int $idPatient): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::columns() . ' FROM ' . self::FROM . '
             WHERE idPatient = :idPatient
             ORDER BY admissionDate DESC, CAST(idEpisodeOfCare AS UNSIGNED) DESC'
        );
        $stmt->execute(['idPatient' => $idPatient]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Séjours de plusieurs patients en une requête (liste des patients : séjour en cours, dernier séjour et
     * historique de chacun), du plus récent au plus ancien comme findByPatient(). [] si aucun patient.
     *
     * @param list<int> $idPatients
     * @return EpisodeOfCare[]
     */
    public function findByPatients(array $idPatients): array
    {
        $ids = array_values(array_unique(array_map(static fn ($id): string => (string) (int) $id, $idPatients)));
        if ($ids === []) {
            return [];
        }

        // Un marqueur positionnel par identifiant : les valeurs restent des paramètres préparés.
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::columns() . ' FROM ' . self::FROM . '
             WHERE idPatient IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')
             ORDER BY admissionDate DESC, CAST(idEpisodeOfCare AS UNSIGNED) DESC'
        );
        $stmt->execute($ids);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Séjours en cours (dischargeDate NULL), tous services confondus, du plus ancien au plus récent.
     *
     * @return EpisodeOfCare[]
     */
    public function findOngoing(): array
    {
        $rows = $this->pdo->query(
            'SELECT ' . self::columns() . ' FROM ' . self::FROM . '
             WHERE dischargeDate IS NULL
             ORDER BY admissionDate, CAST(idEpisodeOfCare AS UNSIGNED)'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->mapToEntity($r), $rows);
    }

    /**
     * Séjours EN COURS d'un service hospitalier (dischargeDate NULL), du plus récent au plus ancien :
     * les patients présents, base de l'agenda du service (AgendaService::getAgendaService()).
     *
     * @return EpisodeOfCare[]
     */
    public function findByHealthcareService(string $idService): array
    {
         $stmt = $this->pdo->prepare(
             'SELECT ' . self::columns() . ' FROM ' . self::FROM . '
             WHERE idHealthcareService = :idHealthcareService
             AND dischargeDate IS NULL
             ORDER BY admissionDate DESC, CAST(idEpisodeOfCare AS UNSIGNED) DESC'
         );
        $stmt->execute(['idHealthcareService' => $idService]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Enregistre la date de sortie, ce qui termine le séjour (fin de séjour ou décès). Aucun contrôle
     * ici : EpisodeOfCareService::closeEpisodeOfCare() vérifie que le séjour est en cours.
     */
    public function updateDischarge(int $idEpisodeOfCare, DateTimeInterface $dischargeDate): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE EpisodeOfCare SET dischargeDate = :dischargeDate WHERE idEpisodeOfCare = :id'
        );
        $stmt->execute(['dischargeDate' => $dischargeDate->format('Y-m-d H:i:s'), 'id' => $idEpisodeOfCare]);
    }

    /** Change le lit et le box d'un séjour (null = non attribué). */
    public function updateLocation(int $idEpisodeOfCare, ?string $bedNumber, ?string $boxNumber): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE EpisodeOfCare SET bedNumber = :bedNumber, boxNumber = :boxNumber WHERE idEpisodeOfCare = :id'
        );
        $stmt->execute(['bedNumber' => $bedNumber, 'boxNumber' => $boxNumber, 'id' => $idEpisodeOfCare]);
    }

    /**
     * Crée un séjour et le renvoie complété (id et numéro).
     *
     * Le numéro de séjour est toujours généré ici, jamais saisi (RG1) : un numéro porté par
     * l'entité reçue est ignoré. Format AAAA-NNNNNN : année de la date d'admission, tiret,
     * compteur sur 6 chiffres qui repart à 000001 chaque année (ex. 2026-000108).
     * Cf. database/schema.sql et le README.
     *
     * Comme pour Patient, idEpisodeOfCare n'a pas d'AUTO_INCREMENT : l'id est calculé ici. La lecture
     * des maxima est verrouillée (FOR UPDATE) ; si un doublon passe malgré tout, la contrainte
     * UNIQUE le refuse et l'insertion est retentée avec des valeurs recalculées (INSERT_ATTEMPTS fois).
     *
     * @throws PDOException toute autre erreur SQL, ou un doublon encore présent au dernier essai
     */
    public function insert(EpisodeOfCare $s): EpisodeOfCare
    {
        return (new Transaction($this->pdo))->run(function () use ($s): EpisodeOfCare {
            $year = $s->getAdmissionDate()->format('Y');

            for ($attempt = 1;; $attempt++) {
                $stmt = $this->pdo->prepare(
                    'SELECT COALESCE(MAX(CAST(idEpisodeOfCare AS UNSIGNED)), 0) + 1 AS id,
                            COALESCE(MAX(CASE WHEN episodeNumber LIKE :prefix
                                         THEN CAST(SUBSTRING(episodeNumber, 6) AS UNSIGNED) END), 0) + 1 AS counter
                     FROM EpisodeOfCare FOR UPDATE'
                );
                $stmt->execute(['prefix' => $year . '-%']);
                $next = $stmt->fetch(PDO::FETCH_ASSOC);
                $id = (int) $next['id'];
                $episodeNumber = sprintf('%s-%06d', $year, (int) $next['counter']);

                $details = self::detailValues($s);
                try {
                    $this->pdo->prepare(
                        'INSERT INTO EpisodeOfCare (' . self::BASE_INSERT_COLUMNS . ', '
                        . implode(', ', array_keys($details)) . ')
                         VALUES (:idEpisodeOfCare, :idPatient, :episodeNumber, :admissionDate,
                                 :dischargeDate, :bedNumber, :boxNumber, :idService,
                                 :weight, :height, :precautions, :idReferringPractitioner, '
                        . implode(', ', array_map(static fn (string $c): string => ':' . $c, array_keys($details)))
                        . ')'
                    )->execute([
                        'idEpisodeOfCare' => (string) $id,
                        'idPatient' => (string) $s->getIdPatient(),
                        'episodeNumber' => $episodeNumber,
                        'admissionDate' => $s->getAdmissionDate()->format('Y-m-d H:i:s'),
                        'dischargeDate' => $s->getDischargeDate()?->format('Y-m-d H:i:s'),
                        'bedNumber' => $s->getBedNumber(),
                        'boxNumber' => $s->getBoxNumber(),
                        'idService' => $s->getIdService(),
                        'weight' => $s->getWeight(),
                        'height' => $s->getHeight(),
                        'precautions' => $s->getPrecautions(),
                        'idReferringPractitioner' => $s->getIdReferringPractitioner(),
                    ] + $details);
                    break;
                } catch (PDOException $e) {
                    if (($e->errorInfo[1] ?? null) !== self::DUPLICATE_ERROR || $attempt >= self::INSERT_ATTEMPTS) {
                        throw $e;
                    }
                }
            }

            return new EpisodeOfCare(
                idEpisodeOfCare: $id,
                idPatient: $s->getIdPatient(),
                episodeNumber: $episodeNumber,
                admissionDate: $s->getAdmissionDate(),
                dischargeDate: $s->getDischargeDate(),
                bedNumber: $s->getBedNumber(),
                boxNumber: $s->getBoxNumber(),
                idService: $s->getIdService(),
                weight: $s->getWeight(),
                height: $s->getHeight(),
                precautions: $s->getPrecautions(),
                details: $s->getDetails(),
                idReferringPractitioner: $s->getIdReferringPractitioner(),
                referringPractitionerName: $s->getReferringPractitionerName()
            );
        });
    }

    /**
     * Colonnes de base modifiables champ par champ depuis le dossier (RecordEditService), en plus
     * du catalogue RecordFields::EPISODE : mesures d'admission, précautions, service, médecin référent.
     */
    public const EDITABLE_BASE_COLUMNS = [
        'weight', 'height', 'precautions', 'idHealthcareService', 'idReferringPractitioner',
    ];

    /**
     * Met à jour seulement les champs fournis : clés de RecordFields::EPISODE ou colonnes de
     * EDITABLE_BASE_COLUMNS ; toute autre clé est ignorée (liste blanche).
     *
     * @param array<string, ?string> $details
     */
    public function updateDetails(int $idEpisodeOfCare, array $details): void
    {
        $details = array_intersect_key(
            $details,
            RecordFields::EPISODE + array_fill_keys(self::EDITABLE_BASE_COLUMNS, true)
        );
        if ($details === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE EpisodeOfCare SET '
            . implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($details)))
            . ' WHERE idEpisodeOfCare = :id'
        );
        $stmt->execute(['id' => (string) $idEpisodeOfCare] + $details);
    }

    /**
     * Met à jour UN champ seulement si sa valeur en base est encore celle attendue (contrôle de
     * concurrence optimiste, atomique : la comparaison est faite par l'UPDATE lui-même, <=> traite
     * NULL). Renvoie false si la ligne n'existe pas ou si la valeur a changé entre-temps.
     * Le nom de colonne doit appartenir à la liste blanche (RecordFields::EPISODE ou
     * EDITABLE_BASE_COLUMNS).
     *
     * @throws LogicException si la colonne n'est pas dans la liste blanche
     */
    public function updateDetailIfUnchanged(int $id, string $column, ?string $new, ?string $expected): bool
    {
        if (!isset((RecordFields::EPISODE + array_fill_keys(self::EDITABLE_BASE_COLUMNS, true))[$column])) {
            throw new LogicException('Colonne non modifiable : ' . $column);
        }
        $stmt = $this->pdo->prepare(
            'UPDATE EpisodeOfCare SET ' . $column . ' = :new
             WHERE idEpisodeOfCare = :id AND ' . $column . ' <=> :expected'
        );
        $stmt->execute(['new' => $new, 'id' => (string) $id, 'expected' => $expected]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Valeurs des champs complémentaires à écrire, une par colonne du catalogue (null si absente).
     *
     * @return array<string, ?string>
     */
    private static function detailValues(EpisodeOfCare $s): array
    {
        $values = [];
        foreach (RecordFields::episodeColumns() as $column) {
            $values[$column] = $s->getDetail($column);
        }

        return $values;
    }

    /**
     * Ligne lue (colonnes de columns(), avec la jointure du référent) vers l'entité. Les champs
     * complémentaires sont repris tels quels en texte ; poids et taille deviennent des float.
     *
     * @param array<string, mixed> $r
     */
    private function mapToEntity(array $r): EpisodeOfCare
    {
        return new EpisodeOfCare(
            idEpisodeOfCare: (int) $r['idEpisodeOfCare'],
            idPatient: (int) $r['idPatient'],
            episodeNumber: (string) $r['episodeNumber'],
            admissionDate: new DateTimeImmutable($r['admissionDate']),
            dischargeDate: $r['dischargeDate'] !== null ? new DateTimeImmutable($r['dischargeDate']) : null,
            bedNumber: $r['bedNumber'],
            boxNumber: $r['boxNumber'],
            idService: $r['idService'],
            // DECIMAL arrive en chaîne ("62.50") depuis PDO MySQL.
            weight: $r['weight'] !== null ? (float) $r['weight'] : null,
            height: $r['height'] !== null ? (float) $r['height'] : null,
            precautions: $r['precautions'],
            details: array_map(
                static fn ($v): ?string => $v === null ? null : (string) $v,
                array_intersect_key($r, RecordFields::EPISODE)
            ),
            idReferringPractitioner: $r['idReferringPractitioner'] !== null
                ? (string) $r['idReferringPractitioner']
                : null,
            referringPractitionerName: self::referrerName($r)
        );
    }

    /** « Prénom Nom » du référent joint ; l'identifiant si le praticien n'a pas de nom, null sans référent. */
    private static function referrerName(array $r): ?string
    {
        if ($r['idReferringPractitioner'] === null) {
            return null;
        }
        $name = trim($r['referrerFirstName'] . ' ' . $r['referrerLastName']);

        return $name !== '' ? $name : (string) $r['idReferringPractitioner'];
    }
}
