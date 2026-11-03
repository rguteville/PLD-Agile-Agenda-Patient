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
    public function testDecorativeIconIsHiddenFromScreenReaders(): void
    {
        $html = View::render('components/icon', ['name' => 'clock']);

        self::assertStringContainsString('class="icon icon-clock"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('<circle cx="12" cy="12" r="10"/>', $html);
    }

    public function testMeaningfulIconHasEscapedLabel(): void
    {
        $html = View::render('components/icon', ['name' => 'triangle-alert', 'label' => 'Allergie <sévère>']);

        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('aria-label="Allergie &lt;sévère&gt;"', $html);
    }

    public function testUnknownIconFallsBackToInfo(): void
    {
        $html = View::render('components/icon', ['name' => 'nonexistent']);

        self::assertStringContainsString('icon-info', $html);
        self::assertStringNotContainsString('nonexistent', $html);
    }

    public function testKnownBadgeHasIconTextAndClass(): void
    {
        $html = View::render('components/status_badge', ['status' => 'late']);

        self::assertStringContainsString('badge badge--late', $html);
        self::assertStringContainsString('icon-clock-alert', $html);
        self::assertStringContainsString('<span>En retard</span>', $html);
    }

    public function testBadgeLabelOverriddenAndEscaped(): void
    {
        $html = View::render('components/status_badge', ['status' => 'validated', 'label' => 'Validée par <X>']);

        self::assertStringContainsString('badge--validated', $html);
        self::assertStringContainsString('Validée par &lt;X&gt;', $html);
    }

    public function testUnknownStatusBadgeShowsValueAsNeutral(): void
    {
        $html = View::render('components/status_badge', ['status' => 'NOUVEAU']);

        self::assertStringContainsString('badge--unknown', $html);
        self::assertStringContainsString('<span>NOUVEAU</span>', $html);
        self::assertStringContainsString('icon-circle-alert', $html);
    }

    public function testEmptyStateWithTitleAndText(): void
    {
        $html = View::render('components/empty_state', ['title' => 'Rien <ici>', 'text' => 'Explication']);

        self::assertStringContainsString('class="empty-state"', $html);
        self::assertStringContainsString('Rien &lt;ici&gt;', $html);
        self::assertStringContainsString('Explication', $html);
        self::assertStringContainsString('icon-inbox', $html);
    }

    public function testBannerWithoutAllergyShowsExplicitState(): void
    {
        $html = View::render('components/patient_banner', ['banner' => self::banner()]);

        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('Aucune allergie connue', $html);
        self::assertStringNotContainsString('Allergies :', $html);
        self::assertStringContainsString('DUPONT', $html);
        self::assertStringContainsString('62,5 kg', $html);
        self::assertStringContainsString('non renseignée', $html, 'taille absente');
    }

    /** Retours du 2026-11-03 : référent nommé, ou « à désigner » mis en évidence sans couleur seule. */
    public function testBannerShowsReferentOrHighlightedToBeDesignated(): void
    {
        $named = View::render('components/patient_banner', [
            'banner' => ['referent' => 'Dr Claire <Morel>'] + self::banner(),
        ]);
        $missing = View::render('components/patient_banner', ['banner' => self::banner()]);

        self::assertMatchesRegularExpression('/<dt>Référent<\/dt>\s*<dd>\s*Dr Claire &lt;Morel&gt;/', $named);
        self::assertStringNotContainsString('referent-missing', $named);
        self::assertStringContainsString('<span class="referent-missing">', $missing);
        self::assertStringContainsString('icon-circle-alert', $missing, 'icône en plus du texte');
        self::assertMatchesRegularExpression('/<\/svg>\s*à désigner/', $missing);
    }

    public function testBannerWithAllergiesListsThemWithAlert(): void
    {
        $html = View::render('components/patient_banner', ['banner' => self::banner([
            ['label' => 'Substance <A>', 'criticality' => 'sévère'],
            ['label' => 'Substance B', 'criticality' => null],
        ])]);

        self::assertStringContainsString('Allergies :', $html);
        self::assertStringContainsString('icon-triangle-alert', $html);
        self::assertStringContainsString('Substance &lt;A&gt;', $html);
        self::assertStringContainsString('(sévère)', $html);
        self::assertStringNotContainsString('Aucune allergie connue', $html);
    }

    /** @return array<string, mixed> */
    public function testFieldWithHelpIsDescribedForScreenReaders(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'insNumber', 'label' => 'Matricule INS', 'help' => 'Aide <courte>', 'value' => '', 'error' => '',
        ]]);

        self::assertStringContainsString('<p class="field-help" id="insNumber-help">Aide &lt;courte&gt;</p>', $html);
        self::assertStringContainsString('aria-describedby="insNumber-help"', $html);
        self::assertStringContainsString('<label for="insNumber">', $html);
    }

    public function testFailingFieldWithHelpCombinesBothDescriptions(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'x', 'label' => 'X', 'help' => 'Aide', 'value' => '', 'error' => 'Trop long',
        ]]);

        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('aria-describedby="x-help x-error"', $html);
        self::assertStringContainsString('role="alert"', $html);
    }

    public function testCheckboxListChecksValuesAndHasNamedGroup(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'allergies', 'label' => 'Allergies', 'type' => 'checkbox-group',
            'options' => ['ALG01' => 'Pénicilline', 'ALG02' => 'Latex <x>'], 'value' => ['ALG02'], 'error' => '',
        ]]);

        self::assertStringContainsString('role="group" aria-labelledby="allergies-label"', $html);
        self::assertStringContainsString('name="allergies[]" value="ALG02"', $html);
        self::assertSame(1, substr_count($html, 'checked'));
        self::assertStringContainsString('Latex &lt;x&gt;', $html);
    }

    public function testEmptyCheckboxListShowsFallbackMessage(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'allergies', 'label' => 'Allergies', 'type' => 'checkbox-group', 'options' => [],
            'emptyMessage' => 'Référentiel indisponible', 'value' => [], 'error' => '',
        ]]);

        self::assertStringContainsString('Référentiel indisponible', $html);
        self::assertStringNotContainsString('type="checkbox"', $html);
    }

    public function testOptionalSelectOffersEmptyChoice(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'asaScore', 'label' => 'ASA', 'type' => 'select', 'empty' => 'Non renseigné',
            'options' => ['I' => 'ASA I', 'II' => 'ASA II'], 'value' => 'II', 'error' => '',
        ]]);

        self::assertStringContainsString('<option value="">Non renseigné</option>', $html);
        self::assertMatchesRegularExpression('/value="II"\s+selected/', $html);
    }

    public function testHiddenFieldAndGroupHeading(): void
    {
        $cache = View::render('components/field', ['field' => [
            'name' => 'idPatient', 'type' => 'hidden', 'value' => '9',
        ]]);
        self::assertSame('<input type="hidden" id="idPatient" name="idPatient" value="9">', trim($cache));

        $title = View::render('components/field', ['field' => [
            'name' => 'identity', 'type' => 'heading', 'label' => 'Identité <x>', 'help' => 'Aide',
        ]]);
        self::assertStringContainsString('<h2 class="form-subtitle" id="identity">Identité &lt;x&gt;</h2>', $title);
        self::assertStringNotContainsString('<input', $title);
    }

    public function testBannerAllergiesNotRecordedIsADistinctState(): void
    {
        $html = View::render('components/patient_banner', [
            'banner' => self::banner() + ['allergiesStatus' => 'unknown'],
        ]);

        self::assertStringContainsString('allergies--unknown', $html);
        self::assertStringContainsString('Allergies non renseignées', $html);
        self::assertStringNotContainsString('Aucune allergie connue', $html);

        $none = View::render('components/patient_banner', [
            'banner' => self::banner() + ['allergiesStatus' => 'none'],
        ]);
        self::assertStringContainsString('allergies--none', $none);
        self::assertStringContainsString('Aucune allergie connue', $none);
    }

    public function testBreadcrumbMarksCurrentPage(): void
    {
        $html = View::render('components/breadcrumb', ['items' => [
            ['label' => 'Patients', 'href' => '/patients'],
            ['label' => 'Dossier du séjour n° 2026-000001', 'href' => '/sejour?id=1'],
            ['label' => 'Agenda <x>'],
        ]]);

        self::assertStringContainsString('<nav class="breadcrumb" aria-label="Fil d\'Ariane">', $html);
        self::assertStringContainsString('<a href="/patients">Patients</a>', $html);
        self::assertStringContainsString('href="/sejour?id=1"', $html);
        self::assertStringContainsString('<li aria-current="page">', $html);
        self::assertStringContainsString('Agenda &lt;x&gt;', $html);
        self::assertSame(2, substr_count($html, '<a '), 'la page courante n\'est pas un lien');
    }

    public function testAllergyListWithCriticalityPerAllergen(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'allergies', 'label' => 'Allergies', 'type' => 'allergy-picker',
            'options' => ['ALG01' => 'Pénicilline', 'ALG02' => 'Latex <x>'], 'value' => ['ALG01'],
            'criticalities' => ['ALG01' => 'Élevée'], 'criticalityOptions' => ['Faible', 'Élevée'], 'error' => '',
        ]]);

        self::assertStringContainsString('data-allergy-picker', $html);
        self::assertStringContainsString('data-allergy-filter', $html);
        self::assertMatchesRegularExpression('/value="ALG01"\s+checked/', $html);
        self::assertStringContainsString('name="allergyCriticality[ALG01]"', $html);
        self::assertMatchesRegularExpression('/<option value="Élevée"\s+selected>/', $html);
        self::assertStringContainsString('Latex &lt;x&gt;', $html);
        self::assertStringContainsString('1 allergène coché', $html);
    }

    public function testBannerColorsEachAllergyByCriticality(): void
    {
        $html = View::render('components/patient_banner', ['banner' => self::banner([
            ['label' => 'Pénicilline', 'criticality' => 'Élevée', 'level' => 'high'],
            ['label' => 'Latex', 'criticality' => null, 'level' => 'unknown'],
        ])]);

        self::assertStringContainsString('allergy-tag--high', $html);
        self::assertStringContainsString('allergy-tag--unknown', $html);
        self::assertStringContainsString('(criticité non évaluée)', $html, 'jamais la couleur seule');
    }

    public function testDateFieldInDdMmYyyyWithGreyedExample(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'birthDate', 'label' => 'Date de naissance', 'type' => 'date', 'value' => '1958-03-14',
            'error' => '',
        ]]);

        self::assertStringContainsString('type="text"', $html, 'le champ date natif n\'accepte pas d\'exemple');
        self::assertStringContainsString('value="14/03/1958"', $html);
        self::assertStringContainsString('placeholder="JJ/MM/AAAA"', $html);
        self::assertStringContainsString('data-date-input', $html);
    }

    public function testFieldGreyedExample(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'lastname', 'label' => 'Nom', 'placeholder' => 'Ex. : <DUPONT>', 'value' => '', 'error' => '',
        ]]);

        self::assertStringContainsString('placeholder="Ex. : &lt;DUPONT&gt;"', $html);
    }

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
