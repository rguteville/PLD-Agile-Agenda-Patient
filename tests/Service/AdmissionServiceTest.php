<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Domain\RecordFields;
use Aura\Service\AdmissionService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AURA-10.2 : contrôle serveur des champs d'admission (RG1).
 */
final class AdmissionServiceTest extends TestCase
{
    /** Date de référence fixe pour que « strictement dans le passé » soit déterministe. */
    private const TODAY = '2026-10-14';

    private const VALID_DATA = [
        'lastName' => 'Durand',
        'firstName' => 'Alice',
        'ipp' => '123456',
        'birthDate' => '1990-06-15',
        'sex' => 'F',
        'height' => '168',
        'weight' => '61.5',
        'precautions' => 'Isolement contact',
    ];

    private AdmissionService $service;

    protected function setUp(): void
    {
        $this->service = new AdmissionService(new DateTimeImmutable(self::TODAY));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function missingRequiredFields(): iterable
    {
        yield 'nom vide' => [['lastName' => ''] + self::VALID_DATA, 'lastName'];
        yield 'nom espaces' => [['lastName' => '   '] + self::VALID_DATA, 'lastName'];
        yield 'nom absent' => [array_diff_key(self::VALID_DATA, ['lastName' => 1]), 'lastName'];
        yield 'nom non scalaire' => [['lastName' => ['x']] + self::VALID_DATA, 'lastName'];
        yield 'prenom vide' => [['firstName' => ''] + self::VALID_DATA, 'firstName'];
        yield 'prenom espaces' => [['firstName' => "\t "] + self::VALID_DATA, 'firstName'];
        yield 'prenom absent' => [array_diff_key(self::VALID_DATA, ['firstName' => 1]), 'firstName'];
    }

    /** @param array<string, mixed> $donnees */
    #[DataProvider('missingRequiredFields')]
    public function testMissingRequiredFieldGivesSingleErrorOnThatField(array $data, string $field): void
    {
        $errors = $this->service->validate($data);

        $this->assertSame([$field], array_keys($errors), 'seul le champ manquant est en erreur');
        $this->assertStringContainsString('obligatoire', $errors[$field]);
    }

    public function testLastAndFirstNameWithoutOtherFieldAreValid(): void
    {
        $this->assertSame([], $this->service->validate(['lastName' => ' Martin ', 'firstName' => 'Paul']));
    }

    public function testLastAndFirstNameWithOtherFieldsEmptyAreValid(): void
    {
        $data = array_fill_keys(array_keys(self::VALID_DATA), '');
        $data['lastName'] = 'Martin';
        $data['firstName'] = 'Paul';

        $this->assertSame([], $this->service->validate($data));
    }

    /** Le décès n'est pas un champ d'admission : une valeur envoyée (formulaire forgé) est ignorée. */
    public function testDeceasedIsNotAnAdmissionField(): void
    {
        self::assertArrayNotHasKey('deceased', RecordFields::admissionPatientFields());
        self::assertArrayHasKey('bloodType', RecordFields::admissionPatientFields());
        self::assertSame([], $this->service->validate(self::VALID_DATA + ['deceased' => 'n\'importe quoi']));
    }

    public function testIppIsOptional(): void
    {
        $this->assertSame([], $this->service->validate(['lastName' => 'Martin', 'firstName' => 'Paul', 'ipp' => '']));
        $this->assertSame(
            [],
            $this->service->validate(['lastName' => 'Martin', 'firstName' => 'Paul', 'ipp' => ' 987654 '])
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIpps(): iterable
    {
        yield 'trop court' => ['12345'];
        yield 'trop long' => ['1234567890'];
        yield 'letters' => ['ABC123'];
        yield 'espaces internes' => ['123 456'];
        yield 'négatif' => ['-123456'];
    }

    #[DataProvider('invalidIpps')]
    public function testMalformedIppIsRejected(string $ipp): void
    {
        $errors = $this->service->validate(['lastName' => 'Martin', 'firstName' => 'Paul', 'ipp' => $ipp]);

        $this->assertSame(['ipp'], array_keys($errors));
    }

    public function testIppOfSixToNineDigitsIsAccepted(): void
    {
        foreach (['100000', '999999999'] as $ipp) {
            $this->assertSame(
                [],
                $this->service->validate(['lastName' => 'Martin', 'firstName' => 'Paul', 'ipp' => $ipp])
            );
        }
    }

    /** AURA-15 : la recherche par IPP applique la même règle que l'admission, via validerIpp(). */
    #[DataProvider('invalidIpps')]
    public function testValidateIppRejectsSameValuesAsAdmission(string $ipp): void
    {
        $this->assertSame(
            "L'IPP doit contenir entre 6 et 9 chiffres et ne pas commencer par 0.",
            AdmissionService::validateIpp($ipp)
        );
    }

    public function testValidateIppAcceptsValidOrEmptyIpp(): void
    {
        $this->assertNull(AdmissionService::validateIpp(' 987654 '), 'les espaces autour sont ignorés');
        $this->assertNull(AdmissionService::validateIpp('999999999'));
        $this->assertNull(AdmissionService::validateIpp(''), 'facultatif (RG1)');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBirthDates(): iterable
    {
        yield 'mois et jour inexistants' => ['2026-13-45'];
        yield '30 février' => ['1990-02-30'];
        yield 'format français' => ['15/06/1990'];
        yield 'text' => ['yesterday'];
        yield 'date et heure' => ['1990-06-15 10:00'];
    }

    #[DataProvider('invalidBirthDates')]
    public function testInvalidBirthDateIsRejected(string $date): void
    {
        $errors = $this->service->validate(['birthDate' => $date] + self::VALID_DATA);

        $this->assertSame(['birthDate'], array_keys($errors));
        $this->assertStringContainsString('invalid', $errors['birthDate']);
        $this->assertStringNotContainsString($date, $errors['birthDate'], 'la valeur saisie ne figure pas');
    }

    /** @return iterable<string, array{string}> */
    public static function nonPastBirthDates(): iterable
    {
        yield 'future' => ['2999-01-01'];
        yield 'tomorrow' => ['2026-10-15'];
        yield "aujourd'hui" => [self::TODAY];
    }

    #[DataProvider('nonPastBirthDates')]
    public function testNonPastBirthDateIsRejected(string $date): void
    {
        $errors = $this->service->validate(['birthDate' => $date] + self::VALID_DATA);

        $this->assertSame(['birthDate'], array_keys($errors));
        $this->assertStringContainsString('antérieure', $errors['birthDate']);
    }

    public function testBirthDateOfYesterdayIsAccepted(): void
    {
        $this->assertSame([], $this->service->validate(['birthDate' => '2026-10-13'] + self::VALID_DATA));
    }

    /** @return iterable<string, array{string}> */
    public static function sexesOutsideList(): iterable
    {
        yield 'unknown' => ['X'];
        yield 'valeur formulaire non convertie (H)' => ['H'];
        yield 'lowercase' => ['f'];
    }

    #[DataProvider('sexesOutsideList')]
    public function testSexOutsideListIsRejected(string $sex): void
    {
        $errors = $this->service->validate(['sex' => $sex] + self::VALID_DATA);

        $this->assertSame(['sex'], array_keys($errors));
    }

    public function testAllowedSexesAreAccepted(): void
    {
        foreach (AdmissionService::ALLOWED_SEXES as $sex) {
            $this->assertSame([], $this->service->validate(['sex' => $sex] + self::VALID_DATA));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidMeasures(): iterable
    {
        yield 'taille texte' => ['height', 'abc'];
        yield 'taille négative' => ['height', '-5'];
        yield 'taille nulle' => ['height', '0'];
        yield 'poids nul' => ['weight', '0'];
        yield 'poids négatif' => ['weight', '-70'];
        yield 'poids avec unité' => ['weight', '70kg'];
    }

    #[DataProvider('invalidMeasures')]
    public function testNonPositiveHeightOrWeightIsRejected(string $field, string $value): void
    {
        $errors = $this->service->validate([$field => $value] + self::VALID_DATA);

        $this->assertSame([$field], array_keys($errors));
        $this->assertStringContainsString('strictement positif', $errors[$field]);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function tooLargeMeasures(): iterable
    {
        yield 'taille de 1000 cm' => ['height', '1000', 'inférieure à 1000 cm'];
        yield 'poids de 1000 kg' => ['weight', '1000', 'inférieur à 1000 kg'];
        yield 'poids arrondi à 1000' => ['weight', '999.995', 'inférieur à 1000 kg'];
        yield 'notation exponentielle' => ['height', '1e400', 'inférieure à 1000 cm'];
    }

    /** AURA-10.1 : colonnes DECIMAL(5,2), une valeur trop grande serait refusée par MariaDB. */
    #[DataProvider('tooLargeMeasures')]
    public function testTooLargeHeightOrWeightIsRejected(string $field, string $value, string $message): void
    {
        $errors = $this->service->validate([$field => $value] + self::VALID_DATA);

        $this->assertSame([$field], array_keys($errors));
        $this->assertStringContainsString($message, $errors[$field]);
    }

    public function testMeasuresAtColumnLimitAreAccepted(): void
    {
        $limits = ['height' => '999.99', 'weight' => '0.01'];

        $this->assertSame([], $this->service->validate($limits + self::VALID_DATA));
    }

    public function testPrecautionsOfFiveHundredCharactersAreAccepted(): void
    {
        // Caractères accentués : la limite porte sur les caractères, pas les octets.
        $precautions = str_repeat('é', AdmissionService::PRECAUTIONS_MAX_LENGTH);

        $this->assertSame([], $this->service->validate(['precautions' => $precautions] + self::VALID_DATA));
    }

    public function testTooLongPrecautionsAreRejected(): void
    {
        $precautions = str_repeat('a', AdmissionService::PRECAUTIONS_MAX_LENGTH + 1);

        $errors = $this->service->validate(['precautions' => $precautions] + self::VALID_DATA);

        $this->assertSame(['precautions'], array_keys($errors));
        $this->assertStringContainsString('500 caractères', $errors['precautions']);
    }

    public function testBrowserLineBreakCountsAsOneCharacter(): void
    {
        // Le navigateur envoie \r\n, mais maxlength du textarea le compte pour un caractère.
        $precautions = str_repeat("a\r\n", 200);

        $this->assertSame([], $this->service->validate(['precautions' => $precautions] + self::VALID_DATA));
    }

    public function testCompleteValidSetGivesNoError(): void
    {
        $this->assertSame([], $this->service->validate(self::VALID_DATA));
    }

    public function testErrorsAccumulateOnePerField(): void
    {
        $errors = $this->service->validate([
            'lastName' => '',
            'firstName' => ' ',
            'ipp' => 'n’importe quoi',
            'birthDate' => '2026-13-45',
            'sex' => 'X',
            'height' => 'abc',
            'weight' => '0',
        ]);

        $this->assertSame(
            ['lastName', 'firstName', 'ipp', 'birthDate', 'sex', 'height', 'weight'],
            array_keys($errors)
        );
    }

    public function testValidationDoesNotChangeData(): void
    {
        $data = self::VALID_DATA;
        $this->service->validate($data);

        $this->assertSame(self::VALID_DATA, $data);
    }

    public function testValidatePatientIgnoresEpisodeFields(): void
    {
        $data = ['height' => '-5', 'weight' => 'abc'] + self::VALID_DATA;

        $this->assertSame([], $this->service->validatePatientIdentity($data));
        $this->assertSame(['height', 'weight'], array_keys($this->service->validate($data)));
    }

    public function testValidatePatientAppliesSameRulesAsValidate(): void
    {
        $data = ['lastName' => '', 'ipp' => '12', 'sex' => 'X'] + self::VALID_DATA;

        $this->assertSame($this->service->validate($data), $this->service->validatePatientIdentity($data));
        $this->assertSame(['lastName', 'ipp', 'sex'], array_keys($this->service->validatePatientIdentity($data)));
    }
    /** Champs complémentaires (catalogue RecordFields) : refus d'une valeur hors échelle, le reste intact. */
    public function testOutOfScaleScoreIsRejectedOnItsFieldOnly(): void
    {
        $errors = $this->service->validate(self::VALID_DATA + ['asaScore' => 'VII', 'nyhaClass' => 'II']);

        self::assertSame(['asaScore'], array_keys($errors));
    }

    public function testValidAdditionalFieldsGiveNoError(): void
    {
        $data = self::VALID_DATA + [
            'admissionReason' => 'Choc septique',
            'medicalHistory' => "HTA\nDiabète",
            'asaScore' => 'III',
            'girScore' => '4',
            'surgeryDate' => '2026-10-12',
            'trustedPersonEmail' => 'proche@example.com',
            'allergies' => ['ALG01', 'ALG02'],
            'idService' => 'SRV01',
        ];

        self::assertSame([], $this->service->validate($data));
    }

    public function testServiceMissingFromReferenceListIsRejected(): void
    {
        $referenceDataSets = ['services' => ['SRV01' => 'Réanimation médicale']];

        $errors = $this->service->validate(self::VALID_DATA + ['idService' => 'SRV99'], $referenceDataSets);

        self::assertSame(['idService'], array_keys($errors));
        self::assertSame([], $this->service->validate(self::VALID_DATA + ['idService' => 'SRV01'], $referenceDataSets));
    }

    public function testAllergenMissingFromReferenceListIsRejected(): void
    {
        $referenceDataSets = ['substances' => ['ALG01' => 'Pénicilline']];

        $errors = $this->service->validate(self::VALID_DATA + ['allergies' => ['ALG01', 'ALG99']], $referenceDataSets);

        self::assertSame(['allergies'], array_keys($errors));
        self::assertSame(
            [],
            $this->service->validate(self::VALID_DATA + ['allergies' => ['ALG01']], $referenceDataSets)
        );
        self::assertSame([], $this->service->validate(self::VALID_DATA + ['allergies' => ''], $referenceDataSets));
    }

    public function testWithoutReferenceListMembershipCheckIsDisabled(): void
    {
        // Les tests unitaires et les pages sans base n'ont pas de référentiel : rien n'est refusé ici,
        // la base refusera de toute façon une clé étrangère inconnue.
        self::assertSame([], $this->service->validate(self::VALID_DATA + ['idService' => 'SRV99']));
    }

    /** Médecins proposés comme référent (retours du 2026-11-03), lus en base par l'appelant. */
    private const DOCTORS = ['PRA01' => 'Dr Claire Morel', 'PRA02' => 'Dr Karim Benali'];

    /** @return iterable<string, array{mixed}> */
    public static function acceptedReferringPractitioners(): iterable
    {
        yield 'médecin de la liste' => ['PRA02'];
        yield 'à désigner (vide)' => [''];
        yield 'à désigner (espaces)' => ['   '];
        yield 'champ absent' => [null];
        // Comme les autres champs : une valeur non textuelle (formulaire forgé) vaut « vide ».
        yield 'non scalaire' => [['PRA01']];
    }

    #[DataProvider('acceptedReferringPractitioners')]
    public function testReferringPractitionerFromListOrToBeDesignatedIsAccepted(mixed $value): void
    {
        $data = $value === null ? self::VALID_DATA : self::VALID_DATA + ['idReferringPractitioner' => $value];

        self::assertSame([], $this->service->validate($data, ['doctors' => self::DOCTORS]));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedReferringPractitioners(): iterable
    {
        yield 'interne (praticien, pas médecin de la liste)' => ['PRA03'];
        yield 'identifiant inconnu' => ['PRA99'];
        yield 'casse différente' => ['pra01'];
        yield 'injection' => ["PRA01' OR '1'='1"];
    }

    #[DataProvider('rejectedReferringPractitioners')]
    public function testReferringPractitionerOutsideDoctorListIsRejectedOnItsFieldOnly(string $value): void
    {
        $errors = $this->service->validate(
            self::VALID_DATA + ['idReferringPractitioner' => $value],
            ['doctors' => self::DOCTORS]
        );

        self::assertSame(['idReferringPractitioner'], array_keys($errors));
        self::assertStringNotContainsString($value, $errors['idReferringPractitioner'], 'valeur saisie non reprise');
    }

    public function testWithoutDoctorListReferringPractitionerIsNotChecked(): void
    {
        // Comme pour le service : sans référentiel (tests, page sans base), la clé étrangère protège la base.
        self::assertSame([], $this->service->validate(self::VALID_DATA + ['idReferringPractitioner' => 'PRA99']));
    }

    public function testUnknownAllergyCriticalityIsRejectedUnderAllergyList(): void
    {
        $errors = $this->service->validate(self::VALID_DATA + [
            'allergies' => ['ALG01'], 'allergyCriticalities' => ['ALG01' => 'Mortelle'],
        ]);

        self::assertSame(['allergyCriticalities'], array_keys($errors));
        self::assertSame([], $this->service->validate(self::VALID_DATA + [
            'allergies' => ['ALG01', 'ALG02'], 'allergyCriticalities' => ['ALG01' => 'Élevée', 'ALG02' => ''],
        ]));
    }
}
