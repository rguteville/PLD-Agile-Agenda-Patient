<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Model\Service;
use PDO;

class ServiceDao
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return Service[] */
    public function findAll(): array
    {
        $rows = $this->pdo->query('SELECT id_service, nom FROM service ORDER BY nom')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $r) => $this->mapToEntity($r), $rows);
    }

    public function findById(int $id): ?Service
    {
        $stmt = $this->pdo->prepare('SELECT id_service, nom FROM service WHERE id_service = :id');
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    public function insert(Service $s): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO service (nom) VALUES (:nom)');
        $stmt->execute(['nom' => $s->getNom()]);
        return (int) $this->pdo->lastInsertId();
    }

    private function mapToEntity(array $r): Service
    {
        return new Service(idService: (int) $r['id_service'], nom: $r['nom']);
    }
}