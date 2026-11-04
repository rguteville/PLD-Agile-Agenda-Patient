<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AppointmentDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\Appointment;
use Aura\Domain\AuditEvent;
use Aura\Domain\EpisodeOfCare;
use Aura\Domain\Patient;
use Aura\Service\PatientDeletionService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AURA-12.3 : règle de suppression d'un patient (RG10, traçabilité) et enchaînement de la suppression
 * logique, sans base (DAO simulés).
 */
final class PatientDeletionServiceTest extends TestCase
{
    private PatientDao&MockObject $patients;
    private EpisodeOfCareDao&MockObject $episodes;
    private AppointmentDao&MockObject $appointments;
    private AuditEventDao&MockObject $auditEvents;
    private PatientDeletionService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->episodes = $this->createMock(EpisodeOfCareDao::class);
        $this->appointments = $this->createMock(AppointmentDao::class);
        $this->auditEvents = $this->createMock(AuditEventDao::class);

        // La transaction exécute simplement le traitement : pas de base ici.
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('run')->willReturnCallback(fn (callable $treatment) => $treatment());

        $this->service = new PatientDeletionService(
            $this->patients,
            $this->episodes,
            $this->appointments,
            $this->auditEvents,
            $transaction
        );
    }

    /**
     * @return iterable<string, array{0: bool, 1: int, 2: ?string}>
     */
    public static function ruleProvider(): iterable
    {
        yield 'aucun séjour en cours, aucun soin réalisé : suppression possible' => [false, 0, null];
        yield 'séjour en cours' => [true, 0, PatientDeletionService::REFUSED_ONGOING_EPISODE];
        yield 'un soin réalisé dans un séjour clos' => [false, 1, PatientDeletionService::REFUSED_COMPLETED_CARE];
        yield 'plusieurs soins réalisés' => [false, 12, PatientDeletionService::REFUSED_COMPLETED_CARE];
        yield 'séjour en cours et soins réalisés : le séjour en cours d\'abord' =>
            [true, 3, PatientDeletionService::REFUSED_ONGOING_EPISODE];
    }

    #[DataProvider('ruleProvider')]
    public function testRefusalReason(bool $ongoing, int $completedCare, ?string $expected): void
    {
        self::assertSame($expected, PatientDeletionService::refusalReason($ongoing, $completedCare));
    }

    public function testRefusalMessagesAreExplicit(): void
    {
        self::assertStringContainsString('séjour en cours', PatientDeletionService::REFUSED_ONGOING_EPISODE);
        self::assertStringContainsString('soin a été réalisé', PatientDeletionService::REFUSED_COMPLETED_CARE);
    }

    public function testDeletableLoggedAndMarkedRemoved(): void
    {
        $this->patients->method('findById')->with(4)->willReturn($this->patient());
        $this->episodes->method('findByPatient')->with(4)->willReturn([$this->episode(discharged: true)]);
        $this->appointments->method('countByPatientAndStatus')
            ->with(4, Appointment::STATUS_DONE)
            ->willReturn(0);
        $this->patients->expects($this->once())->method('markRemoved')->with(4)->willReturn(true);
        $this->auditEvents->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (AuditEvent $e): bool {
                $this->assertSame(AuditEvent::ENTITY_PATIENT, $e->getEntity());
                $this->assertSame(4, $e->getEntityId());
                $this->assertSame('removedAt', $e->getField());
                $this->assertNull($e->getOldValue());
                $this->assertSame(PatientDeletionService::AUDIT_VALUE, $e->getNewValue());
                $this->assertSame(AuditEvent::AUTHOR_UNKNOWN, $e->getAuthor());
                $this->assertStringContainsString('PARKER Peter (IPP 100000004)', (string) $e->getDetail());

                return true;
            }));

        self::assertNull($this->service->delete(4));
    }

    public function testOngoingEpisodeRefusedWithoutWriting(): void
    {
        $this->patients->method('findById')->willReturn($this->patient());
        $this->episodes->method('findByPatient')->willReturn([$this->episode(discharged: false)]);
        $this->patients->expects($this->never())->method('markRemoved');
        $this->auditEvents->expects($this->never())->method('insert');

        self::assertSame(PatientDeletionService::REFUSED_ONGOING_EPISODE, $this->service->delete(4));
    }

    public function testCompletedCareRefusedWithoutWriting(): void
    {
        $this->patients->method('findById')->willReturn($this->patient());
        $this->episodes->method('findByPatient')->willReturn([$this->episode(discharged: true)]);
        $this->appointments->method('countByPatientAndStatus')->willReturn(2);
        $this->patients->expects($this->never())->method('markRemoved');
        $this->auditEvents->expects($this->never())->method('insert');

        self::assertSame(PatientDeletionService::REFUSED_COMPLETED_CARE, $this->service->delete(4));
    }

    public function testUnknownOrAlreadyRemovedPatientRejected(): void
    {
        $this->patients->method('findById')->willReturn(null);
        $this->patients->expects($this->never())->method('markRemoved');

        $this->expectException(InvalidArgumentException::class);
        $this->service->delete(999);
    }

    public function testConfirmationPageShowsIdentityAndRefusal(): void
    {
        $this->patients->method('findById')->willReturn($this->patient());
        $this->episodes->method('findByPatient')
            ->willReturn([$this->episode(discharged: true), $this->episode(discharged: false)]);

        $page = $this->service->prepare(4);

        self::assertNotNull($page);
        self::assertSame('Parker', $page['identity']['lastName']);
        self::assertSame('100000004', $page['identity']['ipp']);
        self::assertSame('09/01/2001', $page['identity']['birthDate']);
        self::assertSame(2, $page['episodes']);
        self::assertSame(PatientDeletionService::REFUSED_ONGOING_EPISODE, $page['refusal']);
    }

    public function testConfirmationPageNullForUnknownPatient(): void
    {
        $this->patients->method('findById')->willReturn(null);

        self::assertNull($this->service->prepare(999));
    }

    private function patient(): Patient
    {
        return new Patient(4, '100000004', 'Parker', 'Peter', new DateTimeImmutable('2001-01-09'), 'M');
    }

    private function episode(bool $discharged): EpisodeOfCare
    {
        return new EpisodeOfCare(
            1,
            4,
            '2026-000104',
            new DateTimeImmutable('2026-10-01 03:20'),
            $discharged ? new DateTimeImmutable('2026-10-08 11:00') : null
        );
    }
}
