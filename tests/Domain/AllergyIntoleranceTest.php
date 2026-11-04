<?php

declare(strict_types=1);

namespace Aura\Tests\Domain;

use Aura\Domain\AllergyIntolerance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Retours du 2026-11-02 : niveau d'affichage d'une allergie selon sa criticité. */
final class AllergyIntoleranceTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function criticalities(): iterable
    {
        yield 'élevée' => ['Élevée', 'high'];
        yield 'élevée avec espaces' => [' Élevée ', 'high'];
        yield 'faible' => ['Faible', 'low'];
        yield 'non évaluée' => [null, 'unknown'];
        yield 'empty' => ['', 'unknown'];
        yield 'valeur inattendue traitée avec prudence' => ['sévère', 'unknown'];
    }

    #[DataProvider('criticalities')]
    public function testDisplayLevelByCriticality(?string $criticality, string $level): void
    {
        self::assertSame($level, AllergyIntolerance::criticalityLevel($criticality));
    }
}
