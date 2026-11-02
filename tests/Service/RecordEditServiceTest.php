<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AuditEventDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\SejourDao;
use Aura\Dao\Transaction;
use Aura\Domain\AuditEvent;
use Aura\Domain\Patient;
use Aura\Domain\Sejour;
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
    private SejourDao&MockObject $sejours;
    private AuditEventDao&MockObject $audit;
    private RecordEditService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->sejours = $this->createMock(SejourDao::class);
        $this->audit = $this->createMock(AuditEventDao::class);
        $references = $this->createMock(ReferenceDataDao::class);
        $references->method('healthcareServices')->willReturn(['SRV01' => 'Réanimation médicale']);
        // Transaction sans PDO : on exécute simplement le traitement.
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $t): mixed => $t());

        $this->service = new RecordEditService(
            $this->patients,
            $this->sejours,
            $this->audit,
            $transaction,
            $references,
            new DateTimeImmutable('2026-10-14')
        );
    }

    public function testChampHorsListeBlancheEstRefuseSansLireLaBase(): void
    {
        $this->patients->expects($this->never())->method('findById');
        $this->patients->expects($this->never())->method('updateDetails');

        foreach (['lastName', 'idPatient', 'ipp', 'medicalHistory; DROP TABLE Patient'] as $field) {
            $r = $this->service->update('patient', 9, $field, 'x', null);
            self::assertSame(RecordEditService::STATUS_FORBIDDEN_FIELD, $r['status'], $field);
        }
        $r = $this->service->update('autre', 9, 'medicalHistory', 'x', null);
        self::assertSame(RecordEditService::STATUS_FORBIDDEN_FIELD, $r['status'], 'entité inconnue');
    }

    public function testDossierInexistant(): void
    {
        $this->patients->method('findById')->willReturn(null);

        $r = $this->service->update('patient', 404, 'medicalHistory', 'HTA', null);

        self::assertSame(RecordEditService::STATUS_NOT_FOUND, $r['status']);
    }

    public function testSuccesEcritLeChampEtUneLigneDHistorique(): void
    {
        $this->patients->method('findById')->with(9)->willReturn($this->patient(['medicalHistory' => 'HTA']));
        $this->patients->expects($this->once())->method('updateDetails')
            ->with(9, ['medicalHistory' => "HTA\nDiabète"]);
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

    public function testValeurInvalideRefuseeAvecLeMessageDuChamp(): void
    {
        $this->sejours->method('findById')->with(9)->willReturn($this->sejour(['asaScore' => 'III']));
        $this->sejours->expects($this->never())->method('updateDetails');
        $this->audit->expects($this->never())->method('insert');

        $r = $this->service->update('episode', 9, 'asaScore', 'IX', 'III');

        self::assertSame(RecordEditService::STATUS_INVALID, $r['status']);
        self::assertStringContainsString('échelle', (string) $r['message']);
        self::assertSame('III', $r['value'], 'la valeur actuelle est renvoyée');
    }

    public function testConflitDeConcurrenceRenvoieLaValeurActuelle(): void
    {
        $this->patients->method('findById')->willReturn($this->patient(['medicalHistory' => 'HTA, diabète']));
        $this->patients->expects($this->never())->method('updateDetails');

        $r = $this->service->update('patient', 9, 'medicalHistory', 'HTA, asthme', 'HTA');

        self::assertSame(RecordEditService::STATUS_CONFLICT, $r['status']);
        self::assertSame('HTA, diabète', $r['value']);
        self::assertStringContainsString('entre-temps', (string) $r['message']);
    }

    public function testValeurInchangeeNEcritRien(): void
    {
        $this->sejours->method('findById')->willReturn($this->sejour([], poids: 62.5));
        $this->sejours->expects($this->never())->method('updateDetails');
        $this->audit->expects($this->never())->method('insert');

        $r = $this->service->update('episode', 9, 'weight', '62.50', '62.5');

        self::assertSame(RecordEditService::STATUS_UNCHANGED, $r['status']);
        self::assertSame('62.5', $r['display']);
    }

    public function testChampsDeBaseDuSejourSontModifiablesEtArrondisCommeLAdmission(): void
    {
        $this->sejours->method('findById')->willReturn($this->sejour([], poids: 62.5));
        $this->sejours->expects($this->once())->method('updateDetails')->with(9, ['weight' => '63.46']);
        $this->audit->expects($this->once())->method('insert')->willReturn(1);

        $r = $this->service->update('episode', 9, 'weight', '63.456', '62.5');

        self::assertSame(RecordEditService::STATUS_SAVED, $r['status']);
        self::assertSame('63.46', $r['value']);
    }

    public function testServiceHospitalierEstControleParLeReferentiel(): void
    {
        $this->sejours->method('findById')->willReturn($this->sejour([]));

        $refuse = $this->service->update('episode', 9, 'idHealthcareService', 'SRV99', 'REA');
        self::assertSame(RecordEditService::STATUS_INVALID, $refuse['status']);

        $this->sejours->expects($this->once())->method('updateDetails')->with(9, ['idHealthcareService' => 'SRV01']);
        $this->audit->method('insert')->willReturn(1);
        $ok = $this->service->update('episode', 9, 'idHealthcareService', 'SRV01', 'REA');
        self::assertSame(RecordEditService::STATUS_SAVED, $ok['status']);
        self::assertSame('Réanimation médicale', $ok['display'], 'libellé du service, pas son id');
    }

    public function testViderUnChampEstUneModificationTracee(): void
    {
        $this->patients->method('findById')->willReturn($this->patient(['lifestyle' => 'Fumeur']));
        $this->patients->expects($this->once())->method('updateDetails')->with(9, ['lifestyle' => null]);
        $this->audit->expects($this->once())->method('insert')->willReturn(1);

        $r = $this->service->update('patient', 9, 'lifestyle', '   ', 'Fumeur');

        self::assertSame(RecordEditService::STATUS_SAVED, $r['status']);
        self::assertNull($r['value']);
    }

    public function testHistoriqueTraduitLesLibellesEtLesEchelles(): void
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
            new AuditEvent(1, 'Patient', 9, 'inconnu', null, 'x', 'non identifié', new DateTimeImmutable('2026-10-13')),
        ]);

        $rows = $this->service->history(9, 3);

        self::assertSame('Score ASA', $rows[0]['label']);
        self::assertSame('ASA II – maladie systémique légère', $rows[0]['oldValue']);
        self::assertSame('ASA III – maladie systémique sévère', $rows[0]['newValue']);
        self::assertSame('14/10/2026 08:05', $rows[0]['recordedAt']);
        self::assertSame('inconnu', $rows[1]['label'], 'un champ hors catalogue garde son nom brut');
        self::assertNull($rows[1]['oldValue']);
    }

    /** @param array<string, ?string> $details */
    private function patient(array $details): Patient
    {
        return new Patient(9, null, 'Durand', 'Alice', null, 'F', details: $details);
    }

    /** @param array<string, ?string> $details */
    private function sejour(array $details, ?float $poids = null): Sejour
    {
        return new Sejour(
            idSejour: 9,
            idPatient: 9,
            numSejour: '2026-000009',
            dateAdmission: new DateTimeImmutable('2026-10-13 09:30:00'),
            idService: 'REA',
            poids: $poids,
            details: $details
        );
    }
}
