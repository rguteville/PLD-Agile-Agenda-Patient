<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\ActivityDefinition;
use PDO;

/**
 * Référentiel des types de soin (RG6) : table ActivityDefinition, ressource HL7 du même nom.
 * Lecture seule : le référentiel est chargé par database/test.sql, jamais saisi dans AURA.
 * La liste complète pour les formulaires est dans ReferenceDataDao::activityDefinitions().
 */
class ActivityDefinitionDao
{
    private const COLUMNS = 'idActivityDefinition, name, description';

    public function __construct(private PDO $pdo)
    {
    }

    /** Type de soin par identifiant (VARCHAR(20)) ; null s'il n'existe pas. */
    public function findById(string $id): ?ActivityDefinition
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ActivityDefinition WHERE idActivityDefinition = :id'
        );
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    /**
     * Ligne SQL (clés = noms de colonnes) vers l'entité. Ne renvoie jamais null malgré le type
     * déclaré ; name et description, eux, peuvent l'être (colonnes facultatives).
     *
     * @param array<string, ?string> $r
     */
    public function mapToEntity(array $r): ?ActivityDefinition
    {
        return new ActivityDefinition(
            idActivityDefinition: $r['idActivityDefinition'],
            name: $r['name'],
            description: $r['description']
        );
    }
}
