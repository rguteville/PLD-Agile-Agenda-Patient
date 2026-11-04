<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\SubstanceDao;
use Aura\Domain\AllergyIntolerance;
use Aura\Domain\EpisodeOfCare;
use Aura\Domain\Substance;
use Aura\Service\AllergyIntoleranceService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Ajout et retrait d'une allergie depuis le dossier (RG2), sans base (DAO doublés). */
final class AllergyIntoleranceServiceTest extends TestCase
{
    private const ID_EPISODE = 9;
    private const ID_PATIENT = 42;

    private EpisodeOfCareDao&MockObject $episodesOfCare;
    private AllergyIntoleranceDao&MockObject $allergies;
    private SubstanceDao&MockObject $substances;
    private AllergyIntoleranceService $service;

    protected function setUp(): void
    {
        $this->episodesOfCare = $this->createMock(EpisodeOfCareDao::class);
        $this->allergies = $this->createMock(AllergyIntoleranceDao::class);
        $this->substances = $this->createMock(SubstanceDao::class);
        $this->service = new AllergyIntoleranceService($this->episodesOfCare, $this->allergies, $this->substances);
    }

    public function testAllergyIsAddedToThePatientOfTheStay(): void
    {
        $this->episodeOfCareExists();
        $this->substances->method('findById')->with('ALG01')->willReturn(new Substance('ALG01', 'Pénicilline'));
        $this->allergies->method('findByPatient')->with(self::ID_PATIENT)->willReturn([]);
        $this->allergies->expects($this->once())->method('insert')->with($this->callback(
            fn(AllergyIntolerance $a) => $a->getIdAllergyIntolerance() === null
                && $a->getIdPatient() === self::ID_PATIENT
                && $a->getIdSubstance() === 'ALG01'
                && $a->getSubstance() === 'Pénicilline'
                && $a->getCriticality() === 'Élevée'
        ))->willReturn(12);

        self::assertSame(12, $this->service->add(self::ID_EPISODE, ' ALG01 ', 'Élevée'));
    }

    /** Critère 1 : un POST forgé avec un texte libre ou un id inconnu est refusé. */
    public function testAllergenOutsideTheReferenceListIsRefused(): void
    {
        $this->episodeOfCareExists();
        $this->substances->method('findById')->with('Pénicilline')->willReturn(null);
        $this->allergies->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AllergyIntoleranceService::UNKNOWN_SUBSTANCE_MESSAGE);
        $this->service->add(self::ID_EPISODE, 'Pénicilline', null);
    }

    /** Critère 3, première couche : doublon détecté avant toute écriture. */
    public function testKnownAllergyIsNotAddedTwice(): void
    {
        $this->episodeOfCareExists();
        $this->substances->method('findById')->willReturn(new Substance('ALG01', 'Pénicilline'));
        $this->allergies->method('findByPatient')->with(self::ID_PATIENT)->willReturn([
            new AllergyIntolerance(1, self::ID_PATIENT, 'ALG01', 'Pénicilline', 'Faible'),
        ]);
        $this->allergies->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AllergyIntoleranceService::DUPLICATE_MESSAGE);
        $this->service->add(self::ID_EPISODE, 'ALG01', 'Élevée');
    }

    /** Critère 3, seconde couche : condition de course, la base refuse (UNIQUE), même message. */
    public function testDuplicateRefusedByTheDatabaseGivesTheSameMessage(): void
    {
        $this->episodeOfCareExists();
        $this->substances->method('findById')->willReturn(new Substance('ALG01', 'Pénicilline'));
        $this->allergies->method('findByPatient')->willReturn([]);
        $this->allergies->method('insert')->willThrowException(self::mariaDbError(1062));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AllergyIntoleranceService::DUPLICATE_MESSAGE);
        $this->service->add(self::ID_EPISODE, 'ALG01', null);
    }

    public function testAllergyIsRemovedFromThePatientOfTheStay(): void
    {
        $this->episodeOfCareExists();
        $this->allergies->expects($this->once())->method('delete')->with(7, self::ID_PATIENT)->willReturn(true);

        $this->service->remove(self::ID_EPISODE, 7);
    }

    private function episodeOfCareExists(): void
    {
        $this->episodesOfCare->method('findById')->with(self::ID_EPISODE)->willReturn(new EpisodeOfCare(
            idEpisodeOfCare: self::ID_EPISODE,
            idPatient: self::ID_PATIENT,
            episodeNumber: '2026-000009',
            admissionDate: new DateTimeImmutable('2026-10-13 09:30:00')
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
