<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Domain\ServiceRequest;
use Aura\Service\AgendaGenerationService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Génération quotidienne de l'agenda (RG6, RG9), règles pures : heures attendues d'une ligne entre
 * maintenant et l'horizon, heures encore manquantes, horizon de 7 jours.
 */
final class AgendaGenerationServiceTest extends TestCase
{
    /** @return iterable<string, array{ServiceRequest, list<string>}> */
    public static function lines(): iterable
    {
        yield 'soin unique déjà passé : rien (RG9, pas de recalcul rétroactif)' => [
            new ServiceRequest(1, 'TYP03', new DateTimeImmutable('2026-10-11 06:00')),
            [],
        ];
        yield 'soin unique au-delà de l\'horizon : rien' => [
            new ServiceRequest(1, 'TYP03', new DateTimeImmutable('2026-10-17 06:00')),
            [],
        ];
        yield 'récurrente commencée avant : mêmes heures, à partir de maintenant' => [
            new ServiceRequest(1, 'TYP02', new DateTimeImmutable('2026-10-12 10:00'), frequencyHours: 12),
            ['2026-10-14 10:00', '2026-10-14 22:00', '2026-10-15 10:00', '2026-10-15 22:00',
                '2026-10-16 10:00', '2026-10-16 22:00'],
        ];
        yield 'ligne arrêtée : plus aucune heure' => [
            new ServiceRequest(
                1,
                'TYP02',
                new DateTimeImmutable('2026-10-12 10:00'),
                frequencyHours: 4,
                idStoppedBy: 'PRA01',
                stoppedAt: new DateTimeImmutable('2026-10-13 08:00')
            ),
            [],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('lines')]
    public function testExpectedTimesStayBetweenNowAndTheHorizon(ServiceRequest $line, array $expected): void
    {
        $times = AgendaGenerationService::expectedTimes(
            $line,
            new DateTimeImmutable('2026-10-14 09:00'),
            new DateTimeImmutable('2026-10-16 23:59:59')
        );

        self::assertSame($expected, self::format($times));
    }

    public function testOnlyMissingTimesAreCreated(): void
    {
        $expected = self::dates(['2026-10-14 10:00', '2026-10-14 22:00', '2026-10-15 10:00']);
        $existing = self::dates(['2026-10-14 22:00']);

        self::assertSame(
            ['2026-10-14 10:00', '2026-10-15 10:00'],
            self::format(AgendaGenerationService::missingTimes($expected, $existing))
        );
        self::assertSame([], AgendaGenerationService::missingTimes($expected, $expected), 'deuxième génération');
    }

    public function testHorizonIsTheEndOfTheSeventhDay(): void
    {
        $end = AgendaGenerationService::horizonEnd(new DateTimeImmutable('2026-10-14 09:00'), 7);

        self::assertSame('2026-10-21 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    /**
     * @param DateTimeImmutable[] $times
     * @return list<string>
     */
    private static function format(array $times): array
    {
        return array_values(array_map(static fn (DateTimeImmutable $t): string => $t->format('Y-m-d H:i'), $times));
    }

    /**
     * @param list<string> $times
     * @return list<DateTimeImmutable>
     */
    private static function dates(array $times): array
    {
        return array_map(static fn (string $t): DateTimeImmutable => new DateTimeImmutable($t), $times);
    }
}
