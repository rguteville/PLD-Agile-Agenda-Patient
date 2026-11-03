<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\Transaction;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Transaction::run() sur la base du CI : validation, annulation avec exception relancée, et
 * rattachement à une transaction déjà ouverte (pas d'imbrication).
 *
 * Le test de validation écrit réellement (c'est ce qu'il vérifie) : il passe par la table de journal
 * AuditEvent, entité « ZZTX » qu'aucun écran ne lit, et la nettoie en tearDown. Une seconde connexion
 * confirme que la ligne est visible hors de la session qui l'a écrite. Ignoré sans DB_HOST/DB_NAME.
 */
final class TransactionTest extends TestCase
{
    private const ENTITY = 'ZZTX';

    private PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo->exec("DELETE FROM AuditEvent WHERE entity = '" . self::ENTITY . "'");
    }

    private function insertEvent(string $entityId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO AuditEvent (entity, entityId, field, author, recordedAt)
             VALUES (:entity, :entityId, 'zzField', 'Zz test', '2030-01-01 00:00:00')"
        );
        $stmt->execute(['entity' => self::ENTITY, 'entityId' => $entityId]);
    }

    private static function countEvents(PDO $pdo, string $entityId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM AuditEvent WHERE entity = :entity AND entityId = :entityId');
        $stmt->execute(['entity' => self::ENTITY, 'entityId' => $entityId]);

        return (int) $stmt->fetchColumn();
    }

    /** Seconde connexion, indépendante de Database::get(), pour voir ce qui est réellement validé. */
    private static function otherConnection(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST'),
                (int) (getenv('DB_PORT') ?: 3306),
                getenv('DB_NAME')
            ),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public function testRunCommitsAndReturnsTheTreatmentResult(): void
    {
        $result = (new Transaction($this->pdo))->run(function (): string {
            self::assertTrue($this->pdo->inTransaction(), 'le traitement s\'exécute dans une transaction');
            $this->insertEvent('commit');

            return 'résultat';
        });

        self::assertSame('résultat', $result);
        self::assertFalse($this->pdo->inTransaction(), 'transaction refermée');
        self::assertSame(
            1,
            self::countEvents(self::otherConnection(), 'commit'),
            'ligne visible d\'une autre connexion'
        );
    }

    public function testRunRollsBackAndRethrowsTheSameException(): void
    {
        $thrown = new RuntimeException('Zz échec métier');

        try {
            (new Transaction($this->pdo))->run(function () use ($thrown): void {
                $this->insertEvent('rollback');
                throw $thrown;
            });
            self::fail('L\'exception du traitement devait être relancée.');
        } catch (RuntimeException $e) {
            self::assertSame($thrown, $e, 'même instance, pas une exception enveloppée');
        }

        self::assertFalse($this->pdo->inTransaction(), 'transaction refermée après l\'échec');
        self::assertSame(0, self::countEvents($this->pdo, 'rollback'), 'écriture annulée');
    }

    public function testRunAlsoRollsBackOnAnError(): void
    {
        // \Throwable, pas seulement \Exception : une TypeError dans un service annule aussi l'écriture.
        try {
            (new Transaction($this->pdo))->run(function (): void {
                $this->insertEvent('error');
                strlen([]);
            });
            self::fail('La TypeError devait être relancée.');
        } catch (\TypeError) {
            self::assertFalse($this->pdo->inTransaction());
        }

        self::assertSame(0, self::countEvents($this->pdo, 'error'));
    }

    public function testNestedRunJoinsTheOpenTransactionWithoutCommitting(): void
    {
        $this->pdo->beginTransaction();
        $transaction = new Transaction($this->pdo);

        $result = $transaction->run(function () use ($transaction): int {
            $this->insertEvent('outer');

            return $transaction->run(function (): int {
                $this->insertEvent('inner');

                return 7;
            });
        });

        self::assertSame(7, $result);
        self::assertTrue($this->pdo->inTransaction(), 'la transaction de l\'appelant reste ouverte');
        self::assertSame(0, self::countEvents(self::otherConnection(), 'inner'), 'rien de validé avant l\'appelant');

        $this->pdo->rollBack();

        self::assertSame(0, self::countEvents($this->pdo, 'outer'));
        self::assertSame(0, self::countEvents($this->pdo, 'inner'), 'l\'annulation de l\'appelant emporte tout');
    }

    public function testNestedRunFailureLeavesTheDecisionToTheCaller(): void
    {
        $this->pdo->beginTransaction();
        $this->insertEvent('before');

        try {
            (new Transaction($this->pdo))->run(function (): void {
                throw new RuntimeException('Zz échec interne');
            });
            self::fail('L\'exception devait remonter.');
        } catch (RuntimeException) {
            self::assertTrue(
                $this->pdo->inTransaction(),
                'pas de rollBack sur une transaction qui n\'est pas la sienne'
            );
        }

        self::assertSame(1, self::countEvents($this->pdo, 'before'), 'les écritures de l\'appelant sont intactes');
    }
}
