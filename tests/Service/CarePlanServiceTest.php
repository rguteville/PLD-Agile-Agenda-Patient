<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\SubstanceDao;
use Aura\Dao\Transaction;
use Aura\Service\CarePlanService;
use Aura\Service\PatientRecordService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Prescription, règles pures sans base : date de début obligatoire (RG3), dates bornées, alerte allergène
 * avec confirmation exacte, validation par un médecin authentifié (RG5, authentification factice).
 */
final class CarePlanServiceTest extends TestCase
{
    /** Allergies du patient : idSubstance => libellé. */
    private const ALLERGENS = ['ALG01' => 'Pénicilline'];
    /** Référentiel : idMedication => idSubstance. */
    private const MEDICATION_SUBSTANCES = ['MED04' => 'ALG01', 'MED06' => 'ALG11'];
    private const MEDICATIONS = [
        'MED01' => 'Paracétamol (Solution injectable)',
        'MED04' => 'Amoxicilline (Poudre pour solution injectable)',
        'MED06' => 'Céfotaxime (Poudre pour solution injectable)',
    ];
    private const DOCTORS = ['PRA01' => 'Claire Morel (Médecin réanimateur)'];

    public function testStartDateIsRequired(): void
    {
        $errors = $this->service()->checkInput(self::order(['startDate' => '']));

        self::assertSame(['lines[0][startDate]'], array_keys($errors));
        self::assertStringContainsString('RG3', $errors['lines[0][startDate]']);
    }

    /** RG4 : une prescription a un prescripteur et un type de soin (ou un médicament). */
    public function testPrescriptionNeedsAPrescriberAndACareType(): void
    {
        $order = self::order(['idActivityDefinition' => '']);
        $order['idPrescriber'] = '';

        $errors = $this->service()->checkInput($order);

        self::assertSame(['idPrescriber', 'lines[0][idActivityDefinition]'], array_keys($errors));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function outOfBoundsDates(): array
    {
        return [
            'année 9999' => [['startDate' => '9999-12-31T23:00'], 'startDate'],
            'fin avant le début' => [['endDate' => '2026-10-19T08:00'], 'endDate'],
            'plus d\'un an' => [['endDate' => '2027-10-21T08:00'], 'endDate'],
        ];
    }

    /** @param array<string, string> $dates */
    #[DataProvider('outOfBoundsDates')]
    public function testPrescriptionDatesAreBounded(array $dates, string $field): void
    {
        $errors = $this->service()->checkInput(self::order($dates));

        self::assertArrayHasKey('lines[0][' . $field . ']', $errors);
    }

    /** @return array<string, array{list<array<string, string>>, string, bool}> */
    public static function allergyCases(): array
    {
        $amoxicillin = ['kind' => 'MEDICATION', 'idMedication' => 'MED04'];
        $paracetamol = ['kind' => 'MEDICATION', 'idMedication' => 'MED01'];
        // Un soin ne porte jamais d'allergène, même si un idMedication traîne dans la requête.
        $care = ['kind' => 'CARE', 'idActivityDefinition' => 'TYP02', 'idMedication' => 'MED04'];

        return [
            'allergène sans confirmation : refus' => [[$paracetamol, $amoxicillin], '', true],
            'allergène, valeur forgée : refus' => [[$amoxicillin], 'oui', true],
            'allergène confirmé exactement : accepté' => [[$amoxicillin], 'MED04', false],
            'aucun allergène du patient : accepté' => [[$paracetamol], '', false],
            'soin : jamais d\'allergène' => [[$care], '', false],
        ];
    }

    /** @param list<array<string, string>> $lines */
    #[DataProvider('allergyCases')]
    public function testAllergenIsRefusedUnlessExplicitlyConfirmed(array $lines, string $override, bool $refused): void
    {
        $conflicts = CarePlanService::allergyConflicts(
            $lines,
            CarePlanService::medicationAllergens(self::ALLERGENS, self::MEDICATION_SUBSTANCES),
            self::MEDICATIONS
        );

        $errors = CarePlanService::checkAllergies($conflicts, $override);

        self::assertSame($refused ? [CarePlanService::ALLERGY_OVERRIDE] : [], array_keys($errors));
    }

    public function testConfirmationCoversOnlyTheMedicationsSeenWhenTicked(): void
    {
        $conflict = static fn (string $id): array => [
            'line' => 1, 'idMedication' => $id, 'medication' => $id, 'allergen' => 'Pénicilline',
        ];
        $two = [$conflict('MED07'), $conflict('MED04')];

        self::assertSame([], CarePlanService::checkAllergies($two, CarePlanService::allergySignature($two)));
        self::assertArrayHasKey(
            CarePlanService::ALLERGY_OVERRIDE,
            CarePlanService::checkAllergies($two, 'MED04'),
            'un allergène ajouté après la confirmation la rend caduque'
        );
    }

    /** Patient allergique à l'« amoxicilline » : alerte, même si le référentiel relie ce médicament à la pénicilline. */
    public function testAllergenNamedLikeTheMedicationIsFlagged(): void
    {
        self::assertSame(
            ['MED04' => 'amoxicilline'],
            CarePlanService::medicationAllergens(
                ['ALG06' => 'amoxicilline'],
                self::MEDICATION_SUBSTANCES,
                self::MEDICATIONS
            )
        );
    }

    /** @return array<string, array{string, string, bool}> */
    public static function validators(): array
    {
        return [
            'médecin et mot de passe : accepté' => ['PRA01', 'secret', true],
            'mot de passe vide : refusé' => ['PRA01', '  ', false],
            'interne, hors liste des médecins : refusé' => ['PRA03', 'secret', false],
        ];
    }

    /** RG5 : une ordonnance d'urgence ou paramédicale n'est validée que par un médecin authentifié. */
    #[DataProvider('validators')]
    public function testValidationNeedsADoctorAndAPassword(string $idValidator, string $password, bool $accepted): void
    {
        $error = CarePlanService::checkValidatorAuth($idValidator, $password, self::DOCTORS);

        self::assertSame($accepted ? null : CarePlanService::AUTH_ERROR, $error);
    }

    /**
     * Ordonnance d'une ligne de soin toutes les 8 h à partir du 20/10/2026 à 8 h.
     *
     * @param array<string, string> $line champs de la ligne à remplacer
     * @return array<string, mixed>
     */
    private static function order(array $line): array
    {
        return [
            'mode' => 'MEDICAL',
            'idPrescriber' => 'PRA01',
            'lines' => [$line + [
                'kind' => 'CARE', 'idActivityDefinition' => 'TYP02', 'frequencyType' => 'SCHEDULED',
                'frequencyHours' => '8', 'startDate' => '2026-10-20T08:00', 'endDate' => '',
            ]],
        ];
    }

    /** Service sans base : checkInput() n'appelle aucun DAO. */
    private function service(): CarePlanService
    {
        return new CarePlanService(
            $this->createStub(CarePlanDao::class),
            $this->createStub(ReferenceDataDao::class),
            new PatientRecordService(
                $this->createStub(EpisodeOfCareDao::class),
                $this->createStub(PatientDao::class),
                $this->createStub(AllergyIntoleranceDao::class),
                $this->createStub(SubstanceDao::class),
                new DateTimeImmutable('2026-10-14')
            ),
            $this->createStub(Transaction::class),
            $this->createStub(AuditEventDao::class)
        );
    }
}
