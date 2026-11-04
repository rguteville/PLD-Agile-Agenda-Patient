<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AuditEventDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\Transaction;
use Aura\Domain\AuditEvent;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;
use Aura\Service\RecordEditService;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Édition champ par champ du dossier (PR « dossier-patient-edition-inline ») : liste blanche,
 * validation, concurrence optimiste, trace AuditEvent, sans base (DAO doublés).
 */
final class RecordEditServiceTest extends TestCase
{
    private PatientDao&MockObject $patients;
    private EpisodeOfCareDao&MockObject $episodesOfCare;
    private AuditEventDao&MockObject $audit;
    private RecordEditService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->episodesOfCare = $this->createMock(EpisodeOfCareDao::class);
        $this->audit = $this->createMock(AuditEventDao::class);
        $references = $this->createMock(ReferenceDataDao::class);
        $references->method('healthcareServices')->willReturn(['SRV01' => 'Réanimation médicale']);
        // Transaction sans PDO : on exécute simplement le traitement.
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $t): mixed => $t());

        $this->service = new RecordEditService(
            $this->patients,
            $this->episodesOfCare,
            $this->audit,
            $transaction,
            $references,
            new DateTimeImmutable('2026-10-14')
        );
    }

    public function testFieldOutsideTheWhitelistIsRefused(): void
    {
        $this->patients->expects($this->never())->method('findById');
        $this->patients->expects($this->never())->method('updateDetailIfUnchanged');

        foreach (['lastName', 'idPatient', 'ipp', 'medicalHistory; DROP TABLE Patient'] as $field) {
            $r = $this->service->update('patient', 9, $field, 'x', null);
            self::assertSame(RecordEditService::STATUS_FORBIDDEN_FIELD, $r['status'], $field);
        }
        $r = $this->service->update('other', 9, 'medicalHistory', 'x', null);
        self::assertSame(RecordEditService::STATUS_FORBIDDEN_FIELD, $r['status'], 'entité inconnue');
    }

    /** Décès : refusé sans médecin identifié (édition en ligne, admission), sans même lire la base. */
    public function testDeathIsRefusedWithoutAnIdentifiedDoctor(): void
    {
        $this->patients->expects($this->never())->method('findById');
        $this->patients->expects($this->never())->method('updateDetailIfUnchanged');
        $this->audit->expects($this->never())->method('insert');

        $r = $this->service->update('patient', 9, 'deceased', '1', '0');

        self::assertSame(RecordEditService::STATUS_FORBIDDEN_FIELD, $r['status']);
        self::assertStringContainsString('médecin', (string) $r['message']);
    }

    /**
     * Groupe sanguin (relecture du 2026-11-04) : sans médecin identifié, jamais remplacé ni effacé, même par
     * l'admission ; l'admission peut seulement compléter un groupe encore vide.
     */
    public function testBloodTypeIsLockedWithoutDoctorButCanBeCompletedWhenEmpty(): void
    {
        $refused = [
            'édition en ligne' => $this->service->update('patient', 9, 'bloodType', 'O-', 'A+'),
            'admission, remplacement' => $this->service
                ->update('patient', 9, 'bloodType', 'O-', 'A+', completeEmpty: true),
            'admission, effacement' => $this->service->update('patient', 9, 'bloodType', '', 'A+', completeEmpty: true),
            'édition en ligne, groupe vide' => $this->service->update('patient', 9, 'bloodType', 'B+', null),
        ];
        foreach ($refused as $case => $r) {
            self::assertSame(RecordEditService::STATUS_FORBIDDEN_FIELD, $r['status'], $case);
        }

        $this->patients->method('findById')->with(9)->willReturn($this->patient([]));
        $this->patients->expects($this->once())->method('updateDetailIfUnchanged')
            ->with(9, 'bloodType', 'B+', null)->willReturn(true);
        $this->audit->expects($this->once())->method('insert')->willReturn(1);

        $r = $this->service->update('patient', 9, 'bloodType', 'B+', null, completeEmpty: true);
        self::assertSame(RecordEditService::STATUS_SAVED, $r['status'], 'admission : groupe inconnu complété');
    }

    /**
     * Relecture adversariale du 2026-11-04 : depuis le dossier du séjour 9, le séjour d'un autre patient (3) n'est
     * pas modifiable, et le dossier d'un patient supprimé de la liste non plus.
     */
    public function testOnlyTheShownStayOfAnActivePatientIsEditable(): void
    {
        $this->episodesOfCare->method('findById')->with(9)->willReturn($this->episodeOfCare([]));
        $this->episodesOfCare->expects($this->never())->method('updateDetailIfUnchanged');
        $this->patients->method('findById')->willReturnOnConsecutiveCalls($this->patient([]), null);

        $other = $this->service->update('episode', 3, 'precautions', 'x', null, idEpisodeOfCare: 9);
        $removed = $this->service->update('episode', 9, 'precautions', 'x', null, idEpisodeOfCare: 9);

        self::assertSame(RecordEditService::STATUS_NOT_FOUND, $other['status'], 'autre séjour');
        self::assertSame(RecordEditService::STATUS_NOT_FOUND, $removed['status'], 'patient supprimé');
    }

    public function testSavedFieldIsTraced(): void
    {
        $this->patients->method('findById')->with(9)->willReturn($this->patient(['medicalHistory' => 'HTA']));
        $this->patients->expects($this->once())->method('updateDetailIfUnchanged')
            ->with(9, 'medicalHistory', "HTA\nDiabète", 'HTA')->willReturn(true);
        $this->audit->expects($this->once())->method('insert')
            ->with(self::callback(static function (AuditEvent $e): bool {
                return $e->getEntity() === AuditEvent::ENTITY_PATIENT && $e->getEntityId() === 9
                    && $e->getField() === 'medicalHistory' && $e->getOldValue() === 'HTA'
                    && $e->getNewValue() === "HTA\nDiabète" && $e->getAuthor() === 'non identifié';
            }))
            ->willReturn(1);

        $r = $this->service->update('patient', 9, 'medicalHistory', "  HTA\r\nDiabète ", 'HTA');

        self::assertSame(RecordEditService::STATUS_SAVED, $r['status']);
        self::assertSame("HTA\nDiabète", $r['value']);
        self::assertSame("HTA\nDiabète", $r['display']);
        self::assertSame('Enregistré', $r['message']);
    }

    public function testInvalidValueIsRefused(): void
    {
        $this->episodesOfCare->method('findById')->with(9)->willReturn($this->episodeOfCare(['asaScore' => 'III']));
        $this->episodesOfCare->expects($this->never())->method('updateDetailIfUnchanged');
        $this->audit->expects($this->never())->method('insert');

        $r = $this->service->update('episode', 9, 'asaScore', 'IX', 'III');

        self::assertSame(RecordEditService::STATUS_INVALID, $r['status']);
        self::assertStringContainsString('échelle', (string) $r['message']);
        self::assertSame('III', $r['value'], 'la valeur actuelle est renvoyée');
    }

    public function testValueChangedMeanwhileGivesAConflict(): void
    {
        $this->patients->method('findById')->willReturn($this->patient(['medicalHistory' => 'HTA, diabète']));
        $this->patients->expects($this->never())->method('updateDetailIfUnchanged');

        $r = $this->service->update('patient', 9, 'medicalHistory', 'HTA, asthme', 'HTA');

        self::assertSame(RecordEditService::STATUS_CONFLICT, $r['status']);
        self::assertSame('HTA, diabète', $r['value']);
        self::assertStringContainsString('entre-temps', (string) $r['message']);
    }

    /** @param array<string, ?string> $details */
    private function patient(array $details): Patient
    {
        return new Patient(9, null, 'Durand', 'Alice', null, 'F', details: $details);
    }

    /** @param array<string, ?string> $details */
    private function episodeOfCare(array $details, ?float $weight = null): EpisodeOfCare
    {
        return new EpisodeOfCare(
            idEpisodeOfCare: 9,
            idPatient: 9,
            episodeNumber: '2026-000009',
            admissionDate: new DateTimeImmutable('2026-10-13 09:30:00'),
            idService: 'REA',
            weight: $weight,
            details: $details
        );
    }
}
