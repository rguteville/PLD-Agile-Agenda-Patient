<?php

declare(strict_types=1);

namespace Aura\Tests\Domain;

use Aura\Domain\Allergie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Retours du 2026-11-02 : niveau d'affichage d'une allergie selon sa criticité. */
final class AllergieTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function criticites(): iterable
    {
        yield 'élevée' => ['Élevée', 'high'];
        yield 'élevée avec espaces' => [' Élevée ', 'high'];
        yield 'faible' => ['Faible', 'low'];
        yield 'non évaluée' => [null, 'unknown'];
        yield 'vide' => ['', 'unknown'];
        yield 'valeur inattendue traitée avec prudence' => ['sévère', 'unknown'];
    }

    #[DataProvider('criticites')]
    public function testNiveauDAffichageSelonLaCriticite(?string $criticite, string $niveau): void
    {
        self::assertSame($niveau, Allergie::criticalityLevel($criticite));
    }
}
