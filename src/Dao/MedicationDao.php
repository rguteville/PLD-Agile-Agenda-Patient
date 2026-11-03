<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Medication;
use PDO;

class MedicationDao
{
    private const COLUMNS = 'idMedication, inn, form';

    public function __construct(private PDO $pdo)
    {
    }

    public function findById(string $id): ?Medication
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM Medication WHERE idMedication = :id'
        );
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    public function mapToEntity(array $r): ?Medication
    {
        return new Medication(
            idMedication: $r['idMedication'],
            inn: $r['inn'],
            form: $r['form']
        );
    }
}
