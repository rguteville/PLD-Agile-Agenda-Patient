<?php

declare(strict_types=1);

namespace Aura\Dao;

use PDO;

/**
 * Exécute un traitement dans une transaction : tout est validé, ou tout est annulé.
 * Si une transaction est déjà ouverte, le traitement s'y rattache (pas d'imbrication).
 */
class Transaction
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Lance $treatment dans une transaction et renvoie son résultat. Toute exception annule la
     * transaction (rollBack) puis est relancée telle quelle. Dans une transaction déjà ouverte,
     * l'appelant extérieur garde la main sur la validation et l'annulation.
     *
     * @template T
     * @param callable(): T $treatment
     * @return T
     * @throws \Throwable l'exception levée par $treatment, après annulation
     */
    public function run(callable $treatment): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $treatment();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $treatment();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
