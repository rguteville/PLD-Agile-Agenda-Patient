<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Domain\ServiceRequest;
use Aura\Service\AgendaGenerationService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AURA-24.2 et 24.3 : règles pures de la génération quotidienne de l'agenda (heures attendues d'une
 * ligne dans une plage, heures manquantes, horizon), sans base.
 */
final class AgendaGenerationServiceTest extends TestCase
{
    private const NOW = '2026-10-14 09:00';
    private const UNTIL = '2026-10-16 23:59:59';

    /**
     * @return iterable<string, array{0: ServiceRequest, 1: list<string>}>
     */
    public static function linesProvider(): iterable
    {
        yield 'unique, déjà passé : rien (RG9, pas de recalcul rétroactif)' => [
            new ServiceRequest(1, 'TYP03', new DateTimeImmutable('2026-10-11 06:00')),
            [],
        ];
        yield 'unique, dans la plage' => [
            new ServiceRequest(1, 'TYP03', new DateTimeImmutable('2026-10-15 06:00')),
            ['2026-10-15 06:00'],
        ];
        yield 'unique, au-delà de l\'horizon' => [
            new ServiceRequest(1, 'TYP03', new DateTimeImmutable('2026-10-17 06:00')),
            [],
        ];
        yield 'récurrente commencée avant : heures comptées depuis le début, seulement à partir de maintenant' => [
            new ServiceRequest(1, 'TYP02', new DateTimeImmutable('2026-10-12 10:00'), frequencyHours: 12),
            ['2026-10-14 10:00', '2026-10-14 22:00', '2026-10-15 10:00', '2026-10-15 22:00',
                '2026-10-16 10:00', '2026-10-16 22:00'],
        ];
        yield 'récurrente, date de fin avant l\'horizon (incluse)' => [
            new ServiceRequest(
                1,
                'TYP02',
                new DateTimeImmutable('2026-10-14 08:00'),
                new DateTimeImmutable('2026-10-15 08:00'),
                8
            ),
            ['2026-10-14 16:00', '2026-10-15 00:00', '2026-10-15 08:00'],
        ];
        yield 'récurrente terminée avant maintenant' => [
            new ServiceRequest(
                1,
                'TYP02',
                new DateTimeImmutable('2026-10-10 08:00'),
                new DateTimeImmutable('2026-10-13 08:00'),
                24
            ),
            [],
        ];
        yield 'récurrente qui commence après l\'horizon' => [
            new ServiceRequest(1, 'TYP02', new DateTimeImmutable('2026-10-20 08:00'), frequencyHours: 24),
            [],
        ];
        yield 'heure égale à maintenant : incluse' => [
            new ServiceRequest(1, 'TYP02', new DateTimeImmutable('2026-10-13 09:00'), frequencyHours: 48),
            ['2026-10-15 09:00'],
        ];
        yield 'ligne arrêtée (AURA-22) : plus aucune heure' => [
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
    #[DataProvider('linesProvider')]
    public function testExpectedTimesWithinRange(ServiceRequest $line, array $expected): void
    {
        $times = AgendaGenerationService::expectedTimes(
            $line,
            new DateTimeImmutable(self::NOW),
            new DateTimeImmutable(self::UNTIL)
        );

        self::assertSame($expected, self::format($times));
    }

    public function testExpectedTimesMatchThoseOfPrescriptionRecording(): void
    {
        // Même algorithme que l'enregistrement (RequestLine::plannedTimes()) : mêmes heures, donc pas de doublon.
        $line = new ServiceRequest(1, 'TYP02', new DateTimeImmutable('2026-10-14 07:30'), frequencyHours: 5);

        $generated = AgendaGenerationService::expectedTimes(
            $line,
            new DateTimeImmutable('2026-10-14 07:30'),
            new DateTimeImmutable('2026-10-21 07:30')
        );

        self::assertSame(self::format($line->plannedTimes()), self::format($generated));
    }

    public function testEmptyRangeGivesNothing(): void
    {
        $line = new ServiceRequest(1, 'TYP02', new DateTimeImmutable('2026-10-14 08:00'), frequencyHours: 1);

        self::assertSame([], AgendaGenerationService::expectedTimes(
            $line,
            new DateTimeImmutable('2026-10-15 00:00'),
            new DateTimeImmutable('2026-10-14 00:00')
        ));
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: list<string>, 2: list<string>}>
     */
    public static function missingProvider(): iterable
    {
        yield 'rien d\'existant : tout est à créer' => [
            ['2026-10-14 10:00', '2026-10-14 22:00'],
            [],
            ['2026-10-14 10:00', '2026-10-14 22:00'],
        ];
        yield 'tout existe déjà : rien à créer (2e génération)' => [
            ['2026-10-14 10:00', '2026-10-14 22:00'],
            ['2026-10-14 22:00', '2026-10-14 10:00'],
            [],
        ];
        yield 'seules les heures absentes, dans l\'ordre' => [
            ['2026-10-14 10:00', '2026-10-14 22:00', '2026-10-15 10:00'],
            ['2026-10-14 22:00'],
            ['2026-10-14 10:00', '2026-10-15 10:00'],
        ];
        yield 'une heure attendue deux fois n\'est créée qu\'une fois' => [
            ['2026-10-14 10:00', '2026-10-14 10:00'],
            [],
            ['2026-10-14 10:00'],
        ];
        yield 'soins existants hors plage : sans effet' => [
            ['2026-10-14 10:00'],
            ['2026-10-01 10:00'],
            ['2026-10-14 10:00'],
        ];
    }

    /**
     * @param list<string> $expected
     * @param list<string> $existing
     * @param list<string> $missing
     */
    #[DataProvider('missingProvider')]
    public function testMissingTimesSkipAlreadyPlannedOnes(array $expected, array $existing, array $missing): void
    {
        self::assertSame($missing, self::format(AgendaGenerationService::missingTimes(
            self::dates($expected),
            self::dates($existing)
        )));
    }

    public function testMissingTimesCompareToTheSecond(): void
    {
        $missing = AgendaGenerationService::missingTimes(
            [new DateTimeImmutable('2026-10-14 10:00:30')],
            [new DateTimeImmutable('2026-10-14 10:00:00')]
        );

        self::assertCount(1, $missing, '10:00:30 et 10:00:00 sont deux heures différentes, comme en base');
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: string}>
     */
    public static function horizonProvider(): iterable
    {
        yield 'J + 7, fin de journée' => ['2026-10-14 09:00', 7, '2026-10-21 23:59:59'];
        yield 'lancé juste après minuit' => ['2026-10-14 00:05', 7, '2026-10-21 23:59:59'];
        yield 'horizon nul : fin du jour même' => ['2026-10-14 09:00', 0, '2026-10-14 23:59:59'];
        yield 'horizon négatif traité comme nul' => ['2026-10-14 09:00', -3, '2026-10-14 23:59:59'];
    }

    #[DataProvider('horizonProvider')]
    public function testHorizonEndIsEndOfDayPlusDays(string $now, int $days, string $end): void
    {
        self::assertSame(
            $end,
            AgendaGenerationService::horizonEnd(new DateTimeImmutable($now), $days)->format('Y-m-d H:i:s')
        );
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
