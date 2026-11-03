<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\AgendaService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fin de la rotation en cours : limite de validation d'un soin fait en avance (retours du 2026-11-04). */
final class AgendaServiceShiftEndTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function instants(): array
    {
        return [
            'nuit, après minuit' => ['2026-10-14 00:30:00', '2026-10-14 06:00'],
            'dernière minute de la nuit' => ['2026-10-14 05:59:00', '2026-10-14 06:00'],
            'début du matin' => ['2026-10-14 06:00:00', '2026-10-14 14:00'],
            'après-midi' => ['2026-10-14 14:00:00', '2026-10-14 22:00'],
            'nuit, avant minuit' => ['2026-10-14 23:50:00', '2026-10-15 06:00'],
            'fin de mois' => ['2026-10-31 22:00:00', '2026-11-01 06:00'],
        ];
    }

    #[DataProvider('instants')]
    public function testShiftEndFollowsTheRotations(string $now, string $expected): void
    {
        self::assertSame($expected, AgendaService::shiftEnd(new DateTimeImmutable($now))->format('Y-m-d H:i'));
    }
}
