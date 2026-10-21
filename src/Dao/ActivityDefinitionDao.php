<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\ActivityDefinition;
use PDO;

/**
 * Accès aux séjours : table EpisodeOfCare (noms HL7 depuis AURA-16, cf. database/schema.sql) ;
 * les entités du domaine restent en français.
 */
class ActivityDefinitionDao
{
    private const COLUMNS = 'idActivityDefinition, name, description';

    public function __construct(private PDO $pdo)
    {
    }

    public function findById(string $id): ?ActivityDefinition
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM ActivityDefinition WHERE idActivityDefinition = :id');
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    public function mapToEntity(array $r): ?ActivityDefinition
    {
        return new ActivityDefinition(
            idActivityDefinition: $r['idActivityDefinition'],
            name: $r['name'],
            description: $r['description']
        );
    }
}
