<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\CarePlanService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Retours des médecins du 2026-11-03 sur la vue Prescription, règles pures (sans base) : alerte et
 * refus d'un médicament qui contient un allergène du patient sauf confirmation explicite, note de
 * l'ordonnance bornée, authentification (factice) du médecin qui valide une ordonnance.
 */
final class CarePlanServiceSafetyTest extends TestCase
{
    /** Allergies du patient : idSubstance => libellé. */
    private const ALLERGENS = ['ALG01' => 'Pénicilline', 'ALG02' => 'Latex'];
    /** Référentiel : idMedication => idSubstance (seuls les médicaments qui en ont un). */
    private const MEDICATION_SUBSTANCES = ['MED04' => 'ALG01', 'MED06' => 'ALG11'];
    private const MEDICATIONS = [
        'MED01' => 'Paracétamol (Solution injectable)',
        'MED04' => 'Amoxicilline (Poudre pour solution injectable)',
        'MED06' => 'Céfotaxime (Poudre pour solution injectable)',
    ];

    public function testMedicationAllergensKeepsOnlyPatientAllergies(): void
    {
        self::assertSame(
            ['MED04' => 'Pénicilline'],
            CarePlanService::medicationAllergens(self::ALLERGENS, self::MEDICATION_SUBSTANCES),
            'MED06 (céphalosporines) : le patient n\'y est pas allergique'
        );
        self::assertSame([], CarePlanService::medicationAllergens([], self::MEDICATION_SUBSTANCES));
    }

    /** @return array<string, array{0: list<array<string, string>>, 1: string, 2: bool}> */
    public static function allergyCases(): array
    {
        $amoxicillin = ['kind' => 'MEDICATION', 'idMedication' => 'MED04'];
        $paracetamol = ['kind' => 'MEDICATION', 'idMedication' => 'MED01'];
        $cefotaxime = ['kind' => 'MEDICATION', 'idMedication' => 'MED06'];
        // Un soin ne porte jamais d'allergène, même si un idMedication traîne dans la requête.
        $care = ['kind' => 'CARE', 'idActivityDefinition' => 'TYP02', 'idMedication' => 'MED04'];

        return [
            'allergène sans confirmation : refus' => [[$paracetamol, $amoxicillin], '', true],
            'allergène, case non cochée (valeur forgée) : refus' => [[$amoxicillin], 'oui', true],
            'allergène avec confirmation : accepté' => [[$amoxicillin], '1', false],
            'aucun allergène du patient : accepté' => [[$paracetamol, $cefotaxime], '', false],
            'aucun allergène, case cochée quand même : sans effet' => [[$paracetamol], '1', false],
            'soin : jamais d\'allergène' => [[$care], '', false],
            'ordonnance vide : rien à signaler' => [[], '', false],
        ];
    }

    /** @param list<array<string, string>> $lines */
    #[DataProvider('allergyCases')]
    public function testAllergenRefusedUnlessExplicitlyConfirmed(array $lines, string $override, bool $refused): void
    {
        $conflicts = CarePlanService::allergyConflicts(
            $lines,
            CarePlanService::medicationAllergens(self::ALLERGENS, self::MEDICATION_SUBSTANCES),
            self::MEDICATIONS
        );
        $errors = CarePlanService::checkAllergies($conflicts, $override);

        if ($refused) {
            self::assertSame([CarePlanService::ALLERGY_OVERRIDE], array_keys($errors));
        } else {
            self::assertSame([], $errors);
        }
    }

    public function testRefusalNamesMedicationAllergenAndLine(): void
    {
        $conflicts = CarePlanService::allergyConflicts(
            [
                ['kind' => 'MEDICATION', 'idMedication' => 'MED01'],
                ['kind' => 'MEDICATION', 'idMedication' => 'MED04'],
            ],
            ['MED04' => 'Pénicilline'],
            self::MEDICATIONS
        );

        self::assertSame([[
            'line' => 2,
            'idMedication' => 'MED04',
            'medication' => 'Amoxicilline (Poudre pour solution injectable)',
            'allergen' => 'Pénicilline',
        ]], $conflicts);

        $message = CarePlanService::checkAllergies($conflicts, '')[CarePlanService::ALLERGY_OVERRIDE];
        self::assertStringContainsString('ligne 2', $message);
        self::assertStringContainsString('Amoxicilline (Poudre pour solution injectable)', $message);
        self::assertStringContainsString('Pénicilline', $message);
        self::assertStringContainsString('Je prescris malgré l\'allergie', $message);
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function validatorCases(): array
    {
        return [
            'médecin habilité et mot de passe : accepté' => ['PRA01', 'secret', true],
            'mot de passe non vérifié (factice) : accepté' => ['PRA02', 'x', true],
            'mot de passe vide : refusé' => ['PRA01', '', false],
            'mot de passe fait d\'espaces : refusé' => ['PRA01', '   ', false],
            'aucun médecin choisi : refusé' => ['', 'secret', false],
            'non-médecin (interne, hors liste) : refusé' => ['PRA03', 'secret', false],
            'praticien inconnu : refusé' => ['PRA99', 'secret', false],
        ];
    }

    #[DataProvider('validatorCases')]
    public function testValidationNeedsDoctorAndNonEmptyPassword(
        string $idValidator,
        string $password,
        bool $accepted
    ): void {
        $doctors = ['PRA01' => 'Claire Morel (Médecin réanimateur)', 'PRA02' => 'Karim Benali (Médecin réanimateur)'];

        $error = CarePlanService::checkValidatorAuth($idValidator, $password, $doctors);

        self::assertSame($accepted ? null : CarePlanService::AUTH_ERROR, $error);
    }

    public function testValidationMessageIsTheRecordDialogOne(): void
    {
        self::assertSame(
            'Choisissez le médecin qui valide et saisissez son mot de passe.',
            CarePlanService::AUTH_ERROR
        );
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function noteCases(): array
    {
        return [
            'pas de note' => ['', true],
            'note courte' => ['Réévaluer à J3.', true],
            'exactement la borne (caractères accentués)' => [str_repeat('é', CarePlanService::NOTE_MAX_LENGTH), true],
            'un caractère de trop' => [str_repeat('a', CarePlanService::NOTE_MAX_LENGTH + 1), false],
        ];
    }

    #[DataProvider('noteCases')]
    public function testCarePlanNoteIsBounded(string $note, bool $accepted): void
    {
        $service = (new \ReflectionClass(CarePlanService::class))->newInstanceWithoutConstructor();

        $errors = $service->checkInput([
            'mode' => 'MEDICAL',
            'idPrescriber' => 'PRA01',
            'note' => $note,
            'lines' => [[
                'kind' => 'CARE', 'idActivityDefinition' => 'TYP02', 'frequencyType' => 'ONCE',
                'startDate' => '2026-10-20T08:00',
            ]],
        ]);

        self::assertSame($accepted ? [] : ['note'], array_keys($errors));
    }
}
