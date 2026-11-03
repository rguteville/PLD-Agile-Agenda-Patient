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

    public function testFieldOutsideWhitelistIsRejectedWithoutReadingDatabase(): void
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
    public function testDeceasedIsRefusedWithoutIdentifiedDoctor(): void
    {
        $this->patients->expects($this->never())->method('findById');
        $this->patients->expects($this->never())->method('updateDetailIfUnchanged');
        $this->audit->expects($this->never())->method('insert');

        $r = $this->service->update('patient', 9, 'deceased', '1', '0');

        self::assertSame(RecordEditService::STATUS_FORBIDDEN_FIELD, $r['status']);
        self::assertStringContainsString('médecin', (string) $r['message']);
    }

    public function testDeceasedIsWrittenAndTracedForIdentifiedDoctor(): void
    {
        $this->patients->method('findById')->with(9)->willReturn($this->patient(['deceased' => '0']));
        $this->patients->expects($this->once())->method('updateDetailIfUnchanged')
            ->with(9, 'deceased', '1', '0')->willReturn(true);
        $this->audit->expects($this->once())->method('insert')->willReturn(1);

        $r = $this->service->update('patient', 9, 'deceased', '1', '0', 'Claire Morel (médecin)');

        self::assertSame(RecordEditService::STATUS_SAVED, $r['status']);
        self::assertSame('Oui', $r['display']);
        $invalid = $this->service->update('patient', 9, 'deceased', 'peut-être', '0', 'Claire Morel (médecin)');
        self::assertSame(RecordEditService::STATUS_INVALID, $invalid['status'], 'seulement Oui / Non');
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

    public function testNonexistentRecord(): void
    {
        $this->patients->method('findById')->willReturn(null);

        $r = $this->service->update('patient', 404, 'medicalHistory', 'HTA', null);

        self::assertSame(RecordEditService::STATUS_NOT_FOUND, $r['status']);
    }

    public function testSuccessWritesFieldAndHistoryLine(): void
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

    public function testInvalidValueRejectedWithFieldMessage(): void
    {
        $this->episodesOfCare->method('findById')->with(9)->willReturn($this->episodeOfCare(['asaScore' => 'III']));
        $this->episodesOfCare->expects($this->never())->method('updateDetailIfUnchanged');
        $this->audit->expects($this->never())->method('insert');

        $r = $this->service->update('episode', 9, 'asaScore', 'IX', 'III');

        self::assertSame(RecordEditService::STATUS_INVALID, $r['status']);
        self::assertStringContainsString('échelle', (string) $r['message']);
        self::assertSame('III', $r['value'], 'la valeur actuelle est renvoyée');
    }

    public function testConcurrencyConflictReturnsCurrentValue(): void
    {
        $this->patients->method('findById')->willReturn($this->patient(['medicalHistory' => 'HTA, diabète']));
        $this->patients->expects($this->never())->method('updateDetailIfUnchanged');

        $r = $this->service->update('patient', 9, 'medicalHistory', 'HTA, asthme', 'HTA');

        self::assertSame(RecordEditService::STATUS_CONFLICT, $r['status']);
        self::assertSame('HTA, diabète', $r['value']);
        self::assertStringContainsString('entre-temps', (string) $r['message']);
    }

    public function testUnchangedValueWritesNothing(): void
    {
        $this->episodesOfCare->method('findById')->willReturn($this->episodeOfCare([], weight: 62.5));
        $this->episodesOfCare->expects($this->never())->method('updateDetailIfUnchanged');
        $this->audit->expects($this->never())->method('insert');

        $r = $this->service->update('episode', 9, 'weight', '62.50', '62.5');

        self::assertSame(RecordEditService::STATUS_UNCHANGED, $r['status']);
        self::assertSame('62.5', $r['display']);
    }

    public function testBaseEpisodeFieldsAreEditableAndRoundedLikeAdmission(): void
    {
        $this->episodesOfCare->method('findById')->willReturn($this->episodeOfCare([], weight: 62.5));
        $this->episodesOfCare->expects($this->once())->method('updateDetailIfUnchanged')
            ->with(9, 'weight', '63.46', '62.5')->willReturn(true);
        $this->audit->expects($this->once())->method('insert')->willReturn(1);

        $r = $this->service->update('episode', 9, 'weight', '63.456', '62.5');

        self::assertSame(RecordEditService::STATUS_SAVED, $r['status']);
        self::assertSame('63.46', $r['value']);
    }

    public function testHealthcareServiceIsCheckedAgainstReferenceList(): void
    {
        $this->episodesOfCare->method('findById')->willReturn($this->episodeOfCare([]));

        $rejected = $this->service->update('episode', 9, 'idHealthcareService', 'SRV99', 'REA');
        self::assertSame(RecordEditService::STATUS_INVALID, $rejected['status']);

        $this->episodesOfCare->expects($this->once())->method('updateDetailIfUnchanged')
            ->with(9, 'idHealthcareService', 'SRV01', 'REA')->willReturn(true);
        $this->audit->method('insert')->willReturn(1);
        $ok = $this->service->update('episode', 9, 'idHealthcareService', 'SRV01', 'REA');
        self::assertSame(RecordEditService::STATUS_SAVED, $ok['status']);
        self::assertSame('Réanimation médicale', $ok['display'], 'libellé du service, pas son id');
    }

    public function testClearingFieldIsTracedChange(): void
    {
        $this->patients->method('findById')->willReturn($this->patient(['lifestyle' => 'Fumeur']));
        $this->patients->expects($this->once())->method('updateDetailIfUnchanged')
            ->with(9, 'lifestyle', null, 'Fumeur')->willReturn(true);
        $this->audit->expects($this->once())->method('insert')->willReturn(1);

        $r = $this->service->update('patient', 9, 'lifestyle', '   ', 'Fumeur');

        self::assertSame(RecordEditService::STATUS_SAVED, $r['status']);
        self::assertNull($r['value']);
    }

    public function testConflictDetectedByConditionalWriteReturnsRereadValue(): void
    {
        // Lecture : « HTA » ; entre la lecture et l'UPDATE, quelqu'un écrit « HTA, asthme » : l'UPDATE
        // conditionnel ne touche aucune ligne, la valeur est relue et renvoyée en conflit.
        $this->patients->method('findById')->willReturnOnConsecutiveCalls(
            $this->patient(['medicalHistory' => 'HTA']),
            $this->patient(['medicalHistory' => 'HTA, asthme'])
        );
        $this->patients->method('updateDetailIfUnchanged')->willReturn(false);
        $this->audit->expects($this->never())->method('insert');

        $r = $this->service->update('patient', 9, 'medicalHistory', 'HTA, diabète', 'HTA');

        self::assertSame(RecordEditService::STATUS_CONFLICT, $r['status']);
        self::assertSame('HTA, asthme', $r['value']);
    }

    public function testPatientIsEditableOnlyFromOneOfItsEpisodes(): void
    {
        $this->episodesOfCare->method('findById')->with(3)->willReturn($this->episodeOfCare([]));
        $this->patients->expects($this->never())->method('updateDetailIfUnchanged');

        // Le séjour 3 appartient au patient 9 : le patient 4 est refusé comme introuvable.
        $r = $this->service->update('patient', 4, 'medicalHistory', 'x', null, idEpisodeOfCare: 3);

        self::assertSame(RecordEditService::STATUS_NOT_FOUND, $r['status']);
    }

    public function testHistoryTranslatesLabelsAndScales(): void
    {
        $this->audit->method('findForRecord')->with(9, 3, 20)->willReturn([
            new AuditEvent(
                2,
                'EpisodeOfCare',
                3,
                'asaScore',
                'II',
                'III',
                'non identifié',
                new DateTimeImmutable('2026-10-14 08:05')
            ),
            new AuditEvent(1, 'Patient', 9, 'unknown', null, 'x', 'non identifié', new DateTimeImmutable('2026-10-13')),
            new AuditEvent(
                0,
                'EpisodeOfCare',
                3,
                'idPatient',
                null,
                '9',
                'non identifié',
                new DateTimeImmutable('2026-10-13'),
                'rattachement après recherche par nom'
            ),
        ]);

        $rows = $this->service->history(9, 3);

        self::assertSame('Score ASA', $rows[0]['label']);
        self::assertSame('ASA II – maladie systémique légère', $rows[0]['oldValue']);
        self::assertSame('ASA III – maladie systémique sévère', $rows[0]['newValue']);
        self::assertSame('14/10/2026 08:05', $rows[0]['recordedAt']);
        self::assertSame('unknown', $rows[1]['label'], 'un champ hors catalogue garde son nom brut');
        self::assertNull($rows[1]['oldValue']);
        self::assertSame('Rattachement du séjour au patient', $rows[2]['label']);
        self::assertSame('patient n° 9', $rows[2]['newValue']);
        self::assertSame('rattachement après recherche par nom', $rows[2]['detail']);
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
