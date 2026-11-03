<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;
use Aura\Service\PatientListService;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** AURA-12.1 : liste des patients avec leur séjour en cours, sans base (DAO simulés). */
final class PatientListServiceTest extends TestCase
{
    private PatientDao&MockObject $patients;
    private EpisodeOfCareDao&MockObject $episodesOfCare;
    private PatientListService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->episodesOfCare = $this->createMock(EpisodeOfCareDao::class);
        $this->service = new PatientListService($this->patients, $this->episodesOfCare);
    }

    public function testAllPatientsAppearWithOrWithoutOngoingEpisode(): void
    {
        $this->patients->method('findAll')->willReturn([
            $this->patient(1, 'Dupont', 'Marie', '100000001', '1958-03-21'),
            $this->patient(2, 'Martin', 'Jean', null, null),
        ]);
        $this->patients->expects($this->never())->method('search');
        $this->episodesOfCare->method('findOngoing')->willReturn([
            $this->episodeOfCare(idEpisodeOfCare: 10, idPatient: 1, number: '2026-000101'),
            $this->episodeOfCare(idEpisodeOfCare: 11, idPatient: 99, number: '2026-000199'),
        ]);

        $lines = $this->service->listAll(null);

        self::assertSame([
            [
                'idPatient' => 1,
                'lastName' => 'Dupont',
                'firstName' => 'Marie',
                'ipp' => '100000001',
                'birthDate' => '21/03/1958',
                'idEpisodeOfCare' => 10,
                'episodeNumber' => '2026-000101',
            ],
            [
                'idPatient' => 2,
                'lastName' => 'Martin',
                'firstName' => 'Jean',
                'ipp' => null,
                'birthDate' => null,
                'idEpisodeOfCare' => null,
                'episodeNumber' => null,
            ],
        ], $lines);
    }

    public function testSearchIsDelegatedToDaoAfterCleaning(): void
    {
        $this->patients->expects($this->never())->method('findAll');
        $this->patients->expects($this->once())
            ->method('search')
            ->with('dup')
            ->willReturn([$this->patient(1, 'Dupont', 'Marie', null, null)]);
        $this->episodesOfCare->method('findOngoing')->willReturn([]);

        $lines = $this->service->listAll('  dup ');

        self::assertCount(1, $lines);
        self::assertSame('Dupont', $lines[0]['lastName']);
        self::assertNull($lines[0]['idEpisodeOfCare']);
    }

    public function testEmptySearchListsEveryone(): void
    {
        $this->patients->expects($this->once())->method('findAll')->willReturn([]);
        $this->patients->expects($this->never())->method('search');
        $this->episodesOfCare->method('findOngoing')->willReturn([]);

        self::assertSame([], $this->service->listAll('   '));
    }

    public function testSearchTermIsTruncated(): void
    {
        $long = str_repeat('a', PatientListService::SEARCH_MAX_LENGTH + 10);

        self::assertSame(
            str_repeat('a', PatientListService::SEARCH_MAX_LENGTH),
            PatientListService::cleanSearch($long)
        );
        self::assertNull(PatientListService::cleanSearch(null));
        self::assertNull(PatientListService::cleanSearch(''));
    }

    private function patient(int $id, string $lastName, string $firstName, ?string $ipp, ?string $birth): Patient
    {
        return new Patient(
            $id,
            $ipp,
            $lastName,
            $firstName,
            $birth !== null ? new DateTimeImmutable($birth) : null,
            'F'
        );
    }

    private function episodeOfCare(int $idEpisodeOfCare, int $idPatient, string $number): EpisodeOfCare
    {
        return new EpisodeOfCare($idEpisodeOfCare, $idPatient, $number, new DateTimeImmutable('2026-10-12 08:15:00'));
    }
}
