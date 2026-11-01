<?php

declare(strict_types=1);

namespace Aura\Tests\View;

use Aura\Core\View;
use PHPUnit\Framework\TestCase;

/**
 * AURA-UI : rendu des composants partagés (icône, badge de statut, état vide, bandeau patient),
 * sans base ni serveur. Les données sont des substituts évidents (« DUPONT Test »).
 */
final class ComponentsRenderTest extends TestCase
{
    public function testIconeDecorativeEstCacheeAuxLecteursDEcran(): void
    {
        $html = View::render('components/icon', ['name' => 'clock']);

        self::assertStringContainsString('class="icon icon-clock"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('<circle cx="12" cy="12" r="10"/>', $html);
    }

    public function testIconePorteuseDeSensALabelEchappe(): void
    {
        $html = View::render('components/icon', ['name' => 'triangle-alert', 'label' => 'Allergie <sévère>']);

        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('aria-label="Allergie &lt;sévère&gt;"', $html);
    }

    public function testIconeInconnueRetombeSurInfo(): void
    {
        $html = View::render('components/icon', ['name' => 'inexistante']);

        self::assertStringContainsString('icon-info', $html);
        self::assertStringNotContainsString('inexistante', $html);
    }

    public function testBadgeConnuAIconeTexteEtClasse(): void
    {
        $html = View::render('components/status_badge', ['status' => 'late']);

        self::assertStringContainsString('badge badge--late', $html);
        self::assertStringContainsString('icon-clock-alert', $html);
        self::assertStringContainsString('<span>En retard</span>', $html);
    }

    public function testBadgeLibelleRemplaceEtEchappe(): void
    {
        $html = View::render('components/status_badge', ['status' => 'validated', 'label' => 'Validée par <X>']);

        self::assertStringContainsString('badge--validated', $html);
        self::assertStringContainsString('Validée par &lt;X&gt;', $html);
    }

    public function testBadgeStatutInconnuAfficheLaValeurEnNeutre(): void
    {
        $html = View::render('components/status_badge', ['status' => 'NOUVEAU']);

        self::assertStringContainsString('badge--unknown', $html);
        self::assertStringContainsString('<span>NOUVEAU</span>', $html);
        self::assertStringContainsString('icon-circle-alert', $html);
    }

    public function testEtatVideAvecTitreEtTexte(): void
    {
        $html = View::render('components/empty_state', ['title' => 'Rien <ici>', 'text' => 'Explication']);

        self::assertStringContainsString('class="empty-state"', $html);
        self::assertStringContainsString('Rien &lt;ici&gt;', $html);
        self::assertStringContainsString('Explication', $html);
        self::assertStringContainsString('icon-inbox', $html);
    }

    public function testBandeauSansAllergieAffichelEtatExplicite(): void
    {
        $html = View::render('components/bandeau_patient', ['bandeau' => self::bandeau()]);

        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('Aucune allergie connue', $html);
        self::assertStringNotContainsString('Allergies :', $html);
        self::assertStringContainsString('DUPONT', $html);
        self::assertStringContainsString('62,5 kg', $html);
        self::assertStringContainsString('non renseignée', $html, 'taille absente');
    }

    public function testBandeauAvecAllergiesLesListeAvecAlerte(): void
    {
        $html = View::render('components/bandeau_patient', ['bandeau' => self::bandeau([
            ['libelle' => 'Substance <A>', 'criticite' => 'sévère'],
            ['libelle' => 'Substance B', 'criticite' => null],
        ])]);

        self::assertStringContainsString('Allergies :', $html);
        self::assertStringContainsString('icon-triangle-alert', $html);
        self::assertStringContainsString('Substance &lt;A&gt;', $html);
        self::assertStringContainsString('(sévère)', $html);
        self::assertStringNotContainsString('Aucune allergie connue', $html);
    }

    /** @return array<string, mixed> */
    public static function bandeau(array $allergies = []): array
    {
        return [
            'nom' => 'DUPONT',
            'prenom' => 'Test',
            'sexe' => 'F',
            'dateNaissance' => '01/01/1980',
            'age' => 46,
            'ipp' => '123456',
            'numeroSejour' => '2026-000001',
            'dateAdmission' => '14/10/2026 08:00',
            'poids' => '62,5 kg',
            'taille' => null,
            'allergies' => $allergies,
        ];
    }
}
