<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;
use Aura\Service\PatientListService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AURA-12.1 : liste des patients, sans base (DAO simulés). Retours des médecins du 2026-11-03 : dossier
 * toujours accessible (séjour en cours, sinon dernier séjour), historique, séjour courant, décès.
 */
final class PatientListServiceTest extends TestCase
{
    private PatientDao&MockObject $patients;
    private EpisodeOfCareDao&MockObject $episodesOfCare;
    private PatientListService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->episodesOfCare = $this->createMock(EpisodeOfCareDao::class);
        $references = $this->createMock(ReferenceDataDao::class);
        $references->method('healthcareServices')->willReturn(['SRV01' => 'Réanimation médicale']);
        $this->service = new PatientListService($this->patients, $this->episodesOfCare, $references);
    }

    public function testAllPatientsAppearWithOrWithoutEpisodeAndEpisodesAreReadOnce(): void
    {
        $this->patients->method('findAll')->willReturn([
            self::patient(1, 'Dupont', 'Marie', '100000001', '1958-03-21'),
            self::patient(2, 'Martin', 'Jean', null, null),
        ]);
        $this->patients->expects($this->never())->method('search');
        $this->episodesOfCare->expects($this->once())->method('findByPatients')->with([1, 2])->willReturn([
            self::episode(10, 1, '2026-000101', '2026-10-12 08:15', null, bed: 'L01', service: 'SRV01'),
        ]);

        $lines = $this->service->listAll(null);

        self::assertCount(2, $lines);
        self::assertSame(
            ['idPatient' => 1, 'lastName' => 'Dupont', 'firstName' => 'Marie', 'ipp' => '100000001',
                'birthDate' => '21/03/1958', 'deceased' => false, 'deceasedLabel' => null],
            array_slice($lines[0], 0, 7)
        );
        self::assertSame([
            'idEpisodeOfCare' => 10, 'episodeNumber' => '2026-000101', 'bed' => 'L01', 'box' => null,
            'service' => 'Réanimation médicale', 'since' => '12/10/2026',
        ], $lines[0]['current'], 'séjour en cours mis en avant, nom du service traduit');
        self::assertSame(10, $lines[0]['record']['idEpisodeOfCare']);
        self::assertNull($lines[1]['current']);
        self::assertNull($lines[1]['record'], 'aucun séjour : pas de dossier, pas de lien mort');
        self::assertSame([], $lines[1]['history']);
    }

    public function testSearchIsDelegatedToDaoAfterCleaning(): void
    {
        $this->patients->expects($this->never())->method('findAll');
        $this->patients->expects($this->once())
            ->method('search')
            ->with('dup')
            ->willReturn([self::patient(1, 'Dupont', 'Marie', null, null)]);
        $this->episodesOfCare->method('findByPatients')->willReturn([]);

        $lines = $this->service->listAll('  dup ');

        self::assertCount(1, $lines);
        self::assertSame('Dupont', $lines[0]['lastName']);
        self::assertNull($lines[0]['record']);
    }

    public function testEmptySearchListsEveryoneWithoutReadingEpisodesWhenNobody(): void
    {
        $this->patients->expects($this->once())->method('findAll')->willReturn([]);
        $this->patients->expects($this->never())->method('search');
        $this->episodesOfCare->expects($this->never())->method('findByPatients');

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

    /**
     * Dossier ouvert depuis la liste : le séjour en cours s'il existe, sinon le dernier séjour (clos).
     *
     * @return iterable<string, array{list<EpisodeOfCare>, ?int, ?int, ?bool, int}>
     */
    public static function recordCases(): iterable
    {
        $closedOld = self::episode(7, 1, '2026-000107', '2026-06-02 10:00', '2026-06-05 16:00');
        $closedRecent = self::episode(4, 1, '2026-000104', '2026-10-01 03:20', '2026-10-08 11:00');
        $ongoing = self::episode(1, 1, '2026-000101', '2026-10-10 08:15', null);

        yield 'aucun séjour' => [[], null, null, null, 0];
        yield 'séjour en cours seul' => [[$ongoing], 1, 1, true, 1];
        yield 'séjours clos : le plus récent' => [[$closedOld, $closedRecent], null, 4, false, 2];
        yield 'en cours prioritaire sur un clos' => [[$closedRecent, $ongoing, $closedOld], 1, 1, true, 3];
    }

    /** @param list<EpisodeOfCare> $episodes */
    #[DataProvider('recordCases')]
    public function testRecordLinkLeadsToOngoingOtherwiseLastEpisode(
        array $episodes,
        ?int $currentId,
        ?int $recordId,
        ?bool $recordOngoing,
        int $historyCount
    ): void {
        $line = PatientListService::presentLine(self::patient(1, 'Simpson', 'Marge', null, null), $episodes);

        self::assertSame($currentId, $line['current']['idEpisodeOfCare'] ?? null);
        self::assertSame($recordId, $line['record']['idEpisodeOfCare'] ?? null);
        self::assertSame($recordOngoing, $line['record']['ongoing'] ?? null);
        self::assertCount($historyCount, $line['history']);
    }

    public function testClosedRecordCarriesDischargeDateAndHistoryIsMostRecentFirst(): void
    {
        $line = PatientListService::presentLine(self::patient(1, 'Simpson', 'Marge', null, null), [
            self::episode(7, 1, '2026-000107', '2026-06-02 10:00', '2026-06-05 16:00'),
            self::episode(4, 1, '2026-000104', '2026-10-01 03:20', '2026-10-08 11:00'),
        ]);

        self::assertSame('08/10/2026', $line['record']['dischargeDate']);
        self::assertSame([4, 7], array_column($line['history'], 'idEpisodeOfCare'));
        self::assertSame([false, false], array_column($line['history'], 'displayed'), 'aucun séjour affiché');
    }

    /** @return iterable<string, array{?string, ?string, bool, ?string}> */
    public static function deceasedCases(): iterable
    {
        yield 'décédée' => ['1', 'F', true, 'Décédée'];
        yield 'décédé' => ['1', 'M', true, 'Décédé'];
        yield 'sexe inconnu' => ['1', null, true, 'Décédé(e)'];
        yield 'vivant' => ['0', 'F', false, null];
        yield 'non renseigné' => [null, 'M', false, null];
    }

    #[DataProvider('deceasedCases')]
    public function testDeceasedIsShownWithLabelMatchingSex(
        ?string $deceased,
        ?string $sex,
        bool $expected,
        ?string $label
    ): void {
        $patient = new Patient(5, null, 'Fletcher', 'Jessica', null, $sex, details: ['deceased' => $deceased]);

        $line = PatientListService::presentLine($patient, []);

        self::assertSame($expected, $line['deceased']);
        self::assertSame($label, $line['deceasedLabel']);
    }

    private static function patient(int $id, string $lastName, string $firstName, ?string $ipp, ?string $birth): Patient
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

    private static function episode(
        int $id,
        int $idPatient,
        string $number,
        string $admission,
        ?string $discharge,
        ?string $bed = null,
        ?string $service = null
    ): EpisodeOfCare {
        return new EpisodeOfCare(
            $id,
            $idPatient,
            $number,
            new DateTimeImmutable($admission),
            $discharge !== null ? new DateTimeImmutable($discharge) : null,
            bedNumber: $bed,
            idService: $service
        );
    }
}
