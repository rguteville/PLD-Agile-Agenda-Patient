<?php

declare(strict_types=1);

namespace Aura\Tests\View;

use Aura\Core\View;
use Aura\Domain\CarePlan;
use PHPUnit\Framework\TestCase;

/**
 * AURA-UI : test de fumée du rendu de chaque écran avec un vue-modèle fixe (sans base). Vérifie la
 * structure attendue (bandeau, badges, états vides, dialogue) et l'échappement.
 */
final class PagesRenderTest extends TestCase
{
    public function testFullLayoutHasSkipLinkThemeAndEscapedTitle(): void
    {
        ob_start();
        View::page('error', ['code' => 404, 'message' => 'Page <introuvable>'], 'AURA - <Erreur>');
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<html lang="fr">', $html);
        self::assertStringNotContainsString('menu-toggle', $html, 'plus de menu à trois barres');
        self::assertStringContainsString('<title>AURA - &lt;Erreur&gt;</title>', $html);
        self::assertStringContainsString('name="color-scheme" content="light dark"', $html);
        self::assertStringContainsString('<script src="/js/theme.js"></script>', $html);
        self::assertStringContainsString('class="skip-link" href="#main"', $html);
        self::assertStringContainsString('id="theme-toggle"', $html);
        self::assertStringContainsString('<main id="main" tabindex="-1">', $html);
        self::assertStringContainsString('Page &lt;introuvable&gt;', $html);
        self::assertStringContainsString('role="alert"', $html);
    }

    public function testEmptyAgendaShowsEmptyStateAndFourShifts(): void
    {
        $html = View::render('pages/episode/patient-agenda', [
            'banner' => ComponentsRenderTest::banner(),
            'idEpisodeOfCare' => 1,
            'agenda' => self::agenda(),
        ]);

        self::assertStringContainsString('class="patient-banner"', $html);
        self::assertStringContainsString('Aucun soin ni médicament prévu ce jour.', $html);
        // « Dossier du séjour » en tête du menu de gauche, à la place d'« Agenda du jour » dans le dossier.
        self::assertStringContainsString('class="button record-menu__agenda" href="/sejour?id=1"', $html);
        self::assertStringContainsString('Mercredi 14 octobre 2026', $html);
        self::assertStringContainsString('href="/agenda-patient?id=1&amp;date=2026-10-13"', $html);
        self::assertStringNotContainsString('agenda-section--late', $html);
        self::assertStringNotContainsString('<dialog', $html, 'validation désactivée : pas de dialogue');
    }

    public function testAgendaWithLateCarePinnedAndValidationEnabled(): void
    {
        $late = self::item('S1', '08:00', 'late');
        $done = self::item('S2', '09:00', 'done', completedAt: '09:10', comment: 'Note <test>');
        $agenda = self::agenda(late: [$late], shiftItems: [1 => [$done]], counts: [
            'total' => 2, 'late' => 1, 'done' => 1, 'planned' => 0,
        ]);

        $html = View::render('pages/episode/patient-agenda', [
            'banner' => ComponentsRenderTest::banner(),
            'idEpisodeOfCare' => 1,
            'agenda' => $agenda,
            'validationEnabled' => true,
            'validators' => ['PRA01' => 'Soignant Test (IDE)'],
        ]);

        self::assertStringContainsString('agenda-section--late', $html);
        self::assertSame(
            1,
            substr_count($html, 'data-care-action="done" data-appointment="S1"'),
            'le retard n\'apparaît qu\'une fois'
        );
        self::assertStringContainsString('badge--late', $html);
        self::assertStringContainsString('badge--done', $html);
        self::assertStringContainsString('à 09:10', $html, 'traçabilité : heure de réalisation');
        self::assertStringContainsString('Note &lt;test&gt;', $html);
        self::assertStringContainsString('<dialog id="validation-dialog"', $html);
        self::assertStringContainsString('Soignant Test (IDE)', $html);
        self::assertStringContainsString('action="/agenda-patient/soin"', $html, 'le dialogue enregistre');
        self::assertStringContainsString('<time datetime="2026-10-14T08:00">08:00</time>', $html);
    }

    public function testPrescriptionListWithThreeStatuses(): void
    {
        $html = View::render('pages/careplan/list', [
            'banner' => ComponentsRenderTest::banner(),
            'idEpisodeOfCare' => 1,
            'isOpen' => true,
            'lines' => [
                self::line(CarePlan::STATUS_NOT_VALIDATED),
                self::line(CarePlan::STATUS_VALIDATED, validation: 'Dr Test, le 14/10/2026 09:00'),
                self::line(CarePlan::STATUS_STOPPED, stop: 'Dr Test, le 15/10/2026 09:00'),
            ],
        ]);

        self::assertStringContainsString('badge--to_validate', $html);
        self::assertStringContainsString('badge--validated', $html);
        self::assertStringContainsString('badge--stopped', $html);
        self::assertStringContainsString('Dr Test, le 14/10/2026 09:00', $html);
        self::assertStringContainsString('Nouvelle prescription', $html);
    }

    public function testEmptyPrescriptionListClosedEpisode(): void
    {
        $html = View::render('pages/careplan/list', [
            'banner' => ComponentsRenderTest::banner(),
            'idEpisodeOfCare' => 1,
            'isOpen' => false,
            'lines' => [],
        ]);

        self::assertStringContainsString('Aucune prescription pour ce séjour.', $html);
        self::assertStringContainsString('Le séjour est clos.', $html);
        self::assertStringNotContainsString('Nouvelle prescription', $html);
    }

    public function testEpisodeRecordWithoutPrescription(): void
    {
        $html = View::render('pages/episode/record', [
            'banner' => ComponentsRenderTest::banner(),
            'episodeOfCare' => [
                'ongoing' => true, 'dischargeDate' => null, 'service' => 'Réa <1>', 'bed' => 'L01', 'box' => null,
                'weight' => '62,5 kg', 'height' => null, 'precautions' => null, 'phone' => null, 'email' => null,
            ],
            'idEpisodeOfCare' => 1,
            'prescriptions' => [],
            'doctors' => [],
            'allergies' => [],
            'substances' => ['SUB01' => 'Substance A'],
            'criticalities' => ['faible'],
        ]);

        self::assertStringContainsString('Réa &lt;1&gt;', $html);
        self::assertStringContainsString('href="/agenda-patient?id=1"', $html);
        self::assertStringContainsString('Aucune prescription validée.', $html);
        self::assertStringContainsString('Aucune allergie connue', $html);
        self::assertStringContainsString('badge--planned', $html, 'statut « En cours »');
    }

    /** Dossier complet : sections présentes même vides, « Non renseigné » explicite, valeurs échappées. */
    public function testRecordShowsFullRecordSections(): void
    {
        $html = View::render('pages/episode/record', [
            'banner' => ComponentsRenderTest::banner(),
            'episodeOfCare' => [
                'ongoing' => true, 'dischargeDate' => null, 'service' => null, 'admissionReason' => null, 'bed' => null,
                'box' => null, 'precautions' => null, 'phone' => null, 'email' => null,
            ],
            'idEpisodeOfCare' => 1,
            'prescriptions' => [],
            'doctors' => [],
            'allergies' => [],
            'substances' => [],
            'criticalities' => [],
            'sections' => [
                ['id' => 'history', 'title' => 'Antécédents', 'filled' => 1, 'total' => 2, 'rows' => [
                    ['entity' => 'patient', 'field' => 'medicalHistory', 'label' => 'ATCD médicaux',
                        'type' => 'textarea', 'options' => null, 'computed' => false, 'help' => null,
                        'raw' => "HTA <b>\nDiabète", 'value' => "HTA <b>\nDiabète"],
                    ['entity' => 'patient', 'field' => 'lifestyle', 'label' => 'Mode de vie',
                        'type' => 'textarea', 'options' => null, 'computed' => false, 'help' => null,
                        'raw' => null, 'value' => null],
                ]],
                ['id' => 'disease', 'title' => 'Histoire de la maladie', 'filled' => 0, 'total' => 1, 'rows' => [
                    ['entity' => 'episode', 'field' => 'bmi', 'label' => 'IMC', 'type' => 'computed',
                        'options' => null, 'computed' => true, 'help' => 'Non calculable : taille manquante.',
                        'raw' => null, 'value' => null],
                ]],
            ],
        ]);

        self::assertStringContainsString('id="dossier-history" open', $html, 'section renseignée ouverte');
        self::assertStringContainsString('id="dossier-disease"', $html, 'section vide toujours présente');
        self::assertStringNotContainsString('id="dossier-disease" open', $html, 'section vide repliée');
        self::assertMatchesRegularExpression('/1 sur 2\s+renseigné/', $html);
        self::assertStringContainsString("HTA &lt;b&gt;\nDiabète", $html);
        self::assertStringContainsString('<span class="not-recorded">Non renseigné</span>', $html);
        self::assertStringContainsString('<span class="not-recorded">Non calculable</span>', $html);
        self::assertStringContainsString('<small>Non calculable : taille manquante.</small>', $html);
        self::assertMatchesRegularExpression('/data-entity="patient"\s+data-field="medicalHistory"/', $html);
    }

    /** Formulaire d'admission : essentiels visibles, sections repliées avec compteur, référentiels. */
    public function testAdmissionShowsCollapsedSectionsAndReferenceData(): void
    {
        $html = View::render('pages/patient/admission', [
            'referenceDataSets' => [
                'services' => ['SRV01' => 'Réa <1>'],
                'substances' => ['ALG01' => 'Pénicilline'],
            ],
        ]);

        self::assertStringContainsString('name="lastname"', $html);
        self::assertStringContainsString('name="admissionReason"', $html);
        // Deux colonnes : identité → note sur les allergies à gauche, sections à droite, recherche en tête.
        $left = strpos($html, 'form-columns__main');
        $right = strpos($html, 'form-columns__more');
        self::assertNotFalse($left);
        self::assertNotFalse($right);
        self::assertLessThan($left, strpos($html, 'name="patientSearch"'), 'recherche avant les colonnes');
        self::assertStringContainsString('<div class="form-lead">', $html, 'recherche : IPP et nom côte à côte');
        self::assertGreaterThan($left, strpos($html, 'name="allergyNote"'));
        self::assertLessThan($right, strpos($html, 'name="allergyNote"'), 'note sur les allergies à gauche');
        self::assertGreaterThan($right, strpos($html, 'id="section-history"'), 'Antécédents à droite');
        // Recherche d'un patient existant : combobox accessible, liste de suggestions, champs cachés.
        self::assertMatchesRegularExpression('/name="patientSearch"[^>]*role="combobox"/', $html);
        self::assertStringContainsString('aria-controls="patient-suggestions"', $html);
        self::assertStringContainsString('<ul id="patient-suggestions" class="suggestions" role="listbox"', $html);
        self::assertStringContainsString('<input type="hidden" id="idPatient" name="idPatient" value="">', $html);
        self::assertStringContainsString('<input type="hidden" id="searchMode" name="searchMode" value="">', $html);
        self::assertStringContainsString('id="patient-search-status" aria-live="polite"', $html);
        self::assertStringContainsString('<option value="SRV01"', $html);
        self::assertStringContainsString('Réa &lt;1&gt;', $html);
        self::assertStringContainsString('name="allergies[]" value="ALG01"', $html);
        foreach (['history', 'disease', 'scores', 'measures', 'admin'] as $section) {
            self::assertMatchesRegularExpression('/id="section-' . $section . '"\s+data-form-section/', $html);
        }
        self::assertStringNotContainsString('data-form-section open', $html, 'sections repliées par défaut');
        self::assertStringContainsString('0 sur 12', $html, 'compteur des antécédents');
        self::assertStringContainsString('name="asaScore"', $html);
        self::assertStringContainsString('ASA III – maladie systémique sévère', $html);
        self::assertStringContainsString('À remplir par le médecin en charge uniquement en cas de plan blanc.', $html);
    }

    public function testAdmissionAfterRejectionOpensFailingSectionAndRefillsValues(): void
    {
        $html = View::render('pages/patient/admission', [
            'values' => [
                'lastname' => 'Durand', 'asaScore' => 'III', 'allergies' => ['ALG01'], 'medicalHistory' => 'HTA',
            ],
            'errors' => ['girScore' => 'Hors échelle'],
            'referenceDataSets' => ['services' => [], 'substances' => ['ALG01' => 'Pénicilline', 'ALG02' => 'Latex']],
        ]);

        self::assertStringContainsString('id="section-scores"', $html);
        self::assertSame(1, substr_count($html, 'data-form-section open'), 'seule la section en erreur est ouverte');
        self::assertStringContainsString('1 sur 12', $html, 'compteur des antécédents tient compte de la ressaisie');
        self::assertStringContainsString('role="alert">Hors échelle</p>', $html);
        self::assertMatchesRegularExpression('/value="ALG01"\s+checked/', $html);
        self::assertStringContainsString('Référentiel indisponible', $html, 'liste des services vide');
    }

    public function testAdmissionWithoutDatabaseStillRenders(): void
    {
        $html = View::render('pages/patient/admission', []);

        self::assertStringContainsString('Référentiel des allergènes indisponible', $html);
        self::assertStringContainsString('name="lastname"', $html);
    }

    /** Édition en ligne : chaque ligne modifiable est un bouton avec ses métadonnées, panneau d'historique. */
    public function testEditableRecordRendersButtonsAndHistory(): void
    {
        $html = View::render('pages/episode/record', [
            'banner' => ComponentsRenderTest::banner(),
            'episodeOfCare' => [
                'idPatient' => 4, 'ongoing' => true, 'dischargeDate' => null, 'service' => null,
                'admissionReason' => null, 'bed' => null, 'box' => null, 'precautions' => null,
                'phone' => null, 'email' => null,
            ],
            'idEpisodeOfCare' => 1,
            'prescriptions' => [],
            'doctors' => [],
            'allergies' => [],
            'substances' => [],
            'criticalities' => [],
            'editable' => true,
            'csrfToken' => 'jeton<x>',
            'sections' => [
                ['id' => 'scores', 'title' => 'Scores', 'filled' => 1, 'total' => 2, 'rows' => [
                    ['entity' => 'episode', 'field' => 'asaScore', 'label' => 'Score ASA', 'type' => 'select',
                        'options' => ['I' => 'ASA I', 'II' => 'ASA "II"'], 'computed' => false, 'help' => null,
                        'raw' => 'II', 'value' => 'ASA "II"'],
                    ['entity' => 'patient', 'field' => 'lifestyle', 'label' => 'Mode de vie', 'type' => 'textarea',
                        'options' => null, 'computed' => false, 'help' => null, 'raw' => null, 'value' => null],
                    ['entity' => 'episode', 'field' => 'bmi', 'label' => 'IMC', 'type' => 'computed',
                        'options' => null, 'computed' => true, 'help' => null, 'raw' => null, 'value' => '22,9 kg/m²'],
                ]],
            ],
            'history' => [
                ['recordedAt' => '14/10/2026 08:05', 'author' => 'non identifié', 'entity' => 'Patient',
                    'field' => 'lifestyle', 'label' => 'Mode de vie', 'detail' => 'précision <x>',
                    'oldValue' => null, 'newValue' => 'Non <fumeur>'],
            ],
        ]);

        self::assertStringContainsString(
            'data-inline-edit data-endpoint="/sejour/champ/modifier" data-csrf="jeton&lt;x&gt;"',
            $html
        );
        self::assertStringContainsString('id="inline-edit-status" aria-live="polite"', $html);
        self::assertMatchesRegularExpression('/data-entity="episode"\s+data-id="1"\s+data-field="asaScore"/', $html);
        self::assertMatchesRegularExpression('/data-entity="patient"\s+data-id="4"\s+data-field="lifestyle"/', $html);
        self::assertStringContainsString(
            'data-options="{&quot;I&quot;:&quot;ASA I&quot;,&quot;II&quot;:&quot;ASA \&quot;II\&quot;&quot;}"',
            $html
        );
        self::assertStringContainsString('aria-label="Modifier : Score ASA"', $html);
        self::assertSame(2, substr_count($html, 'class="inline-edit__value"'), 'valeur calculée non éditable');
        // Crayon d'édition dans chaque bouton-valeur, la valeur elle-même dans un span (jamais dupliquée).
        self::assertSame(2, substr_count($html, 'inline-edit__pencil'));
        self::assertStringContainsString('<span class="inline-edit__text">ASA &quot;II&quot;</span>', $html);
        self::assertStringContainsString('id="dossier-historique"', $html);
        // Menu figé : agenda en tête, parties du dossier, sous-catégories du dossier complet.
        self::assertStringContainsString('<nav class="record-menu" aria-label="Parties du dossier">', $html);
        self::assertLessThan(
            strpos($html, 'href="#dossier-resume"'),
            strpos($html, 'class="button record-menu__agenda" href="/agenda-patient?id=1"'),
            'bouton Agenda en haut du menu'
        );
        self::assertStringContainsString('href="#titre-allergies"', $html);
        self::assertMatchesRegularExpression('/href="#dossier-scores"\s+data-open-details/', $html);
        self::assertStringContainsString('href="#dossier-historique" data-open-details', $html);
        self::assertStringContainsString('id="titre-prescriptions"', $html);
        self::assertStringContainsString('<aside class="record-aside" aria-labelledby="titre-prescriptions">', $html);
        // Modification d'une partie entière : crayon à côté du titre, formulaire masqué, fenêtre de validation.
        self::assertStringContainsString('data-part-edit="summary"', $html);
        self::assertStringContainsString('data-part-edit="scores"', $html);
        self::assertMatchesRegularExpression('/<form class="part-form" data-part="summary"/', $html);
        self::assertStringContainsString('id="summary-lastName"', $html, 'identifiants préfixés par la partie');
        self::assertStringContainsString('data-end-stay', $html, 'séjour en cours : bouton de fin de séjour');
        self::assertStringContainsString('<dialog id="auth-dialog"', $html);
        self::assertStringContainsString('le mot de passe n\'est pas vérifié', $html, 'authentification factice');
        self::assertStringContainsString('<td>Non &lt;fumeur&gt;</td>', $html);
        self::assertStringContainsString('<small>précision &lt;x&gt;</small>', $html);
        self::assertStringContainsString('data-id-episode-of-care="1"', $html);
        foreach (['public/js/inline-edit.js', 'public/js/part-edit.js'] as $script) {
            self::assertStringContainsString(
                'dataset.idEpisodeOfCare',
                (string) file_get_contents(__DIR__ . '/../../' . $script),
                "$script lit l'attribut data-id-episode-of-care rendu par la vue"
            );
        }
        self::assertStringContainsString('<span class="not-recorded">vide</span>', $html);
    }

    public function testReadOnlyRecordHasNoButtonOrHistory(): void
    {
        $html = View::render('pages/episode/record', [
            'banner' => ComponentsRenderTest::banner(),
            'episodeOfCare' => [
                'ongoing' => true, 'dischargeDate' => null, 'service' => null, 'admissionReason' => null, 'bed' => null,
                'box' => null, 'precautions' => null, 'phone' => null, 'email' => null,
            ],
            'idEpisodeOfCare' => 1, 'prescriptions' => [], 'doctors' => [], 'allergies' => [], 'substances' => [],
            'criticalities' => [], 'sections' => [],
        ]);

        self::assertStringNotContainsString('data-inline-edit', $html);
        self::assertStringNotContainsString('dossier-historique', $html);
    }

    public function testPatientListEmptyAndWithSearch(): void
    {
        $empty = View::render('pages/patient/list', ['patients' => [], 'search' => 'zz<q>']);
        self::assertStringContainsString('Aucun patient ne correspond à « zz&lt;q&gt; »', $empty);

        $full = View::render('pages/patient/list', ['patients' => [[
            'idPatient' => 1, 'lastName' => 'DUPONT', 'firstName' => 'Test', 'ipp' => null, 'birthDate' => null,
            'idEpisodeOfCare' => 7, 'episodeNumber' => '2026-000007',
        ]], 'search' => null]);
        self::assertStringContainsString('href="/sejour?id=7"', $full);
        self::assertStringContainsString('aria-label="Dossier de DUPONT Test"', $full);
        self::assertStringNotContainsString('/patients/modifier', $full, 'modification depuis le dossier seulement');
        self::assertStringContainsString('<th scope="col">', $full);
    }

    public function testServiceAgendaIsVerticalWithLateAlertAllergiesAndActions(): void
    {
        $late = [
            'id' => 'S1', 'time' => '06:30', 'datetime' => '2026-10-14T06:30', 'hour' => 6, 'label' => 'Soin <A>',
            'details' => null, 'status' => 'late', 'performedBy' => null,
        ];
        $done = ['id' => 'S2', 'time' => '07:00', 'datetime' => '2026-10-14T07:00', 'status' => 'done',
            'performedBy' => 'Soignant <Test>'] + $late;
        $html = View::render('pages/service/consolidated-view', [
            'service' => [
                'date' => '2026-10-14',
                'dateLabel' => 'mercredi 14 octobre 2026',
                'isToday' => true,
                'previous' => '2026-10-13',
                'next' => '2026-10-15',
                'slots' => ['06:00', '08:00'],
                'currentSlot' => '06:00',
                'late' => 1,
                'lateItems' => [$late + ['idEpisodeOfCare' => 1, 'patient' => 'DUPONT Test', 'bed' => 'L01']],
                'patients' => [[
                    'idEpisodeOfCare' => 1, 'lastName' => 'DUPONT', 'firstName' => 'Test', 'bed' => 'L01',
                    'episodeNumber' => '2026-000001', 'referent' => 'Dr <Ref>', 'late' => 1, 'total' => 2,
                    'allergies' => [['label' => 'Latex <x>', 'level' => 'high']],
                    'cells' => ['06:00' => ['late' => 1, 'done' => 1]],
                    'items' => ['06:00' => [$late, $done]],
                ]],
            ],
            'services' => ['SRV01' => 'Réanimation'],
            'idHealthcareService' => 'SRV01',
            'validationEnabled' => true,
            'validators' => ['PRA01' => 'Soignant Test (IDE)'],
            'csrfToken' => 'jeton',
        ]);

        self::assertStringContainsString('<table class="service-timeline">', $html);
        self::assertStringContainsString('<th scope="col" class="service-patient">', $html, 'un patient par colonne');
        self::assertStringContainsString('6 h – 8 h', $html, 'un créneau par ligne');
        self::assertStringContainsString('class="service-slot is-now"', $html);
        self::assertStringContainsString('1 soin en retard', $html, 'alerte du service (AURA-32)');
        self::assertStringContainsString('allergy-tag--high', $html, 'RG2 : allergies du patient en colonne');
        self::assertStringContainsString('Latex &lt;x&gt;', $html);
        self::assertStringContainsString('Réf. Dr &lt;Ref&gt;', $html);
        self::assertStringContainsString('par Soignant &lt;Test&gt;', $html);
        self::assertStringContainsString('href="/agenda-patient?id=1&amp;date=2026-10-14"', $html);
        self::assertStringContainsString('data-care-action="done" data-appointment="S1"', $html);
        self::assertStringContainsString('Rien de prévu', $html, 'créneau vide annoncé aux lecteurs d\'écran');
        self::assertStringContainsString('action="/agenda-patient/soin"', $html);
        self::assertStringContainsString('aria-label="Légende"', $html);
    }

    public function testConsolidatedViewWithoutPatient(): void
    {
        $html = View::render('pages/service/consolidated-view', ['service' => [
            'date' => '2026-10-14', 'dateLabel' => 'mercredi 14 octobre 2026', 'slots' => [], 'patients' => [],
        ]]);

        self::assertStringContainsString('Aucun patient dans le service ce jour.', $html);
    }

    // ---- Fabriques ----

    /** @return array<string, mixed> */
    private static function agenda(array $late = [], array $shiftItems = [], ?array $counts = null): array
    {
        $shifts = [];
        foreach (\Aura\Service\AgendaService::SHIFTS as $index => $shift) {
            $shifts[] = $shift + ['items' => $shiftItems[$index] ?? []];
        }

        return [
            'date' => '2026-10-14',
            'dateLabel' => 'mercredi 14 octobre 2026',
            'isToday' => false,
            'previous' => '2026-10-13',
            'next' => '2026-10-15',
            'counts' => $counts ?? ['total' => 0, 'late' => 0, 'done' => 0, 'planned' => 0],
            'late' => $late,
            'shifts' => $shifts,
        ];
    }

    /** @return array<string, mixed> */
    private static function item(
        string $id,
        string $time,
        string $status,
        ?string $completedAt = null,
        ?string $comment = null
    ): array {
        return [
            'id' => $id,
            'time' => $time,
            'datetime' => '2026-10-14T' . $time,
            'hour' => (int) substr($time, 0, 2),
            'kind' => 'CARE',
            'kindLabel' => 'Soin',
            'label' => 'Soin A',
            'details' => null,
            'responsible' => 'IDE / AS',
            'status' => $status,
            'completedAt' => $completedAt,
            'recordedAt' => null,
            'comment' => $comment,
        ];
    }

    /** @return array<string, mixed> */
    private static function line(string $status, ?string $validation = null, ?string $stop = null): array
    {
        $line = [
            'idCarePlan' => 1, 'idLine' => 1, 'isMedication' => false, 'activity' => 'Soin A', 'label' => 'Soin A',
            'frequency' => 'Toutes les 8 h', 'startDate' => '14/10/2026 08:00', 'endDate' => null,
            'period' => 'du 14/10/2026 08:00, sans date de fin',
            'mode' => 'Médical', 'prescriber' => 'Dr Test', 'recordedAt' => '14/10/2026 08:00',
            'status' => $status, 'validation' => $validation, 'stop' => $stop,
        ];
        foreach (
            ['timeConstraint', 'important', 'nurseInstructions', 'maxDose', 'dilutionVolume', 'solvent',
            'administrationDuration', 'antibioticReason', 'germ', 'renalAdjustment', 'adjustmentCriterion',
            'offGhsReason'] as $note
        ) {
            $line[$note] = null;
        }

        return $line;
    }
}
