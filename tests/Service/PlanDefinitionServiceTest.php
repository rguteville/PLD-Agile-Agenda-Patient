<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AllergieDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\PatientDao;
use Aura\Dao\PlanDefinitionDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\SejourDao;
use Aura\Dao\SubstanceDao;
use Aura\Domain\PlanDefinition;
use Aura\Domain\PlanDefinitionAction;
use Aura\Service\CarePlanService;
use Aura\Service\DossierPatientService;
use Aura\Service\PlanDefinitionService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Appliquer un protocole : dates calculées à partir de la date d'application, lignes au format
 * de l'écran B3 (aucun accès à la base, le DAO est simulé).
 */
final class PlanDefinitionServiceTest extends TestCase
{
    private const APPLIED_AT = '2026-10-20 08:00';

    public function testDatesDecaleesDepuisLaDateDApplication(): void
    {
        $lines = $this->service(self::septicShock())
            ->linesToPrefill('PLD01', new DateTimeImmutable(self::APPLIED_AT));

        self::assertCount(3, $lines);
        // Hémocultures : tout de suite, une fois, sans date d'arrêt.
        self::assertSame('2026-10-20T08:00', $lines[0]['startDate']);
        self::assertSame('', $lines[0]['endDate']);
        // Céfotaxime : startDate = application + 1 h ; endDate = startDate + 168 h.
        self::assertSame('2026-10-20T09:00', $lines[1]['startDate']);
        self::assertSame('2026-10-27T09:00', $lines[1]['endDate']);
        // Constantes : sans décalage, 24 h.
        self::assertSame('2026-10-20T08:00', $lines[2]['startDate']);
        self::assertSame('2026-10-21T08:00', $lines[2]['endDate']);
    }

    public function testChampsDeLigneDeLEcranB3(): void
    {
        [$bloodTest, $antibiotic, $vitals] = $this->service(self::septicShock())
            ->linesToPrefill('PLD01', new DateTimeImmutable(self::APPLIED_AT));

        self::assertSame(CarePlanService::LINE_FIELDS, array_keys($antibiotic), 'mêmes champs que le formulaire');

        self::assertSame(CarePlanService::KIND_CARE, $bloodTest['kind']);
        self::assertSame('TYP03', $bloodTest['idActivityDefinition']);
        self::assertSame('ONCE', $bloodTest['frequencyType']);
        self::assertSame('', $bloodTest['idMedication'], 'un soin n\'a pas de médicament');

        self::assertSame(CarePlanService::KIND_MEDICATION, $antibiotic['kind']);
        self::assertSame('MED06', $antibiotic['idMedication']);
        self::assertSame('2 g', $antibiotic['dose']);
        self::assertSame('IV', $antibiotic['route']);
        self::assertSame('Sepsis', $antibiotic['antibioticReason']);
        self::assertSame('SCHEDULED', $antibiotic['frequencyType']);
        self::assertSame('8', $antibiotic['frequencyHours']);

        self::assertSame('SCHEDULED', $vitals['frequencyType']);
        self::assertSame('1', $vitals['frequencyHours']);
    }

    public function testFrequenceEnJoursEntiersProposeeEnJours(): void
    {
        $plan = new PlanDefinition('PLD09', 'Pansements', actions: [
            new PlanDefinitionAction('PDA90', 'PLD09', PlanDefinitionAction::KIND_CARE, 'TYP04', frequencyHours: 48),
        ]);

        [$line] = $this->service($plan)->linesToPrefill('PLD09', new DateTimeImmutable(self::APPLIED_AT));

        self::assertSame('DAYS', $line['frequencyType']);
        self::assertSame('2', $line['frequencyDays']);
        self::assertSame('', $line['frequencyHours']);
    }

    /** Les lignes pré-remplies passent telles quelles le contrôle de saisie de l'ordonnance. */
    public function testLignesAccepteesParLeControleDeSaisie(): void
    {
        $lines = $this->service(self::septicShock())
            ->linesToPrefill('PLD01', new DateTimeImmutable(self::APPLIED_AT));

        // checkInput() n'accède pas à la base : les DAO ne sont jamais appelés.
        $carePlanService = new CarePlanService(
            $this->createStub(CarePlanDao::class),
            $this->createStub(ReferenceDataDao::class),
            new DossierPatientService(
                $this->createStub(SejourDao::class),
                $this->createStub(PatientDao::class),
                $this->createStub(AllergieDao::class),
                $this->createStub(SubstanceDao::class),
                new DateTimeImmutable(self::APPLIED_AT)
            )
        );

        self::assertSame([], $carePlanService->checkInput([
            'mode' => 'MEDICAL',
            'idPrescriber' => 'PRA01',
            'lines' => $lines,
        ]));
    }

    public function testProtocoleInconnuRefuse(): void
    {
        $dao = $this->createStub(PlanDefinitionDao::class);
        $dao->method('findById')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        (new PlanDefinitionService($dao))->linesToPrefill('PLD99', new DateTimeImmutable(self::APPLIED_AT));
    }

    public function testProtocoleRetireRefuse(): void
    {
        $retired = new PlanDefinition('PLD03', 'Ancienne version', active: false, actions: [
            new PlanDefinitionAction('PDA11', 'PLD03', PlanDefinitionAction::KIND_CARE, 'TYP02'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service($retired)->linesToPrefill('PLD03', new DateTimeImmutable(self::APPLIED_AT));
    }

    private function service(PlanDefinition $planDefinition): PlanDefinitionService
    {
        $dao = $this->createStub(PlanDefinitionDao::class);
        $dao->method('findById')->willReturn($planDefinition);

        return new PlanDefinitionService($dao);
    }

    private static function septicShock(): PlanDefinition
    {
        return new PlanDefinition('PLD01', 'Choc septique', 'PTH01', 'Sepsis et choc septique', true, [
            new PlanDefinitionAction(
                'PDA01',
                'PLD01',
                PlanDefinitionAction::KIND_CARE,
                'TYP03',
                timeConstraint: 'Hémocultures et lactates, avant l\'antibiotique'
            ),
            new PlanDefinitionAction(
                'PDA04',
                'PLD01',
                PlanDefinitionAction::KIND_MEDICATION,
                'TYP01',
                startOffsetHours: 1,
                durationHours: 168,
                frequencyHours: 8,
                idMedication: 'MED06',
                dose: '2 g',
                route: 'IV',
                antibioticReason: 'Sepsis'
            ),
            new PlanDefinitionAction(
                'PDA05',
                'PLD01',
                PlanDefinitionAction::KIND_CARE,
                'TYP02',
                durationHours: 24,
                frequencyHours: 1
            ),
        ]);
    }
}
