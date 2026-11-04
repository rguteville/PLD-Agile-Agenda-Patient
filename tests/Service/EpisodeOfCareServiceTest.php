<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\EpisodeOfCareDao;
use Aura\Domain\EpisodeOfCare;
use Aura\Service\EpisodeOfCareService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Ouverture et fin d'un séjour, sans base (DAO doublé) : un seul séjour en cours par patient, lit libre,
 * sortie datée après l'admission. Le numéro de séjour est généré par EpisodeOfCareDao (EpisodeOfCareDaoTest).
 */
final class EpisodeOfCareServiceTest extends TestCase
{
    private EpisodeOfCareDao&MockObject $dao;
    private EpisodeOfCareService $service;

    protected function setUp(): void
    {
        $this->dao = $this->createMock(EpisodeOfCareDao::class);
        $this->service = new EpisodeOfCareService($this->dao);
    }

    public function testPatientAlreadyInAStayCannotBeAdmittedAgain(): void
    {
        $this->dao->method('findByPatient')->with(5)->willReturn([
            $this->episodeOfCare(1, 5, dischargeDate: new DateTimeImmutable('-1 day')),
            $this->episodeOfCare(2, 5),
        ]);
        $this->dao->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('déjà un séjour en cours');

        $this->service->openEpisodeOfCare(5);
    }

    public function testOccupiedBedIsRefused(): void
    {
        $this->dao->method('findByPatient')->willReturn([]);
        $this->dao->method('findOngoing')->willReturn([$this->episodeOfCare(1, 9, bedNumber: 'L1')]);
        $this->dao->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lit L1 est déjà occupé');

        $this->service->openEpisodeOfCare(5, 'L1');
    }

    public function testStayCannotBeEndedTwice(): void
    {
        $this->dao->method('findById')
            ->willReturn($this->episodeOfCare(1, 5, dischargeDate: new DateTimeImmutable('-1 hour')));
        $this->dao->expects($this->never())->method('updateDischarge');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('déjà terminé');

        $this->service->closeEpisodeOfCare(1);
    }

    public function testDischargeBeforeAdmissionIsRefused(): void
    {
        $this->dao->method('findById')->willReturn($this->episodeOfCare(1, 5));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('précéder');

        $this->service->closeEpisodeOfCare(1, new DateTimeImmutable('2000-01-01'));
    }

    private function episodeOfCare(
        int $id,
        int $idPatient,
        ?DateTimeImmutable $dischargeDate = null,
        ?string $bedNumber = null,
        ?string $boxNumber = null
    ): EpisodeOfCare {
        return new EpisodeOfCare(
            idEpisodeOfCare: $id,
            idPatient: $idPatient,
            episodeNumber: sprintf('2026-%06d', $id),
            admissionDate: new DateTimeImmutable('-2 days'),
            dischargeDate: $dischargeDate,
            bedNumber: $bedNumber,
            boxNumber: $boxNumber
        );
    }
}
