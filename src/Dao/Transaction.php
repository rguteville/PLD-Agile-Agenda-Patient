<?php

declare(strict_types=1);

namespace Aura\Dao;

use PDO;
use PDOException;

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

        for ($attempt = 1;; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $result = $treatment();
                $this->pdo->commit();

                return $result;
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                // Relecture adversariale du 2026-11-04 : un interblocage (1213) ou une ligne modifiée depuis sa
                // lecture (1020, isolation par instantané de MariaDB 11.8) vient d'un autre onglet qui écrivait
                // en même temps. Tout a été annulé : on rejoue le traitement, au plus MAX_ATTEMPTS fois.
                $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
                if (!in_array($code, self::RETRYABLE_ERRORS, true) || $attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                usleep(20_000 * $attempt);
            }
        }
    }

    /** Codes MariaDB d'un conflit entre transactions concurrentes : interblocage, ligne modifiée depuis sa lecture. */
    private const RETRYABLE_ERRORS = [1213, 1020];
    private const MAX_ATTEMPTS = 3;
}
