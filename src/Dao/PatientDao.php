<?php
declare(strict_types=1);

namespace Aura\Dao;

use Aura\Model\Patient;
use PDO;

class PatientDao
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return Patient[] */
    public function findAll(): array
    {
        $rows = $this->pdo->query('SELECT id, name, email FROM patients')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn(array $r) => new Patient((int) $r['id'], $r['name'], $r['email']),
            $rows
        );
    }

    public function findById(int $id): ?Patient
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email FROM patients WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? new Patient((int) $r['id'], $r['name'], $r['email']) : null;
    }
}
