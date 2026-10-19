<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PrescriptionEntryDao;
use Aura\Service\PrescriptionEntryService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AURA-20 : saisie d'une prescription (figures 7 à 9) — données de la fenêtre,
 * contrôle serveur (RG3, RG4, RG5) et enregistrement dans les colonnes existantes.
 */
final class PrescriptionEntryServiceTest extends TestCase
{
    private const CARE_LINE = [
        'kind' => 'care', 'categoryId' => 'CAT01', 'id' => 'TYP02',
        'start' => '2026-10-20T08:00', 'frequencyHours' => '4', 'end' => '2026-10-27T08:00',
        'timeConstraint' => ' à jeun ',
    ];

    private const MEDICATION_LINE = [
        'kind' => 'medication', 'categoryId' => 'CAT05', 'id' => 'MED06',
        'frequencyType' => 'SCHEDULED', 'frequencyHours' => '6', 'route' => 'IV', 'dose' => '1 g',
        'start' => '2026-10-20T08:00', 'end' => '', 'important' => '', 'nurseInstructions' => 'Diluer dans 50 ml',
    ];

    private PrescriptionEntryDao&MockObject $dao;
    private PrescriptionEntryService $service;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->dao = $this->createMock(PrescriptionEntryDao::class);
        $this->now = new DateTimeImmutable('2026-10-19 10:00:00');
        $this->service = new PrescriptionEntryService($this->dao, $this->now);

        $this->dao->method('findEpisode')->willReturnCallback(fn(string $id): ?array => match ($id) {
            '2' => ['id' => '2', 'ongoing' => true],
            '4' => ['id' => '4', 'ongoing' => false],
            default => null,
        });
        $this->dao->method('findPrescribers')->willReturn([
            ['id' => 'PRA01', 'name' => 'Claire Morel', 'role' => 'Médecin réanimateur', 'canValidate' => true],
            ['id' => 'PRA04', 'name' => 'Thomas Girard', 'role' => 'IDE', 'canValidate' => false],
        ]);
        $this->dao->method('findCareTypes')->willReturn([
            ['id' => 'TYP06', 'name' => 'Isolement contact'],
            ['id' => 'TYP02', 'name' => 'Prise des constantes'],
        ]);
        $this->dao->method('findCategory')->willReturnCallback(fn(string $id): ?array => match ($id) {
            'CAT05' => ['id' => 'CAT05', 'label' => '5.a. Médicaments', 'kind' => 'MEDICATION'],
            'CAT01' => ['id' => 'CAT01', 'label' => '1. Surv', 'kind' => 'CARE'],
            default => null,
        });
        $this->dao->method('medicationExists')->willReturnCallback(fn(string $id): bool => $id === 'MED06');
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function carePlan(array $changes = []): array
    {
        return $changes + [
            'episodeId' => '2',
            'mode' => 'MEDICALE',
            'prescriber' => 'PRA01',
            'lines' => [self::CARE_LINE, self::MEDICATION_LINE],
        ];
    }

    public function testFormDataBuiltFromTheDatabase(): void
    {
        $this->dao->method('findCategories')->willReturn([
            ['id' => 'CAT01', 'label' => '1. Surv, isolement, contentions', 'kind' => 'CARE'],
        ]);
        $this->dao->method('findMedications')->willReturn([
            ['id' => 'MED07', 'inn' => 'Morphine', 'form' => 'Actiskenan PO'],
            ['id' => 'MED08', 'inn' => 'Morphine', 'form' => 'Skenan LP (PO)'],
        ]);

        $data = $this->service->getFormData('2');

        $this->assertSame(['TYP06' => 'Isolement contact', 'TYP02' => 'Prise des constantes'], $data['careTypes']);
        $this->assertSame(
            ['MED07' => 'Actiskenan PO', 'MED08' => 'Skenan LP (PO)'],
            $data['medications']['Morphine']['forms']
        );
        $this->assertSame('Claire Morel (Médecin réanimateur)', $data['prescribers']['PRA01']);
        $this->assertSame(PrescriptionEntryService::FREQUENCIES, $data['frequencies']);
        $this->assertCount(1, $data['categories']);
    }

    public function testFinishedEpisodeIsRejectedOnDisplay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('terminé');

        $this->service->getFormData('4');
    }

    public function testValidCarePlanIsSavedAndAutoValidatedInMedicalMode(): void
    {
        $this->dao->expects($this->once())
            ->method('insertCarePlan')
            ->with(
                $this->callback(function (array $plan): bool {
                    $this->assertSame('2', $plan['episodeId']);
                    $this->assertSame('PRA01', $plan['prescriberId']);
                    $this->assertSame('PRA01', $plan['validatorId'], 'RG5 : médecin habilité en mode médical');
                    $this->assertEquals($this->now, $plan['validatedAt']);
                    return true;
                }),
                $this->callback(function (array $careLines): bool {
                    $this->assertCount(1, $careLines);
                    $this->assertSame('TYP02', $careLines[0]['careTypeId']);
                    $this->assertSame(4, $careLines[0]['frequencyHours']);
                    $this->assertSame('2026-10-27 08:00', $careLines[0]['end']->format('Y-m-d H:i'));
                    $this->assertSame('à jeun', $careLines[0]['timeConstraint']);
                    return true;
                }),
                $this->callback(function (array $medicationLines): bool {
                    $this->assertCount(1, $medicationLines);
                    $m = $medicationLines[0];
                    $this->assertSame('MED06', $m['medicationId']);
                    $this->assertSame('CAT05', $m['careTypeId'], 'la catégorie est le type de soin de la ligne');
                    $this->assertSame(6, $m['frequencyHours']);
                    $this->assertNull($m['end']);
                    $this->assertSame('Consignes IDE : Diluer dans 50 ml', $m['timeConstraint']);
                    return true;
                })
            )
            ->willReturn('9');

        $this->assertSame([], $this->service->validate($this->carePlan()));
        $this->assertSame('9', $this->service->save($this->carePlan()));
    }

    public function testNonHourlyFrequencyAndImportantGoIntoTimeConstraint(): void
    {
        $saved = [];
        $this->dao->method('insertCarePlan')->willReturnCallback(
            function (array $plan, array $careLines, array $medicationLines) use (&$saved): string {
                $saved[] = [$medicationLines[0]['frequencyHours'], $medicationLines[0]['timeConstraint']];
                return '1';
            }
        );

        $line = ['frequencyType' => 'ASAP', 'frequencyHours' => '', 'important' => 'Allergie croisée',
            'nurseInstructions' => ''] + self::MEDICATION_LINE;
        $this->service->save($this->carePlan(['lines' => [$line]]));

        $this->assertSame([[null, 'Dès que possible ; Important : Allergie croisée']], $saved);
    }

    public function testOnlyADoctorsPrescriptionIsValidatedImmediately(): void
    {
        $validators = [];
        $this->dao->method('insertCarePlan')->willReturnCallback(
            function (array $plan) use (&$validators): string {
                $validators[] = $plan['validatorId'];
                return '1';
            }
        );

        $this->service->save($this->carePlan(['mode' => 'URGENCE']));
        $this->service->save($this->carePlan(['prescriber' => 'PRA04']));
        $this->service->save($this->carePlan(['prescriber' => 'PRA04', 'mode' => 'PARAMEDICALE']));

        $this->assertSame(
            ['PRA01', null, null],
            $validators,
            'RG5 : médecin → validée d\'office, quel que soit le mode ; non-médecin → en attente'
        );
    }

    public function testFormDataListsDoctorsAndPendingCarePlans(): void
    {
        $this->dao->method('findPendingCarePlans')->with('2')->willReturn([
            ['id' => '8', 'mode' => 'URGENCE', 'recordedAt' => '2026-10-16 03:10:00',
                'prescriber' => 'Inès Rousseau', 'lines' => ['[IV] Paracétamol (Solution injectable) 1 g']],
        ]);

        $data = $this->service->getFormData('2');

        $this->assertSame(['PRA01' => 'Claire Morel (Médecin réanimateur)'], $data['validators'], 'médecins seulement');
        $this->assertSame([[
            'id' => '8', 'mode' => 'Urgence', 'recordedAt' => '16/10/2026 03:10',
            'prescriber' => 'Inès Rousseau', 'lines' => ['[IV] Paracétamol (Solution injectable) 1 g'],
        ]], $data['pendingCarePlans']);
    }

    public function testDoctorValidatesAPendingCarePlan(): void
    {
        $this->dao->method('findCarePlan')->with('8')
            ->willReturn(['id' => '8', 'episodeId' => '2', 'validated' => false]);
        $this->dao->expects($this->once())
            ->method('validateCarePlan')
            ->with('8', 'PRA01', $this->now)
            ->willReturn(true);

        $this->assertSame([], $this->service->validateCarePlan('8', 'PRA01'));
    }

    /** @return iterable<string, array{string, string, ?array<string, mixed>, string, string}> */
    public static function refusedValidations(): iterable
    {
        $pending = ['id' => '8', 'episodeId' => '2', 'validated' => false];

        yield 'unknown care plan' => ['99', 'PRA01', null, 'carePlanId', 'introuvable'];
        yield 'already validated' => ['8', 'PRA01', ['validated' => true] + $pending, 'carePlanId', 'déjà validée'];
        yield 'no validator' => ['8', '', $pending, 'validator', 'médecin'];
        yield 'non-doctor validator' => ['8', 'PRA04', $pending, 'validator', 'Seul un médecin'];
    }

    /** @param array<string, mixed>|null $carePlan */
    #[DataProvider('refusedValidations')]
    public function testRefusedValidationChangesNothing(
        string $carePlanId,
        string $validatorId,
        ?array $carePlan,
        string $key,
        string $fragment
    ): void {
        $this->dao->method('findCarePlan')->willReturn($carePlan);
        $this->dao->expects($this->never())->method('validateCarePlan');

        $errors = $this->service->validateCarePlan($carePlanId, $validatorId);

        $this->assertArrayHasKey($key, $errors);
        $this->assertStringContainsString($fragment, $errors[$key]);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidCarePlans(): iterable
    {
        $care = self::CARE_LINE;
        $med = self::MEDICATION_LINE;

        yield 'unknown episode' => [['episodeId' => '99'], 'episodeId', 'introuvable'];
        yield 'finished episode (RG4)' => [['episodeId' => '4'], 'episodeId', 'terminé'];
        yield 'unknown mode' => [['mode' => 'AUTRE'], 'mode', 'mode'];
        yield 'no prescriber (RG4)' => [['prescriber' => ''], 'prescriber', 'prescripteur'];
        yield 'no line' => [['lines' => []], 'lines', 'au moins une'];
        yield 'care type not in list' => [['lines' => [['id' => 'TYP99'] + $care]], 'lines.1', 'type de soin'];
        yield 'care without start (RG3)' => [['lines' => [['start' => ''] + $care]], 'lines.1', 'début'];
        yield 'care end before start' => [['lines' => [['end' => '2026-10-01T08:00'] + $care]], 'lines.1', 'après'];
        yield 'care zero frequency' => [['lines' => [['frequencyHours' => '0'] + $care]], 'lines.1', 'fréquence'];
        yield 'medication not in list' => [['lines' => [['id' => 'MED99'] + $med]], 'lines.1', 'médicament'];
        yield 'medication without frequency' => [['lines' => [['frequencyType' => ''] + $med]], 'lines.1', 'fréquence'];
        yield 'scheduled without hours' => [['lines' => [['frequencyHours' => ''] + $med]], 'lines.1', 'heures'];
        yield 'unknown route' => [['lines' => [['route' => 'XX'] + $med]], 'lines.1', 'voie'];
        yield 'no dose' => [['lines' => [['dose' => ' '] + $med]], 'lines.1', 'dose'];
        yield 'instructions too long' => [
            ['lines' => [['nurseInstructions' => str_repeat('a', 190)] + $med]], 'lines.1', '200',
        ];
        yield 'care category for a medication' => [
            ['lines' => [['categoryId' => 'CAT01'] + $med]], 'lines.1', 'catégorie',
        ];
        yield 'unknown kind' => [['lines' => [['kind' => 'other'] + $care]], 'lines.1', 'nature'];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidCarePlans')]
    public function testInvalidCarePlanIsRejectedWithoutSaving(array $changes, string $key, string $fragment): void
    {
        $this->dao->expects($this->never())->method('insertCarePlan');

        $errors = $this->service->validate($this->carePlan($changes));

        $this->assertArrayHasKey($key, $errors);
        $this->assertStringContainsString($fragment, $errors[$key]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->save($this->carePlan($changes));
    }
}
