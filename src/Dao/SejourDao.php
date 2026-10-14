<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Sejour;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;

class SejourDao
{
    private const COLUMNS =
        'id_sejour, id_patient, num_sejour, date_admission, date_sortie, num_lit, num_box, id_service';

    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?Sejour
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM Sejour WHERE id_sejour = :id');
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
             WHERE id_patient = :id_patient
             ORDER BY date_admission DESC, CAST(id_sejour AS UNSIGNED) DESC'
        );
        $stmt->execute(['id_patient' => $idPatient]);

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
             WHERE date_sortie IS NULL
             ORDER BY date_admission, CAST(id_sejour AS UNSIGNED)'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->mapToEntity($r), $rows);
    }

    public function updateSortie(int $idSejour, DateTimeInterface $dateSortie): void
    {
        $stmt = $this->pdo->prepare('UPDATE Sejour SET date_sortie = :date_sortie WHERE id_sejour = :id');
        $stmt->execute(['date_sortie' => $dateSortie->format('Y-m-d H:i:s'), 'id' => $idSejour]);
    }

    public function updateEmplacement(int $idSejour, ?string $numLit, ?string $numBox): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE Sejour SET num_lit = :num_lit, num_box = :num_box WHERE id_sejour = :id'
        );
        $stmt->execute(['num_lit' => $numLit, 'num_box' => $numBox, 'id' => $idSejour]);
    }

    /**
     * Crée un séjour et le renvoie complété (id et numéro). Le numéro de séjour (9 chiffres)
     * est généré à partir de l'identifiant.
     *
     * Comme pour Patient, id_sejour n'a pas d'AUTO_INCREMENT : l'id est calculé ici.
     */
    public function insert(Sejour $s): Sejour
    {
        return (new Transaction($this->pdo))->run(function () use ($s): Sejour {
            $id = (int) $this->pdo
                ->query('SELECT COALESCE(MAX(CAST(id_sejour AS UNSIGNED)), 0) + 1 FROM Sejour FOR UPDATE')
                ->fetchColumn();
            $numSejour = $s->getNumSejour() !== ''
                ? $s->getNumSejour()
                : str_pad((string) $id, 9, '0', STR_PAD_LEFT);

            $stmt = $this->pdo->prepare(
                'INSERT INTO Sejour (' . self::COLUMNS . ')
                 VALUES (:id_sejour, :id_patient, :num_sejour, :date_admission,
                         :date_sortie, :num_lit, :num_box, :id_service)'
            );
            $stmt->execute([
                'id_sejour' => (string) $id,
                'id_patient' => (string) $s->getIdPatient(),
                'num_sejour' => $numSejour,
                'date_admission' => $s->getDateAdmission()->format('Y-m-d H:i:s'),
                'date_sortie' => $s->getDateSortie()?->format('Y-m-d H:i:s'),
                'num_lit' => $s->getNumLit(),
                'num_box' => $s->getNumBox(),
                'id_service' => $s->getIdService(),
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
            idSejour: (int) $r['id_sejour'],
            idPatient: (int) $r['id_patient'],
            numSejour: (string) $r['num_sejour'],
            dateAdmission: new DateTimeImmutable($r['date_admission']),
            dateSortie: $r['date_sortie'] !== null ? new DateTimeImmutable($r['date_sortie']) : null,
            numLit: $r['num_lit'],
            numBox: $r['num_box'],
            idService: $r['id_service']
        );
    }
}
