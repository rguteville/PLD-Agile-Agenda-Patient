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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Règle de suppression d'un patient (RG10, traçabilité) et enchaînement de la suppression
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

    public function testPatientIsMarkedRemovedAndTheDeletionTraced(): void
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

    public function testPatientInAStayCannotBeDeleted(): void
    {
        $this->patients->method('findById')->willReturn($this->patient());
        $this->episodes->method('findByPatient')->willReturn([$this->episode(discharged: false)]);
        $this->patients->expects($this->never())->method('markRemoved');
        $this->auditEvents->expects($this->never())->method('insert');

        self::assertSame(PatientDeletionService::REFUSED_ONGOING_EPISODE, $this->service->delete(4));
    }

    public function testPatientWithCompletedCareCannotBeDeleted(): void
    {
        $this->patients->method('findById')->willReturn($this->patient());
        $this->episodes->method('findByPatient')->willReturn([$this->episode(discharged: true)]);
        $this->appointments->method('countByPatientAndStatus')->willReturn(2);
        $this->patients->expects($this->never())->method('markRemoved');
        $this->auditEvents->expects($this->never())->method('insert');

        self::assertSame(PatientDeletionService::REFUSED_COMPLETED_CARE, $this->service->delete(4));
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
