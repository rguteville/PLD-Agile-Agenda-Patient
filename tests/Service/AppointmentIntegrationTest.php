<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AppointmentDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\Transaction;
use Aura\Service\AppointmentService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-26 / AURA-28 sur base réelle (données de démo, transaction annulée en tearDown) : un soin
 * validé passe REALISE avec son heure, l'auteur est tracé, et une seconde validation est refusée.
 */
final class AppointmentIntegrationTest extends TestCase
{
    private PDO $pdo;
    private AppointmentService $service;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }
        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->pdo->exec(
            "INSERT INTO Appointment (idAppointment, idServiceRequest, idMedicationRequest, plannedTime, status)
             VALUES ('ZZ_SOI', NULL, '1', '2026-10-14 08:00:00', 'PLANIFIE')"
        );
        $this->service = new AppointmentService(
            new AppointmentDao($this->pdo),
            new AuditEventDao($this->pdo),
            new Transaction($this->pdo),
            ['PRA04' => 'Zzsoignant Test (IDE)', 'PRA06' => 'Zzaide Test (AS)'],
            ['PRA04' => 'Infirmier(e) diplômé(e) d\'État', 'PRA06' => 'Aide-soignant(e)']
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testValidatedCareIsDoneTracedAndCannotBeValidatedTwice(): void
    {
        $input = [
            'action' => 'done', 'idPerformer' => 'PRA04', 'password' => 'x',
            'completedAt' => '2026-10-14T08:10', 'comment' => 'Zz note',
        ];
        $now = new DateTimeImmutable('2026-10-14 08:20:00');

        $result = $this->service->apply(1, 'ZZ_SOI', $input, $now);
        self::assertSame(AppointmentService::STATUS_SAVED, $result['status']);

        $row = $this->pdo
            ->query("SELECT status, timeOfCompletion, comment FROM Appointment WHERE idAppointment = 'ZZ_SOI'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame('REALISE', $row['status']);
        self::assertSame('2026-10-14 08:10:00', $row['timeOfCompletion']);
        self::assertSame('Zz note', $row['comment']);
        self::assertSame(
            ['ZZ_SOI' => 'Zzsoignant Test (IDE), authentification factice'],
            (new AuditEventDao($this->pdo))->appointmentAuthors(['ZZ_SOI'])
        );

        $again = $this->service->apply(1, 'ZZ_SOI', $input, $now);
        self::assertSame(AppointmentService::STATUS_CONFLICT, $again['status'], 'déjà validé : rien n\'est réécrit');
    }

    public function testCareOfAnotherEpisodeIsNotFound(): void
    {
        $result = $this->service->apply(
            2,
            'ZZ_SOI',
            ['action' => 'note', 'idPerformer' => 'PRA04', 'password' => 'x', 'comment' => 'Zz'],
            new DateTimeImmutable('2026-10-14 08:20:00')
        );

        self::assertSame(AppointmentService::STATUS_NOT_FOUND, $result['status']);
    }

    public function testNoteIsSavedOnAnyStatusAndTraced(): void
    {
        $result = $this->service->apply(
            1,
            'ZZ_SOI',
            ['action' => 'note', 'idPerformer' => 'PRA04', 'password' => 'x', 'comment' => 'Zz à jeun'],
            new DateTimeImmutable('2026-10-14 07:00:00')
        );

        self::assertSame(AppointmentService::STATUS_SAVED, $result['status']);
        $row = $this->pdo->query("SELECT status, comment FROM Appointment WHERE idAppointment = 'ZZ_SOI'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['status' => 'PLANIFIE', 'comment' => 'Zz à jeun'], $row);
    }

    public function testCareAssistantCannotValidateAMedication(): void
    {
        $result = $this->service->apply(
            1,
            'ZZ_SOI',
            ['action' => 'done', 'idPerformer' => 'PRA06', 'password' => 'x', 'completedAt' => '2026-10-14T08:10'],
            new DateTimeImmutable('2026-10-14 08:20:00')
        );

        self::assertSame(AppointmentService::STATUS_INVALID, $result['status']);
        self::assertArrayHasKey('auth', $result['errors']);
    }

    public function testOnlyTheAuthorOfANoteCanChangeIt(): void
    {
        $note = static fn (string $who, string $text): array => [
            'action' => 'note', 'idPerformer' => $who, 'password' => 'x', 'comment' => $text,
        ];
        $now = new DateTimeImmutable('2026-10-14 07:00:00');

        self::assertSame('saved', $this->service->apply(1, 'ZZ_SOI', $note('PRA04', 'Zz v1'), $now)['status']);
        self::assertSame('saved', $this->service->apply(1, 'ZZ_SOI', $note('PRA04', 'Zz v2'), $now)['status']);
        $other = $this->service->apply(1, 'ZZ_SOI', $note('PRA06', 'Zz v3'), $now);
        self::assertSame('invalid', $other['status'], 'note signée : un autre soignant ne la réécrit pas');
        self::assertArrayHasKey('comment', $other['errors']);
    }

    public function testCareCannotBeValidatedHoursAhead(): void
    {
        $result = $this->service->apply(
            1,
            'ZZ_SOI',
            ['action' => 'done', 'idPerformer' => 'PRA04', 'password' => 'x', 'completedAt' => '2026-10-14T05:00'],
            new DateTimeImmutable('2026-10-14 05:00:00')
        );

        self::assertSame(AppointmentService::STATUS_INVALID, $result['status'], 'prévu à 8 h, validé à 5 h');
        self::assertArrayHasKey('completedAt', $result['errors']);
    }

    public function testStoppedLineAcceptsOnlyCareDoneBeforeTheStop(): void
    {
        $this->pdo->exec(
            "UPDATE MedicationRequest SET stoppedAt = '2026-10-14 08:30:00', idStoppedBy = 'PRA01'
             WHERE idMedicationRequest = '1'"
        );
        $done = static fn (string $at): array => [
            'action' => 'done', 'idPerformer' => 'PRA04', 'password' => 'x', 'completedAt' => $at,
        ];
        $now = new DateTimeImmutable('2026-10-14 09:00:00');

        $after = $this->service->apply(1, 'ZZ_SOI', $done('2026-10-14T08:45'), $now);
        self::assertSame(AppointmentService::STATUS_INVALID, $after['status'], 'fait après l\'arrêt : refusé');

        $before = $this->service->apply(1, 'ZZ_SOI', $done('2026-10-14T08:05'), $now);
        self::assertSame(AppointmentService::STATUS_SAVED, $before['status'], 'fait avant l\'arrêt : tracé');
    }
}
