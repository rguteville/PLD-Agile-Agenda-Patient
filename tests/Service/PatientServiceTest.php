<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;
use Aura\Service\PatientService;
use Aura\Service\EpisodeOfCareService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AURA-41 / AURA-10.2 / AURA-10.3 : inscription d'un patient, validation côté
 * serveur et ouverture de son séjour.
 */
final class PatientServiceTest extends TestCase
{
    private const VALID_DATA = [
        'lastName' => '  Durand ',
        'firstName' => 'Alice',
        'birthDate' => '1990-06-15',
        'sex' => 'f',
        'ipp' => '123456',
        'phone' => '0601020304',
        'email' => 'alice.durand@example.org',
    ];

    private PatientDao&MockObject $dao;
    private EpisodeOfCareService&MockObject $episodesOfCare;
    private PatientService $service;

    protected function setUp(): void
    {
        $this->dao = $this->createMock(PatientDao::class);
        $this->episodesOfCare = $this->createMock(EpisodeOfCareService::class);

        // La transaction exécute simplement le traitement : pas de base ici.
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('run')->willReturnCallback(fn(callable $treatment) => $treatment());

        $this->service = new PatientService($this->dao, $this->episodesOfCare, $transaction);
    }

    public function testValidDataInsertsPatientAndOpensEpisode(): void
    {
        $this->dao->method('existsByIpp')->with('123456')->willReturn(false);
        $this->dao->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Patient $p): bool {
                $this->assertNull($p->getIdPatient());
                $this->assertSame('Durand', $p->getLastName(), 'le nom est nettoyé (trim)');
                $this->assertSame('Alice', $p->getFirstName());
                $this->assertSame('F', $p->getSex(), 'le sexe est normalisé en majuscule');
                $this->assertSame('1990-06-15', $p->getBirthDate()->format('Y-m-d'));
                $this->assertSame('123456', $p->getIpp());
                $this->assertSame('0601020304', $p->getPhone());
                $this->assertSame('alice.durand@example.org', $p->getEmail());
                $this->assertFalse($p->isAnonymous());

                return true;
            }))
            ->willReturn(42);

        $episodeOfCare = $this->episodeOfCare(idPatient: 42);
        $this->episodesOfCare->expects($this->once())
            ->method('openEpisodeOfCare')
            ->with(42, null, null)
            ->willReturn($episodeOfCare);

        $this->assertSame($episodeOfCare, $this->service->registerPatient(self::VALID_DATA));
    }

    public function testFormBedAndBoxArePassedToEpisode(): void
    {
        $this->dao->method('existsByIpp')->willReturn(false);
        $this->dao->method('insert')->willReturn(42);

        $this->episodesOfCare->expects($this->once())
            ->method('openEpisodeOfCare')
            ->with(42, 'L12', 'B3')
            ->willReturn($this->episodeOfCare(idPatient: 42, bedNumber: 'L12', boxNumber: 'B3'));

        $episodeOfCare = $this->service->registerPatient(
            ['bedNumber' => ' L12 ', 'boxNumber' => 'B3'] + self::VALID_DATA
        );

        $this->assertSame('L12', $episodeOfCare->getBedNumber());
    }

    public function testMissingOptionalFieldsAreNull(): void
    {
        $this->dao->expects($this->never())->method('existsByIpp');
        $this->dao->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Patient $p): bool {
                $this->assertNull($p->getIpp());
                $this->assertNull($p->getPhone());
                $this->assertNull($p->getEmail());

                return true;
            }))
            ->willReturn(7);
        $this->episodesOfCare->method('openEpisodeOfCare')->willReturn($this->episodeOfCare(idPatient: 7));

        $episodeOfCare = $this->service->registerPatient([
            'lastName' => 'Martin',
            'firstName' => 'Paul',
            'birthDate' => '1975-01-31',
            'sex' => 'M',
            'ipp' => '   ',
            'email' => '',
        ]);

        $this->assertSame(7, $episodeOfCare->getIdPatient());
    }

    public function testAnonymousBirthPatientWithoutBirthDateIsAccepted(): void
    {
        $this->dao->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Patient $p): bool {
                $this->assertSame('X', $p->getLastName(), 'le nom est normalisé en majuscule');
                $this->assertSame('X', $p->getFirstName());
                $this->assertNull($p->getBirthDate());
                $this->assertTrue($p->isAnonymous());

                return true;
            }))
            ->willReturn(8);
        $this->episodesOfCare->method('openEpisodeOfCare')->willReturn($this->episodeOfCare(idPatient: 8));

        $episodeOfCare = $this->service->registerPatient(['lastName' => 'x', 'firstName' => 'x', 'sex' => 'F']);

        $this->assertSame(8, $episodeOfCare->getIdPatient());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidData(): iterable
    {
        $base = self::VALID_DATA;

        yield 'nom vide' => [['lastName' => '  '] + $base, 'obligatoires'];
        yield 'nom absent' => [array_diff_key($base, ['lastName' => 1]), 'obligatoires'];
        yield 'prenom vide' => [['firstName' => ''] + $base, 'obligatoires'];
        yield 'nom trop long' => [['lastName' => str_repeat('a', 51)] + $base, '50 caractères'];
        yield 'sexe inconnu' => [['sex' => 'X'] + $base, 'sex'];
        yield 'sexe absent' => [array_diff_key($base, ['sex' => 1]), 'sex'];
        yield 'date absente (patient identifié)' => [array_diff_key($base, ['birthDate' => 1]), 'obligatoire'];
        yield 'date mal formée' => [['birthDate' => '15/06/1990'] + $base, 'date de naissance'];
        yield 'date inexistante' => [['birthDate' => '1990-02-30'] + $base, 'date de naissance'];
        yield 'date future' => [['birthDate' => '2999-01-01'] + $base, 'futur'];
        yield 'ipp trop court' => [['ipp' => '12345'] + $base, 'IPP'];
        yield 'ipp non numérique' => [['ipp' => 'ABC1234'] + $base, 'IPP'];
        yield 'ipp pour un patient sous X' => [['lastName' => 'X', 'firstName' => 'X'] + $base, 'sous X'];
        yield 'téléphone invalide' => [['phone' => '06 01 02 03 04'] + $base, 'téléphone'];
        yield 'email invalide' => [['email' => 'pas-un-email'] + $base, 'email'];
    }

    /** @param array<string, mixed> $donnees */
    #[DataProvider('invalidData')]
    public function testInvalidDataIsRejectedWithoutInsert(array $data, string $fragmentMessage): void
    {
        $this->dao->method('existsByIpp')->willReturn(false);
        $this->dao->expects($this->never())->method('insert');
        $this->episodesOfCare->expects($this->never())->method('openEpisodeOfCare');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($fragmentMessage);

        $this->service->registerPatient($data);
    }

    public function testAlreadyKnownIppIsRejected(): void
    {
        $this->dao->method('existsByIpp')->with('123456')->willReturn(true);
        $this->dao->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IPP 123456');

        $this->service->registerPatient(self::VALID_DATA);
    }

    public function testRejectedEpisodeCancelsRegistration(): void
    {
        $this->dao->method('existsByIpp')->willReturn(false);
        $this->dao->method('insert')->willReturn(42);
        $this->episodesOfCare->method('openEpisodeOfCare')
            ->willThrowException(new InvalidArgumentException('Le lit L1 est déjà occupé'));

        // L'exception traverse la transaction : c'est elle qui annule l'insertion du patient.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('déjà occupé');

        $this->service->registerPatient(self::VALID_DATA);
    }

    public function testSearchByIppWhenProvided(): void
    {
        $alice = $this->patient(3);
        $this->dao->method('findByIpp')->with('123456')->willReturn($alice);
        $this->dao->expects($this->never())->method('findByIdentity');

        $this->assertSame($alice, $this->service->searchExistingPatient(self::VALID_DATA));
    }

    public function testSearchByIdentityOnlyIfUnique(): void
    {
        $data = array_diff_key(self::VALID_DATA, ['ipp' => 1]);
        $alice = $this->patient(3);

        $this->dao->method('findByIdentity')
            ->with('Durand', 'Alice', $this->callback(fn($d) => $d->format('Y-m-d') === '1990-06-15'))
            ->willReturnOnConsecutiveCalls([$alice], [$alice, $this->patient(4)], []);

        $this->assertSame($alice, $this->service->searchExistingPatient($data), 'un seul : trouvé');
        $this->assertNull($this->service->searchExistingPatient($data), 'homonymes : ambigu');
        $this->assertNull($this->service->searchExistingPatient($data), 'nothing');
    }

    public function testNeverSearchForAnonymousBirthPatient(): void
    {
        $this->dao->expects($this->never())->method('findByIpp');
        $this->dao->expects($this->never())->method('findByIdentity');

        $data = ['lastName' => 'X', 'firstName' => 'X', 'ipp' => '123456'];

        $this->assertNull($this->service->searchExistingPatient($data));
    }

    public function testIncompleteSearchReturnsNull(): void
    {
        $this->dao->expects($this->never())->method('findByIdentity');

        $this->assertNull($this->service->searchExistingPatient(['lastName' => 'Durand', 'firstName' => 'Alice']));
    }

    public function testAdmitExistingPatientOpensAnEpisode(): void
    {
        $this->dao->method('findById')->with(3)->willReturn($this->patient(3));
        $episodeOfCare = $this->episodeOfCare(idPatient: 3, bedNumber: 'L2');
        $this->episodesOfCare->expects($this->once())
            ->method('openEpisodeOfCare')
            ->with(3, 'L2', null)
            ->willReturn($episodeOfCare);

        $this->assertSame($episodeOfCare, $this->service->admitExistingPatient(3, 'L2'));
    }

    public function testAdmitUnknownPatientIsRejected(): void
    {
        $this->dao->method('findById')->with(99)->willReturn(null);
        $this->episodesOfCare->expects($this->never())->method('openEpisodeOfCare');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Patient 99 introuvable');

        $this->service->admitExistingPatient(99);
    }

    public function testGetUnknownPatientThrows(): void
    {
        $this->dao->method('findById')->with(99)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Patient 99 introuvable');

        $this->service->getPatient(99);
    }

    private function patient(int $id): Patient
    {
        return new Patient($id, null, 'Durand', 'Alice', new DateTimeImmutable('1990-06-15'), 'F');
    }

    private function episodeOfCare(int $idPatient, ?string $bedNumber = null, ?string $boxNumber = null): EpisodeOfCare
    {
        return new EpisodeOfCare(
            idEpisodeOfCare: 1,
            idPatient: $idPatient,
            episodeNumber: '2026-000001',
            admissionDate: new DateTimeImmutable(),
            bedNumber: $bedNumber,
            boxNumber: $boxNumber
        );
    }
}
