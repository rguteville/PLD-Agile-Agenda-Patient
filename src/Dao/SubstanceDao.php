<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Substance;
use PDO;

/**
 * Référentiel des allergènes : table Substance (cf. database/schema.sql). Lecture seule :
 * le référentiel est chargé par database/test.sql, jamais saisi dans l'application.
 */
class SubstanceDao
{
    private const COLUMNS = 'idSubstance, description';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Tous les allergènes par ordre alphabétique de libellé (liste déroulante d'ajout).
     *
     * @return Substance[]
     */
    public function findAll(): array
    {
        $rows = $this->pdo
            ->query('SELECT ' . self::COLUMNS . ' FROM Substance ORDER BY description')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->mapToEntity($r), $rows);
    }

    /** null si l'identifiant ne figure pas dans le référentiel. */
    public function findById(string $idSubstance): ?Substance
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM Substance WHERE idSubstance = :id');
        $stmt->execute(['id' => $idSubstance]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    private function mapToEntity(array $r): Substance
    {
        return new Substance((string) $r['idSubstance'], (string) $r['description']);
    }
}
