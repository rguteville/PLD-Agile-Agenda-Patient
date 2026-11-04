<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\CareTeamDao;
use Aura\Dao\Transaction;
use Aura\Domain\CareTeam;
use Aura\Service\CareTeamService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-30 sur base réelle (transaction annulée en tearDown) : composition d'une équipe, lecture par
 * rotation et par soignant, un patient dans une seule équipe par rotation, retrait logique.
 */
final class CareTeamDaoTest extends TestCase
{
    private PDO $pdo;
    private CareTeamDao $dao;
    private DateTimeImmutable $date;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }
        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->dao = new CareTeamDao($this->pdo);
        // Vacation sans équipe dans les données de démo.
        $this->date = new DateTimeImmutable('2030-01-02');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testComposedTeamIsReadBackByShiftAndByPractitioner(): void
    {
        $service = new CareTeamService($this->dao, new Transaction($this->pdo));
        $result = $service->create(
            'SRV01',
            $this->date,
            CareTeam::SHIFT_NIGHT,
            ['name' => 'Zz équipe', 'members' => ['PRA05'], 'patients' => ['2', '8']],
            ['PRA05' => 'Inès Rousseau (IDE)'],
            [1 => 'A', 2 => 'B', 8 => 'C']
        );
        self::assertSame(CareTeamService::STATUS_SAVED, $result['status']);

        $teams = $this->dao->findForShift('SRV01', $this->date, CareTeam::SHIFT_NIGHT);
        self::assertCount(1, $teams);
        self::assertSame('Zz équipe', $teams[0]->getName());
        self::assertSame(['PRA05' => 'Inès Rousseau'], $teams[0]->getMembers());
        self::assertSame([2, 8], $teams[0]->getEpisodeIds());
        self::assertSame([], $this->dao->findForShift('SRV01', $this->date, CareTeam::SHIFT_MORNING), 'autre rotation');
        self::assertCount(1, $this->dao->findForPractitioner('PRA05', $this->date, CareTeam::SHIFT_NIGHT));
        self::assertSame([], $this->dao->findForPractitioner('PRA04', $this->date, CareTeam::SHIFT_NIGHT));

        $again = $service->create(
            'SRV01',
            $this->date,
            CareTeam::SHIFT_NIGHT,
            ['name' => 'Zz autre', 'members' => ['PRA05'], 'patients' => ['8']],
            ['PRA05' => 'Inès Rousseau (IDE)'],
            [1 => 'A', 2 => 'B', 8 => 'C']
        );
        self::assertSame(CareTeamService::STATUS_INVALID, $again['status'], 'un patient, une équipe par rotation');

        self::assertTrue($this->dao->remove($teams[0]->getIdCareTeam(), new DateTimeImmutable('2030-01-02 21:00')));
        self::assertSame([], $this->dao->findForShift('SRV01', $this->date, CareTeam::SHIFT_NIGHT), 'retrait logique');
        self::assertFalse($this->dao->remove($teams[0]->getIdCareTeam(), new DateTimeImmutable()), 'déjà retirée');
    }
}
