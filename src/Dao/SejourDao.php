<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Sejour;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;
use PDOException;

/**
 * Accès aux séjours : table EpisodeOfCare (noms HL7 depuis AURA-16, cf. database/schema.sql) ;
 * les entités du domaine restent en français.
 */
class SejourDao
{
    /** Colonnes lues, renommées vers les clés utilisées par mapToEntity(). */
    private const COLUMNS =
        'idEpisodeOfCare AS idSejour, idPatient, episodeNumber AS sejourNumber, admissionDate, dischargeDate,
         bedNumber, boxNumber, idHealthcareService AS idService, weight, height, precautions';

    /** Colonnes écrites par insert(), dans l'ordre des paramètres. */
    private const INSERT_COLUMNS =
        'idEpisodeOfCare, idPatient, episodeNumber, admissionDate, dischargeDate, bedNumber, boxNumber,
         idHealthcareService, weight, height, precautions';

    /** Nombre d'essais d'insertion si un autre séjour prend le même id ou numéro entre-temps. */
    private const ESSAIS_INSERTION = 3;

    /** Code MariaDB d'une violation de clé unique (« Duplicate entry »). */
    private const ERREUR_DOUBLON = 1062;

    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?Sejour
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM EpisodeOfCare WHERE idEpisodeOfCare = :id');
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    public function findByNumber(string $number): ?Sejour
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM EpisodeOfCare WHERE episodeNumber = :number');
        $stmt->execute(['number' => $number]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    /**
     * Séjours d'un patient dans le service, du plus récent au plus ancien.
     *
     * @return Sejour[]
     */
    public function findByPatient(int $idPatient): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM EpisodeOfCare
             WHERE idPatient = :idPatient
             ORDER BY admissionDate DESC, CAST(idEpisodeOfCare AS UNSIGNED) DESC'
        );
        $stmt->execute(['idPatient' => $idPatient]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Séjours en cours (patients présents dans le service), du plus ancien au plus récent.
     *
     * @return Sejour[]
     */
    public function findEnCours(): array
    {
        $rows = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM EpisodeOfCare
             WHERE dischargeDate IS NULL
             ORDER BY admissionDate, CAST(idEpisodeOfCare AS UNSIGNED)'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->mapToEntity($r), $rows);
    }

    public function updateSortie(int $idSejour, DateTimeInterface $dateSortie): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE EpisodeOfCare SET dischargeDate = :dischargeDate WHERE idEpisodeOfCare = :id'
        );
        $stmt->execute(['dischargeDate' => $dateSortie->format('Y-m-d H:i:s'), 'id' => $idSejour]);
    }

    public function updateEmplacement(int $idSejour, ?string $numLit, ?string $numBox): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE EpisodeOfCare SET bedNumber = :bedNumber, boxNumber = :boxNumber WHERE idEpisodeOfCare = :id'
        );
        $stmt->execute(['bedNumber' => $numLit, 'boxNumber' => $numBox, 'id' => $idSejour]);
    }

    /**
     * Crée un séjour et le renvoie complété (id et numéro).
     *
     * Le numéro de séjour est toujours généré ici, jamais saisi (RG1) : un numéro porté par
     * l'entité reçue est ignoré. Format AAAA-NNNNNN : année de la date d'admission, tiret,
     * compteur sur 6 chiffres qui repart à 000001 chaque année (ex. 2026-000108).
     * Cf. database/schema.sql et le README.
     *
     * Comme pour Patient, idSejour n'a pas d'AUTO_INCREMENT : l'id est calculé ici. La lecture
     * des maxima est verrouillée (FOR UPDATE) ; si un doublon passe malgré tout, la contrainte
     * UNIQUE le refuse et l'insertion est retentée avec des valeurs recalculées.
     */
    public function insert(Sejour $s): Sejour
    {
        return (new Transaction($this->pdo))->run(function () use ($s): Sejour {
            $annee = $s->getDateAdmission()->format('Y');

            for ($essai = 1;; $essai++) {
                $stmt = $this->pdo->prepare(
                    'SELECT COALESCE(MAX(CAST(idEpisodeOfCare AS UNSIGNED)), 0) + 1 AS id,
                            COALESCE(MAX(CASE WHEN episodeNumber LIKE :prefixe
                                         THEN CAST(SUBSTRING(episodeNumber, 6) AS UNSIGNED) END), 0) + 1 AS compteur
                     FROM EpisodeOfCare FOR UPDATE'
                );
                $stmt->execute(['prefixe' => $annee . '-%']);
                $suivant = $stmt->fetch(PDO::FETCH_ASSOC);
                $id = (int) $suivant['id'];
                $numSejour = sprintf('%s-%06d', $annee, (int) $suivant['compteur']);

                try {
                    $this->pdo->prepare(
                        'INSERT INTO EpisodeOfCare (' . self::INSERT_COLUMNS . ')
                         VALUES (:idSejour, :idPatient, :sejourNumber, :admissionDate,
                                 :dischargeDate, :bedNumber, :boxNumber, :idService,
                                 :weight, :height, :precautions)'
                    )->execute([
                        'idSejour' => (string) $id,
                        'idPatient' => (string) $s->getIdPatient(),
                        'sejourNumber' => $numSejour,
                        'admissionDate' => $s->getDateAdmission()->format('Y-m-d H:i:s'),
                        'dischargeDate' => $s->getDateSortie()?->format('Y-m-d H:i:s'),
                        'bedNumber' => $s->getNumLit(),
                        'boxNumber' => $s->getNumBox(),
                        'idService' => $s->getIdService(),
                        'weight' => $s->getPoids(),
                        'height' => $s->getTaille(),
                        'precautions' => $s->getPrecautions(),
                    ]);
                    break;
                } catch (PDOException $e) {
                    if (($e->errorInfo[1] ?? null) !== self::ERREUR_DOUBLON || $essai >= self::ESSAIS_INSERTION) {
                        throw $e;
                    }
                }
            }

            return new Sejour(
                idSejour: $id,
                idPatient: $s->getIdPatient(),
                numSejour: $numSejour,
                dateAdmission: $s->getDateAdmission(),
                dateSortie: $s->getDateSortie(),
                numLit: $s->getNumLit(),
                numBox: $s->getNumBox(),
                idService: $s->getIdService(),
                poids: $s->getPoids(),
                taille: $s->getTaille(),
                precautions: $s->getPrecautions()
            );
        });
    }

    private function mapToEntity(array $r): Sejour
    {
        return new Sejour(
            idSejour: (int) $r['idSejour'],
            idPatient: (int) $r['idPatient'],
            numSejour: (string) $r['sejourNumber'],
            dateAdmission: new DateTimeImmutable($r['admissionDate']),
            dateSortie: $r['dischargeDate'] !== null ? new DateTimeImmutable($r['dischargeDate']) : null,
            numLit: $r['bedNumber'],
            numBox: $r['boxNumber'],
            idService: $r['idService'],
            // DECIMAL arrive en chaîne ("62.50") depuis PDO MySQL.
            poids: $r['weight'] !== null ? (float) $r['weight'] : null,
            taille: $r['height'] !== null ? (float) $r['height'] : null,
            precautions: $r['precautions']
        );
    }
}
