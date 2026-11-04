<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\MedicationDao;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Médicament par identifiant (table Medication) sur la base du CI : données de database/test.sql,
 * cas limites insérés dans une transaction annulée en tearDown. Ignoré sans DB_HOST/DB_NAME.
 */
final class MedicationDaoTest extends TestCase
{
    private PDO $pdo;
    private MedicationDao $dao;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->dao = new MedicationDao($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testFindByIdReturnsInnAndForm(): void
    {
        $medication = $this->dao->findById('MED01');

        self::assertNotNull($medication);
        self::assertSame('MED01', $medication->getIdMedication());
        self::assertSame('Paracétamol', $medication->getInn());
        self::assertSame('Solution injectable', $medication->getForm());
    }

    public function testFindByIdKeepsAMissingFormAsNull(): void
    {
        $this->pdo->exec("INSERT INTO Medication (idMedication, inn, form) VALUES ('ZZMED1', 'Zzdci', NULL)");

        $medication = $this->dao->findById('ZZMED1');

        self::assertNotNull($medication);
        self::assertSame('Zzdci', $medication->getInn());
        self::assertNull($medication->getForm());
    }

    public function testUnknownMedicationIsNull(): void
    {
        self::assertNull($this->dao->findById('ZZ_INCONNU'));
        self::assertNull($this->dao->findById(''));
    }

    public function testFindByIdIsAnExactMatch(): void
    {
        // Requête préparée et égalité stricte : ni joker LIKE ni injection.
        self::assertNull($this->dao->findById('MED%'));
        self::assertNull($this->dao->findById("MED01' OR '1'='1"));
    }
}
