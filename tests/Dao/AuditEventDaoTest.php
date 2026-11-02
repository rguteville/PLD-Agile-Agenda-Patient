<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\AuditEventDao;
use Aura\Domain\AuditEvent;
use PDO;
use PHPUnit\Framework\TestCase;

/** Journal AuditEvent sur la base du CI : insertion, lecture d'un dossier (patient + séjour), ordre et limite. */
final class AuditEventDaoTest extends TestCase
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
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testInsertionPuisLectureDuDossierDuPlusRecentAuPlusAncienAvecLimite(): void
    {
        $dao = new AuditEventDao($this->pdo);
        $id1 = $dao->insert(new AuditEvent(null, 'Patient', 999901, 'medicalHistory', null, 'HTA', 'non identifié'));
        $id2 = $dao->insert(new AuditEvent(null, 'EpisodeOfCare', 999902, 'asaScore', 'II', 'III', 'non identifié'));
        $id3 = $dao->insert(new AuditEvent(null, 'Patient', 999903, 'lifestyle', null, 'autre dossier', 'x'));
        $id4 = $dao->insert(new AuditEvent(
            null,
            'EpisodeOfCare',
            999902,
            'idPatient',
            null,
            '999901',
            'non identifié',
            detail: 'rattachement après recherche par nom'
        ));
        self::assertGreaterThan($id1, $id2);
        self::assertGreaterThan($id2, $id3);

        $events = $dao->findForRecord(999901, 999902);

        self::assertSame([$id4, $id2, $id1], array_map(static fn (AuditEvent $e) => $e->getIdAuditEvent(), $events));
        self::assertSame('rattachement après recherche par nom', $events[0]->getDetail());
        self::assertNotNull($events[0]->getRecordedAt());
        self::assertSame('HTA', $events[2]->getNewValue());
        self::assertNull($events[2]->getOldValue());

        self::assertCount(2, $dao->findForRecord(999901, 999902, 2), 'limite respectée');
        self::assertSame([], $dao->findForRecord(999904, 999905), 'autre dossier : rien');
    }
}
