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
     * @template T
     * @param callable(): T $traitement
     * @return T
     */
    public function run(callable $traitement): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $traitement();
        }

        $this->pdo->beginTransaction();
        try {
            $resultat = $traitement();
            $this->pdo->commit();

            return $resultat;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
