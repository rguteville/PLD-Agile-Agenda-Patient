<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\PlanDefinitionDao;
use Aura\Domain\PlanDefinition;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Protocoles de démonstration (database/test.sql) et contraintes de PlanDefinitionAction.
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
 */
final class PlanDefinitionDaoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM PlanDefinition')->fetchColumn() === 0) {
            $this->markTestSkipped('Aucun protocole en base : database/test.sql n\'est pas chargé.');
        }
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testSeulsLesProtocolesActifsSontProposesAvecLeurPathologie(): void
    {
        $active = (new PlanDefinitionDao($this->pdo))->findActive();

        $ids = array_map(static fn(PlanDefinition $p) => $p->getIdPlanDefinition(), $active);
        self::assertContains('PLD01', $ids);
        self::assertContains('PLD02', $ids);
        self::assertNotContains('PLD03', $ids, 'un protocole retiré n\'est pas proposé');

        foreach ($active as $planDefinition) {
            self::assertTrue($planDefinition->isActive());
            self::assertSame([], $planDefinition->getActions(), 'la liste ne lit pas les lignes');
        }
        $septicShock = $active[array_search('PLD01', $ids, true)];
        self::assertSame('Sepsis et choc septique', $septicShock->getPathology());
    }

    public function testProtocoleChargeAvecSesLignesParDecalage(): void
    {
        $planDefinition = (new PlanDefinitionDao($this->pdo))->findById('PLD01');

        self::assertNotNull($planDefinition);
        self::assertSame('Choc septique : prise en charge initiale', $planDefinition->getTitle());
        $actions = $planDefinition->getActions();
        self::assertCount(6, $actions);

        $offsets = array_map(static fn($a) => $a->getStartOffsetHours(), $actions);
        $sorted = $offsets;
        sort($sorted);
        self::assertSame($sorted, $offsets, 'lignes triées par décalage de début');

        $antibiotic = null;
        foreach ($actions as $action) {
            if ($action->getIdPlanDefinitionAction() === 'PDA04') {
                $antibiotic = $action;
            }
        }
        self::assertNotNull($antibiotic);
        self::assertTrue($antibiotic->isMedication());
        self::assertSame('MED06', $antibiotic->getIdMedication());
        self::assertSame(1, $antibiotic->getStartOffsetHours());
        self::assertSame(168, $antibiotic->getDurationHours());
        self::assertSame(8, $antibiotic->getFrequencyHours());
    }

    public function testProtocoleInconnu(): void
    {
        self::assertNull((new PlanDefinitionDao($this->pdo))->findById('PLD99'));
    }

    public function testUnMedicamentSansDoseEstRefuseParLaBase(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->exec(
            "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition,
                                               idMedication, route)
             VALUES ('T_PDA', 'PLD01', 'MEDICATION', 'TYP01', 'MED01', 'IV')"
        );
    }

    public function testUneLigneUniqueNAPasDeDuree(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->exec(
            "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition,
                                               durationHours)
             VALUES ('T_PDA', 'PLD01', 'CARE', 'TYP02', 24)"
        );
    }
}
