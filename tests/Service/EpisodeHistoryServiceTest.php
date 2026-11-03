<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Domain\EpisodeOfCare;
use Aura\Service\EpisodeHistoryService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Retours des médecins du 2026-11-03 : historique des séjours d'un patient, séjour en cours et séjour
 * dont le dossier s'ouvre depuis la liste. Service pur, sans base.
 */
final class EpisodeHistoryServiceTest extends TestCase
{
    /** @return iterable<string, array{list<EpisodeOfCare>, list<int>}> */
    public static function orderCases(): iterable
    {
        $june = self::episode(7, '2026-06-02 10:00', '2026-06-05 16:00');
        $october = self::episode(1, '2026-10-10 08:15', null);
        $sameDayLowId = self::episode(3, '2026-10-10 08:15', '2026-10-11 08:00');

        yield 'déjà trié' => [[$october, $june], [1, 7]];
        yield 'ordre inverse' => [[$june, $october], [1, 7]];
        yield 'même admission : identifiant le plus grand d\'abord' => [[$october, $sameDayLowId, $june], [3, 1, 7]];
        yield 'aucun séjour' => [[], []];
    }

    /**
     * @param list<EpisodeOfCare> $episodes
     * @param list<int> $expectedIds
     */
    #[DataProvider('orderCases')]
    public function testHistoryIsMostRecentFirstWhateverTheReceivedOrder(array $episodes, array $expectedIds): void
    {
        self::assertSame($expectedIds, array_column(EpisodeHistoryService::present($episodes), 'idEpisodeOfCare'));
    }

    /** @return iterable<string, array{list<EpisodeOfCare>, ?int, ?int}> */
    public static function targetCases(): iterable
    {
        $closedOld = self::episode(7, '2026-06-02 10:00', '2026-06-05 16:00');
        $closedRecent = self::episode(4, '2026-10-01 03:20', '2026-10-08 11:00');
        $ongoing = self::episode(1, '2026-09-10 08:15', null);

        yield 'aucun séjour' => [[], null, null];
        yield 'clos seulement' => [[$closedOld, $closedRecent], null, 4];
        yield 'en cours, même plus ancien qu\'un clos' => [[$closedRecent, $ongoing, $closedOld], 1, 1];
    }

    /** @param list<EpisodeOfCare> $episodes */
    #[DataProvider('targetCases')]
    public function testOngoingAndRecordEpisode(array $episodes, ?int $ongoingId, ?int $recordId): void
    {
        self::assertSame($ongoingId, EpisodeHistoryService::ongoing($episodes)?->getIdEpisodeOfCare());
        self::assertSame($recordId, EpisodeHistoryService::recordEpisode($episodes)?->getIdEpisodeOfCare());
    }

    public function testPresentedEpisodeCarriesDatesServiceReasonAndDisplayedFlag(): void
    {
        $history = EpisodeHistoryService::present(
            [
                self::episode(7, '2026-06-02 10:00', '2026-06-05 16:00', 'SRV03', 'Décompensation respiratoire'),
                self::episode(1, '2026-10-10 08:15', null, 'SRVXX', '  '),
            ],
            ['SRV03' => 'Soins continus'],
            7
        );

        self::assertSame([
            'idEpisodeOfCare' => 1, 'episodeNumber' => '2026-000001', 'admissionDate' => '10/10/2026',
            'dischargeDate' => null, 'ongoing' => true, 'service' => 'SRVXX', 'admissionReason' => null,
            'displayed' => false,
        ], $history[0], 'service inconnu du référentiel : identifiant brut ; motif vide : null');
        self::assertSame([
            'idEpisodeOfCare' => 7, 'episodeNumber' => '2026-000007', 'admissionDate' => '02/06/2026',
            'dischargeDate' => '05/06/2026', 'ongoing' => false, 'service' => 'Soins continus',
            'admissionReason' => 'Décompensation respiratoire', 'displayed' => true,
        ], $history[1]);
    }

    public function testCurrentEpisodeHighlightsNumberBedServiceAndSince(): void
    {
        $episode = new EpisodeOfCare(
            1,
            1,
            '',
            new DateTimeImmutable('2026-10-10 08:15'),
            bedNumber: 'L01',
            boxNumber: ' ',
            idService: 'SRV01'
        );

        self::assertSame([
            'idEpisodeOfCare' => 1, 'episodeNumber' => '1', 'bed' => 'L01', 'box' => null,
            'service' => 'Réanimation médicale', 'since' => '10/10/2026',
        ], EpisodeHistoryService::presentCurrent($episode, ['SRV01' => 'Réanimation médicale']));
    }

    private static function episode(
        int $id,
        string $admission,
        ?string $discharge,
        ?string $service = null,
        ?string $reason = null
    ): EpisodeOfCare {
        return new EpisodeOfCare(
            $id,
            1,
            sprintf('2026-%06d', $id),
            new DateTimeImmutable($admission),
            $discharge !== null ? new DateTimeImmutable($discharge) : null,
            idService: $service,
            details: ['admissionReason' => $reason]
        );
    }
}
