<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\EpisodeOfCare;
use Aura\Domain\Patient;
use Aura\Service\AdmissionService;
use Aura\Service\EpisodeOfCareService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Admission (RG1) sans base : contrôle des champs (validate), puis enregistrement avec DAO doublés (admit).
 * Le comportement sur la vraie base est dans AdmissionIntegrationTest.
 */
final class AdmissionServiceTest extends TestCase
{
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
        $this->service = new AdmissionService(new DateTimeImmutable('2026-10-14'));
    }

    // ---- Contrôle des champs ----

    public function testLastAndFirstNameAloneAreEnough(): void
    {
        self::assertSame([], $this->service->validate(['lastName' => ' Martin ', 'firstName' => 'Paul']));
        self::assertSame([], $this->service->validate(self::VALID_DATA));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function missingNames(): iterable
    {
        yield 'nom vide' => [['lastName' => ''] + self::VALID_DATA, 'lastName'];
        yield 'nom fait d\'espaces' => [['lastName' => '   '] + self::VALID_DATA, 'lastName'];
        yield 'prénom absent' => [array_diff_key(self::VALID_DATA, ['firstName' => 1]), 'firstName'];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('missingNames')]
    public function testMissingNameGivesOneErrorOnThatField(array $data, string $field): void
    {
        $errors = $this->service->validate($data);

        self::assertSame([$field], array_keys($errors));
        self::assertStringContainsString('obligatoire', $errors[$field]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIpps(): iterable
    {
        yield 'trop court' => ['12345'];
        yield 'trop long' => ['1234567890'];
        yield 'lettres' => ['ABC123'];
    }

    #[DataProvider('invalidIpps')]
    public function testMalformedIppIsRejected(string $ipp): void
    {
        $errors = $this->service->validate(['ipp' => $ipp] + self::VALID_DATA);

        self::assertSame(['ipp'], array_keys($errors));
    }

    public function testIppIsOptionalAndAcceptedFromSixToNineDigits(): void
    {
        foreach (['', '100000', '999999999'] as $ipp) {
            self::assertSame([], $this->service->validate(['ipp' => $ipp] + self::VALID_DATA), $ipp);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBirthDates(): iterable
    {
        yield 'date impossible' => ['1990-02-30'];
        yield 'aujourd\'hui' => ['2026-10-14'];
        yield 'futur' => ['2999-01-01'];
    }

    #[DataProvider('invalidBirthDates')]
    public function testBirthDateMustExistAndBeInThePast(string $date): void
    {
        $errors = $this->service->validate(['birthDate' => $date] + self::VALID_DATA);

        self::assertSame(['birthDate'], array_keys($errors));
    }

    public function testSexOutsideTheListIsRejected(): void
    {
        self::assertSame(['sex'], array_keys($this->service->validate(['sex' => 'X'] + self::VALID_DATA)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidMeasures(): iterable
    {
        yield 'taille nulle' => ['height', '0'];
        yield 'poids négatif' => ['weight', '-70'];
        yield 'poids avec unité' => ['weight', '70kg'];
        yield 'taille trop grande pour la colonne' => ['height', '1000'];
    }

    #[DataProvider('invalidMeasures')]
    public function testHeightAndWeightMustBePositiveAndFitTheColumn(string $field, string $value): void
    {
        self::assertSame([$field], array_keys($this->service->validate([$field => $value] + self::VALID_DATA)));
    }

    public function testTooLongPrecautionsAreRejected(): void
    {
        $precautions = str_repeat('a', AdmissionService::PRECAUTIONS_MAX_LENGTH + 1);

        self::assertSame(
            ['precautions'],
            array_keys($this->service->validate(['precautions' => $precautions] + self::VALID_DATA))
        );
    }

    public function testValuesOutsideTheReferenceListsAreRejected(): void
    {
        $lists = [
            'services' => ['SRV01' => 'Réanimation médicale'],
            'substances' => ['ALG01' => 'Pénicilline'],
            'doctors' => ['PRA01' => 'Dr Claire Morel'],
        ];
        $forged = ['idService' => 'SRV99', 'allergies' => ['ALG01', 'ALG99'], 'idReferringPractitioner' => 'PRA03'];

        $errors = $this->service->validate($forged + self::VALID_DATA, $lists);

        self::assertSame(['idService', 'idReferringPractitioner', 'allergies'], array_keys($errors));
        $valid = ['idService' => 'SRV01', 'allergies' => ['ALG01'], 'idReferringPractitioner' => ''];
        self::assertSame([], $this->service->validate($valid + self::VALID_DATA, $lists), 'référent à désigner');
    }

    public function testOutOfScaleScoreIsRejected(): void
    {
        self::assertSame(['asaScore'], array_keys($this->service->validate(['asaScore' => 'VII'] + self::VALID_DATA)));
    }

    /** Le décès n'est pas un champ d'admission : une valeur envoyée par un formulaire forgé est ignorée. */
    public function testDeceasedSentWithTheAdmissionIsIgnored(): void
    {
        self::assertSame([], $this->service->validate(self::VALID_DATA + ['deceased' => '1']));
    }

    public function testErrorsAccumulateOnePerField(): void
    {
        $errors = $this->service->validate([
            'lastName' => '', 'firstName' => ' ', 'ipp' => 'x', 'birthDate' => '2026-13-45', 'sex' => 'X',
            'height' => 'abc', 'weight' => '0',
        ]);

        self::assertSame(['lastName', 'firstName', 'ipp', 'birthDate', 'sex', 'height', 'weight'], array_keys($errors));
    }

    // ---- Enregistrement (DAO doublés) ----

    public function testNewPatientIsInsertedAndItsStayOpened(): void
    {
        $patients = $this->createMock(PatientDao::class);
        $patients->method('findByIpp')->willReturn(null);
        $patients->expects($this->once())->method('insert')
            ->with($this->callback(static fn (Patient $p): bool => $p->getLastName() === 'Durand'))
            ->willReturn(42);
        $episodes = $this->createMock(EpisodeOfCareService::class);
        $episodes->expects($this->once())->method('openEpisodeOfCare')->with(42)->willReturn(self::episode(42));

        $episode = $this->admission($patients, $episodes)->admit(['lastName' => ' Durand '] + self::VALID_DATA);

        self::assertSame(42, $episode->getIdPatient());
    }

    public function testPatientWithoutIppGetsOneAutomatically(): void
    {
        $patients = $this->createMock(PatientDao::class);
        $patients->method('nextIpp')->willReturn(100000042);
        $patients->expects($this->once())->method('insert')
            ->with($this->callback(static fn (Patient $p): bool => $p->getIpp() === '100000042'))
            ->willReturn(7);
        $episodes = $this->createStub(EpisodeOfCareService::class);
        $episodes->method('openEpisodeOfCare')->willReturn(self::episode(7));

        $this->admission($patients, $episodes)->admit(['lastName' => 'Martin', 'firstName' => 'Paul']);
    }

    public function testPatientKnownByIppIsReusedNotRecreated(): void
    {
        $known = new Patient(3, '123456', 'Durand', 'Alice', new DateTimeImmutable('1990-06-15'), 'F');
        $patients = $this->createMock(PatientDao::class);
        $patients->method('findByIpp')->with('123456')->willReturn($known);
        $patients->expects($this->never())->method('insert');
        $episodes = $this->createMock(EpisodeOfCareService::class);
        $episodes->expects($this->once())->method('openEpisodeOfCare')->with(3)->willReturn(self::episode(3));

        $this->admission($patients, $episodes)->admit(self::VALID_DATA);
    }

    public function testInvalidDataWritesNothing(): void
    {
        $patients = $this->createMock(PatientDao::class);
        $patients->expects($this->never())->method('insert');
        $episodes = $this->createMock(EpisodeOfCareService::class);
        $episodes->expects($this->never())->method('openEpisodeOfCare');

        $this->expectException(InvalidArgumentException::class);
        $this->admission($patients, $episodes)->admit(['lastName' => '', 'firstName' => 'Paul']);
    }

    /** L'IPP d'un patient supprimé (suppression logique) reste réservé : l'admission est refusée. */
    public function testIppOfARemovedPatientIsRefused(): void
    {
        $patients = $this->createMock(PatientDao::class);
        $patients->method('findByIpp')->willReturn(null);
        $patients->method('existsByIpp')->with('123456')->willReturn(true);
        $patients->expects($this->never())->method('insert');

        $this->expectExceptionMessage(AdmissionService::REMOVED_PATIENT_IPP_MESSAGE);
        $this->admission($patients, $this->createStub(EpisodeOfCareService::class))->admit(self::VALID_DATA);
    }

    private function admission(PatientDao $patients, EpisodeOfCareService $episodes): AdmissionService
    {
        // Transaction sans base : le traitement est simplement exécuté.
        $transaction = $this->createStub(Transaction::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $treatment): mixed => $treatment());

        return new AdmissionService(new DateTimeImmutable('2026-10-14'), $patients, $episodes, $transaction);
    }

    private static function episode(int $idPatient): EpisodeOfCare
    {
        return new EpisodeOfCare(11, $idPatient, '2026-000011', new DateTimeImmutable('2026-10-14 10:00:00'));
    }
}
