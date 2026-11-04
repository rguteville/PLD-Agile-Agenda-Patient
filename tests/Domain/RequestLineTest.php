<?php

declare(strict_types=1);

namespace Aura\Tests\Domain;

use Aura\Domain\MedicationRequest;
use Aura\Domain\RequestLine;
use Aura\Domain\ServiceRequest;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Heures des soins planifiés déduites d'une ligne de prescription (RG6), sans base. À l'enregistrement,
 * la génération s'arrête à 7 jours ; la génération quotidienne fait la suite.
 */
final class RequestLineTest extends TestCase
{
    public function testOneOffLineGivesASingleCareAtItsStart(): void
    {
        $line = new ServiceRequest(null, 'TYP03', new DateTimeImmutable('2026-10-11 06:00'));

        self::assertEquals([new DateTimeImmutable('2026-10-11 06:00')], $line->plannedTimes());
    }

    public function testRecurringLineRunsEveryNHoursUntilItsStopDateIncluded(): void
    {
        $line = new MedicationRequest(
            null,
            'TYP01',
            'MED01',
            '1 g',
            'IV',
            new DateTimeImmutable('2026-10-10 12:00'),
            new DateTimeImmutable('2026-10-11 12:00'),
            6
        );

        $times = array_map(static fn (DateTimeImmutable $t): string => $t->format('Y-m-d H:i'), $line->plannedTimes());

        self::assertSame(
            ['2026-10-10 12:00', '2026-10-10 18:00', '2026-10-11 00:00', '2026-10-11 06:00', '2026-10-11 12:00'],
            $times
        );
    }

    public function testLineWithoutStopDateIsPlannedSevenDaysAhead(): void
    {
        $line = new ServiceRequest(null, 'TYP02', new DateTimeImmutable('2026-10-12 08:00'), frequencyHours: 24);

        $times = $line->plannedTimes();

        self::assertCount(RequestLine::PLANNING_HORIZON_DAYS + 1, $times, 'début inclus, horizon inclus');
        self::assertEquals(new DateTimeImmutable('2026-10-19 08:00'), end($times));
    }

    /**
     * Relecture adversariale du 2026-11-04 : une ligne longue (« toutes les heures pendant un an ») ne crée à
     * l'enregistrement que l'horizon de génération ; la génération quotidienne fait le reste.
     */
    public function testLongLineIsPlannedOnlySevenDaysAhead(): void
    {
        $line = new ServiceRequest(
            null,
            'TYP02',
            new DateTimeImmutable('2026-10-12 08:00'),
            new DateTimeImmutable('2027-10-12 08:00'),
            1
        );

        $times = $line->plannedTimes();

        self::assertCount(RequestLine::PLANNING_HORIZON_DAYS * 24 + 1, $times);
        self::assertEquals(new DateTimeImmutable('2026-10-19 08:00'), end($times));
    }
}
