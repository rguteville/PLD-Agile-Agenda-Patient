<?php

declare(strict_types=1);

namespace Aura\Tests\Domain;

use Aura\Domain\MedicationRequest;
use Aura\Domain\RequestLine;
use Aura\Domain\ServiceRequest;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * AURA-24.4 : heures des soins planifiés déduites d'une ligne de prescription (RG3),
 * sans accès à la base.
 */
final class RequestLineTest extends TestCase
{
    public function testUneFoisDonneUnSeulSoinAuDebut(): void
    {
        $line = new ServiceRequest(null, 'TYP03', new DateTimeImmutable('2026-10-11 06:00'));

        self::assertEquals([new DateTimeImmutable('2026-10-11 06:00')], $line->plannedTimes());
    }

    public function testToutesLesNHeuresJusquaLaDateDArretIncluse(): void
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

    public function testDateDArretAvantLaProchaineOccurrence(): void
    {
        $line = new ServiceRequest(
            null,
            'TYP04',
            new DateTimeImmutable('2026-10-11 10:00'),
            new DateTimeImmutable('2026-10-12 10:00'),
            48
        );

        self::assertCount(1, $line->plannedTimes(), 'la 2e occurrence (13/10) dépasse la date d\'arrêt');
    }

    public function testSansDateDArretJusquaLHorizon(): void
    {
        $line = new ServiceRequest(null, 'TYP02', new DateTimeImmutable('2026-10-12 08:00'), frequencyHours: 24);

        $times = $line->plannedTimes();

        self::assertCount(RequestLine::PLANNING_HORIZON_DAYS + 1, $times, 'début inclus, horizon inclus');
        self::assertEquals(new DateTimeImmutable('2026-10-19 08:00'), end($times));
    }
}
