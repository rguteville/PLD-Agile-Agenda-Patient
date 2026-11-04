<?php

declare(strict_types=1);

namespace Aura\Tests\View;

use Aura\Core\View;
use PHPUnit\Framework\TestCase;

/**
 * Bandeau patient (RG2) rendu sans base ni serveur : allergies explicites, décès, échappement des données.
 * Données de substitution évidentes (« DUPONT Test »).
 */
final class ComponentsRenderTest extends TestCase
{
    public function testBannerWithoutAllergySaysNoKnownAllergy(): void
    {
        $html = View::render('components/patient_banner', ['banner' => self::banner()]);

        self::assertStringContainsString('DUPONT', $html);
        self::assertStringContainsString('Aucune allergie connue', $html);
    }

    public function testBannerListsAllergiesWithAnAlertAndEscapesThem(): void
    {
        $html = View::render('components/patient_banner', ['banner' => self::banner([
            ['label' => 'Substance <script>', 'criticality' => 'Élevée', 'level' => 'high'],
        ])]);

        self::assertStringContainsString('Allergies :', $html);
        self::assertStringContainsString('Substance &lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('Aucune allergie connue', $html);
    }

    public function testBannerShowsDeathAsText(): void
    {
        $html = View::render('components/patient_banner', [
            'banner' => ['deceased' => true, 'deceasedLabel' => 'Décédée'] + self::banner(),
        ]);

        self::assertStringContainsString('Décédée', $html, 'un texte, pas seulement une couleur');
    }

    /**
     * Bandeau minimal, comme PatientRecordService::presentBanner().
     *
     * @param list<array<string, ?string>> $allergies
     * @return array<string, mixed>
     */
    public static function banner(array $allergies = []): array
    {
        return [
            'lastName' => 'DUPONT',
            'firstName' => 'Test',
            'sex' => 'F',
            'birthDate' => '01/01/1980',
            'age' => 46,
            'ipp' => '123456',
            'episodeNumber' => '2026-000001',
            'admissionDate' => '14/10/2026 08:00',
            'weight' => '62,5 kg',
            'height' => null,
            'allergies' => $allergies,
        ];
    }
}
