<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\ActivityDefinitionDao;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Type de soin par identifiant (table ActivityDefinition, RG6) sur la base du CI : données de
 * database/test.sql, cas limites insérés dans une transaction annulée en tearDown.
 * Ignoré sans DB_HOST/DB_NAME.
 */
final class ActivityDefinitionDaoTest extends TestCase
{
    private PDO $pdo;
    private ActivityDefinitionDao $dao;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->dao = new ActivityDefinitionDao($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testFindByIdReturnsNameAndDescription(): void
    {
        $activity = $this->dao->findById('TYP02');

        self::assertNotNull($activity);
        self::assertSame('TYP02', $activity->getIdActivityDefinition());
        self::assertSame('Prise des constantes', $activity->getName());
        self::assertSame('Pression artérielle, fréquence cardiaque, SpO2, température', $activity->getDescription());
    }

    public function testFindByIdKeepsAMissingDescriptionAsNull(): void
    {
        $this->pdo->exec(
            "INSERT INTO ActivityDefinition (idActivityDefinition, name, description) VALUES ('ZZTYP', 'Zzsoin', NULL)"
        );

        $activity = $this->dao->findById('ZZTYP');

        self::assertNotNull($activity);
        self::assertSame('Zzsoin', $activity->getName());
        self::assertNull($activity->getDescription());
    }

    public function testUnknownActivityDefinitionIsNull(): void
    {
        self::assertNull($this->dao->findById('ZZ_INCONNU'));
        self::assertNull($this->dao->findById(''));
    }

    public function testMapToEntityBuildsTheEntityFromARow(): void
    {
        $activity = $this->dao->mapToEntity(['idActivityDefinition' => 'ZZ1', 'name' => 'Zz', 'description' => 'Zzd']);

        self::assertSame('ZZ1', $activity->getIdActivityDefinition());
        self::assertSame('Zz', $activity->getName());
        self::assertSame('Zzd', $activity->getDescription());
    }
}
