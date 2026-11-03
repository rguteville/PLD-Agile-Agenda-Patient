<?php

declare(strict_types=1);

namespace Aura\Tests\Core;

use Aura\Core\DateInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Retours du 2026-11-02 : dates saisies en JJ/MM/AAAA, converties vers AAAA-MM-JJ à l'entrée. */
final class DateInputTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function saisies(): iterable
    {
        yield 'date française' => ['14/03/1958', '1958-03-14'];
        yield 'espaces' => [' 14/03/1958 ', '1958-03-14'];
        yield 'déjà au format ISO' => ['1958-03-14', '1958-03-14'];
        yield 'vide' => ['', ''];
        yield 'mal formée : laissée telle quelle pour que le service la refuse' => ['14-03-1958', '14-03-1958'];
        yield 'date inexistante : convertie, le service la refuse' => ['31/02/2026', '2026-02-31'];
    }

    #[DataProvider('saisies')]
    public function testConversionVersLeFormatDesServices(string $saisie, string $attendu): void
    {
        self::assertSame($attendu, DateInput::toIso($saisie));
    }

    public function testAffichageALaFrancaise(): void
    {
        self::assertSame('14/03/1958', DateInput::fromIso('1958-03-14'));
        self::assertSame('', DateInput::fromIso(null));
        self::assertSame('14/03/1958', DateInput::fromIso('14/03/1958'));
    }
}
