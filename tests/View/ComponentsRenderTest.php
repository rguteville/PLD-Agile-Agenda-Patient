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
    public function testChampAvecAideEstDecritPourLesLecteursDEcran(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'insNumber', 'label' => 'Matricule INS', 'help' => 'Aide <courte>', 'value' => '', 'error' => '',
        ]]);

        self::assertStringContainsString('<p class="field-help" id="insNumber-help">Aide &lt;courte&gt;</p>', $html);
        self::assertStringContainsString('aria-describedby="insNumber-help"', $html);
        self::assertStringContainsString('<label for="insNumber">', $html);
    }

    public function testChampEnErreurAvecAideCumuleLesDeuxDescriptions(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'x', 'label' => 'X', 'help' => 'Aide', 'value' => '', 'error' => 'Trop long',
        ]]);

        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('aria-describedby="x-help x-error"', $html);
        self::assertStringContainsString('role="alert"', $html);
    }

    public function testListeACasesCocheLesValeursEtPorteUnGroupeNomme(): void
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

    public function testListeACasesVideAfficheLeMessageDeRepli(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'allergies', 'label' => 'Allergies', 'type' => 'checkbox-group', 'options' => [],
            'emptyMessage' => 'Référentiel indisponible', 'value' => [], 'error' => '',
        ]]);

        self::assertStringContainsString('Référentiel indisponible', $html);
        self::assertStringNotContainsString('type="checkbox"', $html);
    }

    public function testListeDeroulanteFacultativeProposeUnChoixVide(): void
    {
        $html = View::render('components/field', ['field' => [
            'name' => 'asaScore', 'label' => 'ASA', 'type' => 'select', 'empty' => 'Non renseigné',
            'options' => ['I' => 'ASA I', 'II' => 'ASA II'], 'value' => 'II', 'error' => '',
        ]]);

        self::assertStringContainsString('<option value="">Non renseigné</option>', $html);
        self::assertMatchesRegularExpression('/value="II"\s+selected/', $html);
    }

    public function testChampCacheEtTitreDeGroupe(): void
    {
        $cache = View::render('components/field', ['field' => [
            'name' => 'idPatient', 'type' => 'hidden', 'value' => '9',
        ]]);
        self::assertSame('<input type="hidden" id="idPatient" name="idPatient" value="9">', trim($cache));

        $titre = View::render('components/field', ['field' => [
            'name' => 'identite', 'type' => 'heading', 'label' => 'Identité <x>', 'help' => 'Aide',
        ]]);
        self::assertStringContainsString('<h2 class="form-subtitle" id="identite">Identité &lt;x&gt;</h2>', $titre);
        self::assertStringNotContainsString('<input', $titre);
    }

    public function testBandeauAllergiesNonRenseigneesEstUnEtatDistinct(): void
    {
        $html = View::render('components/bandeau_patient', [
            'bandeau' => self::bandeau() + ['allergiesStatus' => 'unknown'],
        ]);

        self::assertStringContainsString('allergies--unknown', $html);
        self::assertStringContainsString('Allergies non renseignées', $html);
        self::assertStringNotContainsString('Aucune allergie connue', $html);

        $none = View::render('components/bandeau_patient', [
            'bandeau' => self::bandeau() + ['allergiesStatus' => 'none'],
        ]);
        self::assertStringContainsString('allergies--none', $none);
        self::assertStringContainsString('Aucune allergie connue', $none);
    }

    public function testFilDArianeMarqueLaPageCourante(): void
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

    public function testListeDesAllergiesAvecCriticiteParAllergene(): void
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

    public function testBandeauColoreChaqueAllergieSelonSaCriticite(): void
    {
        $html = View::render('components/bandeau_patient', ['bandeau' => self::bandeau([
            ['libelle' => 'Pénicilline', 'criticite' => 'Élevée', 'niveau' => 'high'],
            ['libelle' => 'Latex', 'criticite' => null, 'niveau' => 'unknown'],
        ])]);

        self::assertStringContainsString('allergie--high', $html);
        self::assertStringContainsString('allergie--unknown', $html);
        self::assertStringContainsString('(criticité non évaluée)', $html, 'jamais la couleur seule');
    }

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
