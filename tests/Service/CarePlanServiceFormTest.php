<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\SubstanceDao;
use Aura\Dao\Transaction;
use Aura\Domain\CarePlan;
use Aura\Domain\MedicationRequest;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;
use Aura\Domain\ServiceRequest;
use Aura\Service\CarePlanService;
use Aura\Service\PatientRecordService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Retours du 2026-11-02 : l'écran B3 connaît les médicaments déjà prescrits et non arrêtés du séjour,
 * pour avertir (sans bloquer) quand on en prescrit un de plus. Sans base : DAO doublés.
 */
final class CarePlanServiceFormTest extends TestCase
{
    public function testOngoingEpisodeMedicationsWithoutStoppedLinesOrCare(): void
    {
        $start = new DateTimeImmutable('2026-10-10 08:00');
        $carePlans = $this->createStub(CarePlanDao::class);
        $carePlans->method('findByEpisodeOfCare')->willReturn([
            new CarePlan(1, 9, 'PRA01', 'MEDICAL', [
                new MedicationRequest(1, 'TYP01', 'MED01', '1 g', 'IV', $start),
                new MedicationRequest(
                    2,
                    'TYP01',
                    'MED05',
                    '2 mg',
                    'IV',
                    $start,
                    idStoppedBy: 'PRA01',
                    stoppedAt: new DateTimeImmutable('2026-10-11')
                ),
                new ServiceRequest(3, 'TYP02', $start),
            ], $start, 'PRA01', $start),
            // Ordonnance en attente de validation : ses médicaments comptent aussi.
            new CarePlan(2, 9, 'PRA04', 'PARAMEDICAL', [
                new MedicationRequest(4, 'TYP01', 'MED03', '5000 UI', 'SC', $start),
            ], $start),
        ]);

        $form = $this->service($carePlans)->form(9);

        self::assertNotNull($form);
        self::assertSame(['MED01', 'MED03'], $form['activeMedications'], 'MED05 est arrêté, le soin ne compte pas');
        self::assertSame('Protocoles', $form['categories']['GROUPS']['label']);
    }

    private function service(CarePlanDao $carePlans): CarePlanService
    {
        $episodesOfCare = $this->createStub(EpisodeOfCareDao::class);
        $episodesOfCare->method('findById')->willReturn(
            new EpisodeOfCare(9, 9, '2026-000009', new DateTimeImmutable('2026-10-10 08:00'))
        );
        $patients = $this->createStub(PatientDao::class);
        $patients->method('findById')->willReturn(new Patient(9, null, 'Durand', 'Alice', null, 'F'));
        $allergies = $this->createStub(AllergyIntoleranceDao::class);
        $allergies->method('findByPatient')->willReturn([]);
        $substances = $this->createStub(SubstanceDao::class);
        $substances->method('findAll')->willReturn([]);
        $references = $this->createStub(ReferenceDataDao::class);
        $references->method('practitioners')->willReturn([]);
        $references->method('medications')->willReturn([]);
        $references->method('medicationsByInn')->willReturn([]);
        $references->method('activityDefinitions')->willReturn([]);

        return new CarePlanService(
            $carePlans,
            $references,
            new PatientRecordService(
                $episodesOfCare,
                $patients,
                $allergies,
                $substances,
                new DateTimeImmutable('2026-10-14')
            ),
            $this->createStub(Transaction::class),
            $this->createStub(AuditEventDao::class)
        );
    }
}
