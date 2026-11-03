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
 * AURA-10.3 : ouverture, emplacement et clôture d'un séjour.
 * Le numéro de séjour lui-même est généré par EpisodeOfCareDao (cf. EpisodeOfCareDaoTest).
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

    public function testOpenEpisodeInsertsEpisodeWithoutNumberOrDischarge(): void
    {
        $this->dao->method('findByPatient')->with(5)->willReturn([]);
        $this->dao->method('findOngoing')->willReturn([]);
        $this->dao->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (EpisodeOfCare $s): bool {
                $this->assertNull($s->getIdEpisodeOfCare());
                $this->assertSame(5, $s->getIdPatient());
                $this->assertSame('', $s->getEpisodeNumber(), 'le numéro est attribué par le DAO, jamais saisi');
                $this->assertNull($s->getDischargeDate());
                $this->assertTrue($s->isOngoing());
                $this->assertEqualsWithDelta(time(), $s->getAdmissionDate()->getTimestamp(), 5);
                $this->assertSame('L1', $s->getBedNumber());
                $this->assertNull($s->getBoxNumber(), 'un box vide devient null');

                return true;
            }))
            ->willReturnCallback(fn(EpisodeOfCare $s) => $this->episodeOfCare(10, 5, bedNumber: $s->getBedNumber()));

        $episodeOfCare = $this->service->openEpisodeOfCare(5, ' L1 ', '  ');

        $this->assertSame(10, $episodeOfCare->getIdEpisodeOfCare());
    }

    /** AURA-10.1 : les mesures de l'admission sont portées par le séjour inséré. */
    public function testOpenEpisodePassesMeasuresToDao(): void
    {
        $this->dao->method('findByPatient')->willReturn([]);
        $this->dao->method('findOngoing')->willReturn([]);
        $this->dao->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (EpisodeOfCare $s): bool {
                $this->assertSame(68.5, $s->getWeight());
                $this->assertSame(172.0, $s->getHeight());
                $this->assertSame('Isolement contact', $s->getPrecautions());

                return true;
            }))
            ->willReturn($this->episodeOfCare(10, 5));

        $this->service->openEpisodeOfCare(5, weight: 68.5, height: 172.0, precautions: 'Isolement contact');
    }

    public function testOpenEpisodeRejectedIfPatientAlreadyPresent(): void
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

    public function testDischargedPatientCanComeBack(): void
    {
        $this->dao->method('findByPatient')->with(5)->willReturn([
            $this->episodeOfCare(1, 5, dischargeDate: new DateTimeImmutable('-1 day')),
        ]);
        $this->dao->method('findOngoing')->willReturn([]);
        $this->dao->expects($this->once())->method('insert')->willReturn($this->episodeOfCare(2, 5));

        $this->assertSame(2, $this->service->openEpisodeOfCare(5)->getIdEpisodeOfCare());
    }

    public function testOpenEpisodeRejectsOccupiedBed(): void
    {
        $this->dao->method('findByPatient')->willReturn([]);
        $this->dao->method('findOngoing')->willReturn([$this->episodeOfCare(1, 9, bedNumber: 'L1')]);
        $this->dao->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lit L1 est déjà occupé');

        $this->service->openEpisodeOfCare(5, 'L1');
    }

    public function testOpenEpisodeRejectsOccupiedBox(): void
    {
        $this->dao->method('findByPatient')->willReturn([]);
        $this->dao->method('findOngoing')->willReturn([$this->episodeOfCare(1, 9, boxNumber: 'B2')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('box B2 est déjà occupé');

        $this->service->openEpisodeOfCare(5, null, 'B2');
    }

    public function testTooLongBedNumberIsRejected(): void
    {
        $this->dao->method('findByPatient')->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('20 caractères');

        $this->service->openEpisodeOfCare(5, str_repeat('L', 21));
    }

    public function testChangeLocationIgnoresTheEpisodeItself(): void
    {
        $episodeOfCare = $this->episodeOfCare(1, 5, bedNumber: 'L1', boxNumber: 'B1');
        $this->dao->method('findById')->with(1)->willReturn($episodeOfCare);
        $this->dao->method('findOngoing')->willReturn([$episodeOfCare]);
        $this->dao->expects($this->once())->method('updateLocation')->with(1, 'L1', 'B2');

        $this->service->changeLocation(1, 'L1', 'B2');
    }

    public function testChangeLocationOfEndedEpisodeIsRejected(): void
    {
        $this->dao->method('findById')->with(1)
            ->willReturn($this->episodeOfCare(1, 5, dischargeDate: new DateTimeImmutable('-1 hour')));
        $this->dao->expects($this->never())->method('updateLocation');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('terminé');

        $this->service->changeLocation(1, 'L2', null);
    }

    public function testCloseEpisodeRecordsDischarge(): void
    {
        $this->dao->method('findById')->with(1)->willReturnOnConsecutiveCalls(
            $this->episodeOfCare(1, 5),
            $this->episodeOfCare(1, 5, dischargeDate: new DateTimeImmutable())
        );
        $this->dao->expects($this->once())
            ->method('updateDischarge')
            ->with(1, $this->isInstanceOf(DateTimeImmutable::class));

        $this->assertFalse($this->service->closeEpisodeOfCare(1)->isOngoing());
    }

    public function testClosingTwiceIsRejected(): void
    {
        $this->dao->method('findById')
            ->willReturn($this->episodeOfCare(1, 5, dischargeDate: new DateTimeImmutable('-1 hour')));
        $this->dao->expects($this->never())->method('updateDischarge');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('déjà terminé');

        $this->service->closeEpisodeOfCare(1);
    }

    public function testDischargeBeforeAdmissionIsRejected(): void
    {
        $this->dao->method('findById')->willReturn($this->episodeOfCare(1, 5));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('précéder');

        $this->service->closeEpisodeOfCare(1, new DateTimeImmutable('2000-01-01'));
    }

    public function testDischargeInTheFutureIsRejected(): void
    {
        $this->dao->method('findById')->willReturn($this->episodeOfCare(1, 5));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('futur');

        $this->service->closeEpisodeOfCare(1, new DateTimeImmutable('+1 day'));
    }

    public function testGetUnknownEpisodeIsRejected(): void
    {
        $this->dao->method('findById')->with(99)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Séjour 99 introuvable');

        $this->service->getEpisodeOfCare(99);
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
