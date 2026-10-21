<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AllergieDao;
use Aura\Dao\SejourDao;
use Aura\Dao\SubstanceDao;
use Aura\Domain\Allergie;
use Aura\Domain\Sejour;
use Aura\Domain\Substance;
use Aura\Service\AllergyIntoleranceService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** AURA-13.2 : ajout et retrait d'allergies, sans base (DAO simulés), un test par règle. */
final class AllergyIntoleranceServiceTest extends TestCase
{
    private const ID_SEJOUR = 9;
    private const ID_PATIENT = 42;

    private SejourDao&MockObject $sejours;
    private AllergieDao&MockObject $allergies;
    private SubstanceDao&MockObject $substances;
    private AllergyIntoleranceService $service;

    protected function setUp(): void
    {
        $this->sejours = $this->createMock(SejourDao::class);
        $this->allergies = $this->createMock(AllergieDao::class);
        $this->substances = $this->createMock(SubstanceDao::class);
        $this->service = new AllergyIntoleranceService($this->sejours, $this->allergies, $this->substances);
    }

    public function testAddInsertsAllergyForThePatientOfTheSejour(): void
    {
        $this->sejourExists();
        $this->substances->method('findById')->with('ALG01')->willReturn(new Substance('ALG01', 'Pénicilline'));
        $this->allergies->method('findByPatient')->with(self::ID_PATIENT)->willReturn([]);
        $this->allergies->expects($this->once())->method('insert')->with($this->callback(
            fn(Allergie $a) => $a->getIdAllergie() === null
                && $a->getIdPatient() === self::ID_PATIENT
                && $a->getIdAllergene() === 'ALG01'
                && $a->getSubstance() === 'Pénicilline'
                && $a->getCriticite() === 'Élevée'
        ))->willReturn(12);

        self::assertSame(12, $this->service->add(self::ID_SEJOUR, ' ALG01 ', 'Élevée'));
    }

    public function testEmptyCriticalityIsStoredAsNull(): void
    {
        $this->sejourExists();
        $this->substances->method('findById')->willReturn(new Substance('ALG02', 'Latex'));
        $this->allergies->method('findByPatient')->willReturn([]);
        $this->allergies->expects($this->once())->method('insert')
            ->with($this->callback(fn(Allergie $a) => $a->getCriticite() === null))
            ->willReturn(1);

        $this->service->add(self::ID_SEJOUR, 'ALG02', '');
    }

    public function testUnknownSejourIsRejected(): void
    {
        $this->sejours->method('findById')->with(self::ID_SEJOUR)->willReturn(null);
        $this->allergies->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->service->add(self::ID_SEJOUR, 'ALG01', null);
    }

    public function testMissingSubstanceIsRejected(): void
    {
        $this->sejourExists();
        $this->substances->expects($this->never())->method('findById');
        $this->allergies->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Choisissez un allergène');
        $this->service->add(self::ID_SEJOUR, '   ', null);
    }

    /** Critère 1 : un POST forgé avec un texte libre ou un id inconnu est refusé. */
    public function testSubstanceOutsideReferenceListIsRejected(): void
    {
        $this->sejourExists();
        $this->substances->method('findById')->with('Pénicilline')->willReturn(null);
        $this->allergies->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AllergyIntoleranceService::UNKNOWN_SUBSTANCE_MESSAGE);
        $this->service->add(self::ID_SEJOUR, 'Pénicilline', null);
    }

    public function testNonTextualSubstanceIsRejected(): void
    {
        $this->sejourExists();
        $this->allergies->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->service->add(self::ID_SEJOUR, ['ALG01'], null);
    }

    public function testCriticalityOutsideListIsRejected(): void
    {
        $this->sejourExists();
        $this->substances->method('findById')->willReturn(new Substance('ALG01', 'Pénicilline'));
        $this->allergies->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('criticité');
        $this->service->add(self::ID_SEJOUR, 'ALG01', 'Mortelle');
    }

    /** Critère 3, première couche : doublon détecté avant toute écriture. */
    public function testDuplicateDetectedByServiceIsRejectedWithoutInsert(): void
    {
        $this->sejourExists();
        $this->substances->method('findById')->willReturn(new Substance('ALG01', 'Pénicilline'));
        $this->allergies->method('findByPatient')->with(self::ID_PATIENT)->willReturn([
            new Allergie(1, self::ID_PATIENT, 'ALG01', 'Pénicilline', 'Faible'),
        ]);
        $this->allergies->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AllergyIntoleranceService::DUPLICATE_MESSAGE);
        $this->service->add(self::ID_SEJOUR, 'ALG01', 'Élevée');
    }

    /** Critère 3, seconde couche : condition de course, la base refuse (UNIQUE), même message. */
    public function testDuplicateDetectedByDatabaseIsTranslated(): void
    {
        $this->sejourExists();
        $this->substances->method('findById')->willReturn(new Substance('ALG01', 'Pénicilline'));
        $this->allergies->method('findByPatient')->willReturn([]);
        $this->allergies->method('insert')->willThrowException(self::mariaDbError(1062));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AllergyIntoleranceService::DUPLICATE_MESSAGE);
        $this->service->add(self::ID_SEJOUR, 'ALG01', null);
    }

    public function testSubstanceRemovedFromReferenceListMeanwhileIsTranslated(): void
    {
        $this->sejourExists();
        $this->substances->method('findById')->willReturn(new Substance('ALG01', 'Pénicilline'));
        $this->allergies->method('findByPatient')->willReturn([]);
        $this->allergies->method('insert')->willThrowException(self::mariaDbError(1452));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AllergyIntoleranceService::UNKNOWN_SUBSTANCE_MESSAGE);
        $this->service->add(self::ID_SEJOUR, 'ALG01', null);
    }

    public function testOtherDatabaseErrorIsRethrown(): void
    {
        $this->sejourExists();
        $this->substances->method('findById')->willReturn(new Substance('ALG01', 'Pénicilline'));
        $this->allergies->method('findByPatient')->willReturn([]);
        $this->allergies->method('insert')->willThrowException(self::mariaDbError(1205));

        $this->expectException(PDOException::class);
        $this->service->add(self::ID_SEJOUR, 'ALG01', null);
    }

    public function testRemoveDeletesAllergyOfThePatientOfTheSejour(): void
    {
        $this->sejourExists();
        $this->allergies->expects($this->once())->method('delete')->with(7, self::ID_PATIENT)->willReturn(true);

        $this->service->remove(self::ID_SEJOUR, 7);
    }

    public function testRemoveUnknownAllergyIsRejected(): void
    {
        $this->sejourExists();
        $this->allergies->method('delete')->with(7, self::ID_PATIENT)->willReturn(false);

        $this->expectException(InvalidArgumentException::class);
        $this->service->remove(self::ID_SEJOUR, 7);
    }

    public function testRemoveOnUnknownSejourIsRejectedWithoutDelete(): void
    {
        $this->sejours->method('findById')->willReturn(null);
        $this->allergies->expects($this->never())->method('delete');

        $this->expectException(InvalidArgumentException::class);
        $this->service->remove(self::ID_SEJOUR, 7);
    }

    private function sejourExists(): void
    {
        $this->sejours->method('findById')->with(self::ID_SEJOUR)->willReturn(new Sejour(
            idSejour: self::ID_SEJOUR,
            idPatient: self::ID_PATIENT,
            numSejour: '2026-000009',
            dateAdmission: new DateTimeImmutable('2026-10-13 09:30:00')
        ));
    }

    /** PDOException telle que PDO la construit : le code MariaDB est dans errorInfo[1]. */
    private static function mariaDbError(int $code): PDOException
    {
        $e = new PDOException('SQLSTATE[23000]: erreur simulée');
        $e->errorInfo = ['23000', $code, 'erreur simulée'];

        return $e;
    }
}
