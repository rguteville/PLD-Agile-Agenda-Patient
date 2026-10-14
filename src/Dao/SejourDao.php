<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Sejour;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;

/**
 * Accès à la table Sejour. Les colonnes suivent database/schema.sql (camelCase anglais,
 * cf. README « Convention de nommage ») ; les entités du domaine restent en français.
 */
class SejourDao
{
    private const COLUMNS =
        'idSejour, idPatient, sejourNumber, admissionDate, dischargeDate, bedNumber, boxNumber, idService';

    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?Sejour
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM Sejour WHERE idSejour = :id');
        $stmt->execute(['id' => $id]);
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
            'SELECT ' . self::COLUMNS . ' FROM Sejour
             WHERE idPatient = :idPatient
             ORDER BY admissionDate DESC, CAST(idSejour AS UNSIGNED) DESC'
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
            'SELECT ' . self::COLUMNS . ' FROM Sejour
             WHERE dischargeDate IS NULL
             ORDER BY admissionDate, CAST(idSejour AS UNSIGNED)'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->mapToEntity($r), $rows);
    }

    public function updateSortie(int $idSejour, DateTimeInterface $dateSortie): void
    {
        $stmt = $this->pdo->prepare('UPDATE Sejour SET dischargeDate = :dischargeDate WHERE idSejour = :id');
        $stmt->execute(['dischargeDate' => $dateSortie->format('Y-m-d H:i:s'), 'id' => $idSejour]);
    }

    public function updateEmplacement(int $idSejour, ?string $numLit, ?string $numBox): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE Sejour SET bedNumber = :bedNumber, boxNumber = :boxNumber WHERE idSejour = :id'
        );
        $stmt->execute(['bedNumber' => $numLit, 'boxNumber' => $numBox, 'id' => $idSejour]);
    }

    /**
     * Crée un séjour et le renvoie complété (id et numéro). Le numéro de séjour (9 chiffres)
     * est généré à partir de l'identifiant.
     *
     * Comme pour Patient, idSejour n'a pas d'AUTO_INCREMENT : l'id est calculé ici.
     */
    public function insert(Sejour $s): Sejour
    {
        return (new Transaction($this->pdo))->run(function () use ($s): Sejour {
            $id = (int) $this->pdo
                ->query('SELECT COALESCE(MAX(CAST(idSejour AS UNSIGNED)), 0) + 1 FROM Sejour FOR UPDATE')
                ->fetchColumn();
            $numSejour = $s->getNumSejour() !== ''
                ? $s->getNumSejour()
                : str_pad((string) $id, 9, '0', STR_PAD_LEFT);

            $stmt = $this->pdo->prepare(
                'INSERT INTO Sejour (' . self::COLUMNS . ')
                 VALUES (:idSejour, :idPatient, :sejourNumber, :admissionDate,
                         :dischargeDate, :bedNumber, :boxNumber, :idService)'
            );
            $stmt->execute([
                'idSejour' => (string) $id,
                'idPatient' => (string) $s->getIdPatient(),
                'sejourNumber' => $numSejour,
                'admissionDate' => $s->getDateAdmission()->format('Y-m-d H:i:s'),
                'dischargeDate' => $s->getDateSortie()?->format('Y-m-d H:i:s'),
                'bedNumber' => $s->getNumLit(),
                'boxNumber' => $s->getNumBox(),
                'idService' => $s->getIdService(),
            ]);

            return new Sejour(
                idSejour: $id,
                idPatient: $s->getIdPatient(),
                numSejour: $numSejour,
                dateAdmission: $s->getDateAdmission(),
                dateSortie: $s->getDateSortie(),
                numLit: $s->getNumLit(),
                numBox: $s->getNumBox(),
                idService: $s->getIdService()
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
            idService: $r['idService']
        );
    }
}
