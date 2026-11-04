<?php

declare(strict_types=1);

namespace Aura\Tests\View;

use Aura\Core\View;
use Aura\Domain\CarePlan;
use Aura\Service\AgendaService;
use Aura\Service\CarePlanService;
use DateTimeImmutable;
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
        self::assertStringNotContainsString('>Responsable</th>', $html, 'colonne retirée (retours du 2026-11-04)');
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

    /** Retours du 2026-11-03 : note de l'ordonnance et allergène signalés (et échappés) dans la liste. */
    public function testPrescriptionListShowsCarePlanNoteAndAllergen(): void
    {
        $line = self::line(CarePlan::STATUS_VALIDATED, validation: 'Dr Test, le 14/10/2026 09:00');
        $line['carePlanNote'] = 'Réévaluer <J3>';
        $line['allergen'] = 'Pénicilline';

        $html = View::render('pages/careplan/list', [
            'banner' => ComponentsRenderTest::banner(),
            'idEpisodeOfCare' => 1,
            'isOpen' => true,
            'lines' => [$line],
        ]);

        self::assertStringContainsString('Réévaluer &lt;J3&gt;', $html);
        self::assertStringContainsString('Contient un allergène du patient :', $html);
        self::assertStringContainsString('Pénicilline', $html);
    }

    /** Retours du 2026-11-03 : écran B3 (libellés des listes, note, allergie, validation authentifiée, CSRF). */
    public function testPrescriptionFormLabelsNoteAllergyAndAuthenticatedValidation(): void
    {
        $html = View::render('pages/careplan/form', [
            'banner' => ComponentsRenderTest::banner(),
            'idEpisodeOfCare' => 1,
            'savedCarePlanId' => null,
            'validatedCarePlanId' => null,
            'modes' => ['MEDICAL' => 'Prescription médicale'],
            'prescribers' => ['PRA01' => 'Dr Test'],
            'validators' => ['PRA01' => 'Dr Test'],
            'categories' => CarePlanService::CATEGORIES,
            'careTypes' => ['TYP02' => 'Prise des constantes'],
            'medications' => ['Amoxicilline' => ['MED04' => 'Poudre']],
            'planDefinitions' => [],
            'frequencyTypes' => CarePlanService::FREQUENCY_TYPES,
            'routes' => CarePlanService::ROUTES,
            'pendingCarePlans' => [[
                'idCarePlan' => 4, 'mode' => 'Urgence', 'recordedAt' => '14/10/2026 08:00', 'prescriber' => 'IDE',
                'note' => 'Note <en attente>', 'lines' => ['[IV] Amoxicilline (Poudre) 1 g (Une fois)'],
                'allergyAlerts' => ['[IV] Amoxicilline (Poudre) 1 g : allergie à Pénicilline'],
            ]],
            'formError' => '',
            'activeMedications' => [],
            'medicationAllergens' => ['MED04' => 'Pénicilline'],
            'noteMaxLength' => CarePlanService::NOTE_MAX_LENGTH,
            'csrfToken' => 'jeton-<test>',
        ]);

        foreach (['Catégorie', 'Forme galénique', 'id="item-label"'] as $label) {
            self::assertStringContainsString($label, $html);
        }
        self::assertDoesNotMatchRegularExpression('/<label for="(category|item|dosage-form)">\s*[123]\./', $html);
        self::assertStringContainsString('name="note" form="care-plan-form"', $html);
        self::assertStringContainsString('name="allergyOverride" value="" form="care-plan-form"', $html);
        self::assertStringContainsString('Je prescris malgré l\'allergie', $html);
        // Correspondance médicament → allergène transmise au JS (data-lists).
        self::assertStringContainsString('&quot;MED04&quot;:&quot;Pénicilline&quot;', $html);
        self::assertStringContainsString('Note &lt;en attente&gt;', $html);
        self::assertStringContainsString('allergie à Pénicilline', $html);
        self::assertStringContainsString('<label for="validate-4-password">Mot de passe</label>', $html);
        self::assertStringContainsString('type="password" id="validate-4-password" name="password"', $html);
        self::assertSame(2, substr_count($html, 'name="csrf" value="jeton-&lt;test&gt;"'), 'enregistrer et valider');
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
        self::assertStringContainsString('Référent à désigner', $html, 'référent absent : état explicite');
    }

    /** Résumé du dossier : médecin référent nommé, et proposé dans la partie « Dossier » modifiable. */
    public function testRecordSummaryShowsReferentAndOffersDoctorList(): void
    {
        $html = View::render('pages/episode/record', [
            'banner' => ['referent' => 'Dr Claire Morel'] + ComponentsRenderTest::banner(),
            'episodeOfCare' => [
                'ongoing' => true, 'dischargeDate' => null, 'service' => null, 'referent' => 'Dr Claire Morel',
                'bed' => null, 'box' => null, 'precautions' => null, 'phone' => null, 'email' => null,
                'idPatient' => 1,
            ],
            'idEpisodeOfCare' => 1,
            'prescriptions' => [],
            'doctors' => [],
            'allergies' => [],
            'substances' => [],
            'criticalities' => [],
            'editable' => true,
            'referringDoctors' => ['PRA01' => 'Dr Claire Morel', 'PRA02' => 'Dr Karim <Benali>'],
            'partValues' => ['summary' => ['idReferringPractitioner' => 'PRA01']],
        ]);

        self::assertMatchesRegularExpression('/<dt>Médecin référent<\/dt>\s*<dd>\s*Dr Claire Morel/', $html);
        self::assertStringNotContainsString('Référent à désigner', $html);
        self::assertStringContainsString('name="idReferringPractitioner"', $html, 'modifiable dans la partie');
        self::assertMatchesRegularExpression('/<option value="PRA01"\s+selected/', $html);
        self::assertStringContainsString('Dr Karim &lt;Benali&gt;', $html);
        self::assertStringContainsString('À désigner</option>', $html, 'option vide explicite');
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
                'doctors' => ['PRA01' => 'Dr Claire Morel'],
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
        // Médecin référent : facultatif (« À désigner »), avec l'aide sur l'admission de garde.
        self::assertStringContainsString('name="idReferringPractitioner"', $html);
        self::assertStringContainsString('<option value="PRA01"', $html);
        self::assertStringContainsString('À désigner</option>', $html);
        self::assertStringContainsString('pendant une garde', $html);
        self::assertStringContainsString('name="allergies[]" value="ALG01"', $html);
        foreach (['history', 'disease', 'scores', 'measures', 'admin'] as $section) {
            self::assertMatchesRegularExpression('/id="section-' . $section . '"\s+data-form-section/', $html);
        }
        self::assertStringNotContainsString('data-form-section open', $html, 'sections repliées par défaut');
        self::assertStringContainsString('0 sur 12', $html, 'compteur des antécédents');
        self::assertStringContainsString('name="asaScore"', $html);
        self::assertStringContainsString('ASA III : maladie systémique sévère', $html);
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

        $full = View::render('pages/patient/list', ['patients' => [self::listLine(
            current: self::current(7),
            record: ['idEpisodeOfCare' => 7, 'episodeNumber' => '2026-000007', 'ongoing' => true,
                'dischargeDate' => null],
        )], 'search' => null]);
        self::assertStringContainsString('href="/sejour?id=7"', $full);
        self::assertStringContainsString('aria-label="Dossier du séjour en cours de DUPONT Test"', $full);
        self::assertStringNotContainsString('/patients/modifier', $full, 'modification depuis le dossier seulement');
        self::assertStringContainsString('<th scope="col">', $full);
        // AURA-12.3 : suppression depuis la liste, nommée pour les lecteurs d'écran.
        self::assertStringContainsString('href="/patients/supprimer?id=1"', $full);
        self::assertStringContainsString('aria-label="Supprimer DUPONT Test"', $full);
        self::assertStringNotContainsString('Patient supprimé', $full);

        $afterDeletion = View::render('pages/patient/list', ['patients' => [], 'search' => null, 'deleted' => true]);
        self::assertStringContainsString('role="status"', $afterDeletion);
        self::assertStringContainsString('Patient supprimé', $afterDeletion);
    }

    /**
     * Retours des médecins du 2026-11-03 : séjour en cours mis en avant, dossier du dernier séjour (clos) ou
     * « aucun séjour » sans lien mort, historique dépliable, décès en icône + texte.
     */
    public function testPatientListHighlightsCurrentEpisodeAndKeepsEveryRecordReachable(): void
    {
        $history = [
            self::historyItem(1, true, null),
            self::historyItem(7, false, '05/06/2026', 'Motif <grave>'),
        ];
        $html = View::render('pages/patient/list', ['patients' => [
            self::listLine(
                current: self::current(1),
                record: ['idEpisodeOfCare' => 1, 'episodeNumber' => '2026-000001', 'ongoing' => true,
                    'dischargeDate' => null],
                history: $history
            ),
            self::listLine(
                idPatient: 5,
                lastName: 'FLETCHER',
                deceased: true,
                record: ['idEpisodeOfCare' => 5, 'episodeNumber' => '2026-000005', 'ongoing' => false,
                    'dischargeDate' => '27/09/2026'],
                history: [self::historyItem(5, false, '27/09/2026')]
            ),
            self::listLine(idPatient: 9, lastName: 'NOUVEAU'),
        ], 'search' => null]);

        // Séjour en cours (audit IHM du 2026-11-04) : lit et service, puis numéro et date d'entrée.
        self::assertStringContainsString('patient-list__row--current', $html);
        self::assertStringContainsString('n° 2026-000001, depuis le', $html);
        self::assertMatchesRegularExpression('/Lit L01,/', $html);
        self::assertStringContainsString('Réa &lt;médicale&gt;', $html);
        self::assertStringContainsString('depuis le 10/10/2026', $html);
        // Dernier séjour clos : lien explicite ; aucun séjour : texte, pas de lien.
        self::assertStringContainsString(
            'aria-label="Dossier du dernier séjour de FLETCHER Test, clos le 27/09/2026">Dernier dossier</a>',
            $html
        );
        self::assertStringContainsString('Aucun séjour : pas de dossier', $html);
        self::assertStringNotContainsString('href="/sejour?id="', $html, 'jamais de lien mort');
        // Historique dépliable, du plus récent au plus ancien, chaque séjour mène à son dossier.
        self::assertStringContainsString('<details class="episode-history-toggle">', $html);
        self::assertStringContainsString('2 séjours', $html);
        self::assertStringContainsString('aria-label="Séjours de DUPONT Test, du plus récent au plus ancien"', $html);
        self::assertLessThan(
            strpos($html, 'href="/sejour?id=7">Séjour n° 2026-000007'),
            strpos($html, 'href="/sejour?id=1">Séjour n° 2026-000001')
        );
        self::assertStringContainsString('Motif : Motif &lt;grave&gt;', $html);
        self::assertStringContainsString('sortie le 05/06/2026', $html);
        // Décès (2026-11-04) : cœur barré seul à côté du nom, libellé en infobulle et en texte alternatif.
        self::assertMatchesRegularExpression(
            '/<span class="deceased-tag deceased-tag--icon" title="Décédée">\s*'
            . '<svg class="icon icon-heart-off"[^>]*role="img" aria-label="Décédée"/s',
            $html
        );
        self::assertStringNotContainsString('<span>Décédée</span>', $html, 'plus d\'étiquette sous le nom');
        self::assertMatchesRegularExpression(
            '/<td><span class="deceased-tag deceased-tag--icon"[^>]*>.*?<\/span>\s*[^<\s][^<]*<\/td>/s',
            $html,
            'le cœur est à gauche du nom'
        );
        self::assertSame(1, substr_count($html, 'deceased-tag--icon'), 'seul le patient décédé est marqué');
    }

    /** Dossier d'un séjour clos d'un patient hospitalisé : encart vers le séjour en cours, historique signalé. */
    public function testRecordOfOldEpisodeLinksCurrentEpisodeAndListsHistory(): void
    {
        $html = View::render('pages/episode/record', [
            'banner' => ['deceased' => true, 'deceasedLabel' => 'Décédée'] + ComponentsRenderTest::banner(),
            'episodeOfCare' => [
                'ongoing' => false, 'dischargeDate' => '05/06/2026 16:00', 'service' => null, 'bed' => null,
                'box' => null, 'precautions' => null, 'phone' => null, 'email' => null, 'idPatient' => 1,
            ],
            'idEpisodeOfCare' => 7, 'prescriptions' => [], 'doctors' => [], 'allergies' => [], 'substances' => [],
            'criticalities' => [],
            'currentEpisode' => self::current(1),
            'episodeHistory' => [
                self::historyItem(1, true, null),
                self::historyItem(7, false, '05/06/2026', null, true),
            ],
            'editable' => true,
            'sections' => [[
                'id' => 'admin', 'title' => 'Administratif', 'filled' => 1, 'total' => 1, 'rows' => [[
                    'entity' => 'patient', 'field' => 'deceased', 'label' => 'Patient(e) décédé(e)',
                    'type' => 'select', 'options' => ['0' => 'Non', '1' => 'Oui'], 'computed' => false,
                    'validatedByDoctor' => true, 'help' => null, 'raw' => '1', 'value' => 'Oui',
                ]],
            ]],
        ]);

        self::assertStringContainsString(
            'Vous consultez un séjour antérieur : ce patient a un séjour en cours.',
            $html
        );
        self::assertStringContainsString('href="/sejour?id=1">', $html);
        self::assertStringContainsString('Ouvrir le séjour en cours', $html);
        self::assertStringContainsString('lit L01', $html);
        self::assertStringContainsString('href="#titre-sejours"', $html, 'entrée du menu du dossier');
        self::assertStringContainsString('id="titre-sejours"', $html);
        self::assertStringContainsString('aria-current="page">Séjour n° 2026-000007', $html);
        self::assertStringContainsString('Séjour affiché', $html);
        self::assertGreaterThanOrEqual(2, substr_count($html, '<span>Décédée</span>'), 'bandeau et résumé');
        self::assertDoesNotMatchRegularExpression(
            '/class="inline-edit"[^>]*data-field="deceased"/',
            $html,
            'décès : pas d\'édition en ligne, seulement par la partie validée'
        );
        self::assertMatchesRegularExpression('/data-field="deceased">Oui<\/dd>/', $html);
    }

    public function testRecordOfCurrentEpisodeHasNoNotice(): void
    {
        $html = View::render('pages/episode/record', [
            'banner' => ComponentsRenderTest::banner(),
            'episodeOfCare' => [
                'ongoing' => true, 'dischargeDate' => null, 'service' => null, 'bed' => null, 'box' => null,
                'precautions' => null, 'phone' => null, 'email' => null,
            ],
            'idEpisodeOfCare' => 1, 'prescriptions' => [], 'doctors' => [], 'allergies' => [], 'substances' => [],
            'criticalities' => [],
            'episodeHistory' => [self::historyItem(1, true, null, null, true)],
        ]);

        self::assertStringNotContainsString('current-episode-notice', $html);
        self::assertStringNotContainsString('deceased-tag', $html);
        self::assertStringContainsString('aria-current="page">Séjour n° 2026-000001', $html);
    }

    /** AURA-12.3 : confirmation de suppression, identité échappée ; motif du refus sans bouton de confirmation. */
    public function testPatientDeletionConfirmationAndRefusal(): void
    {
        $identity = [
            'idPatient' => 4, 'lastName' => 'Par<ker>', 'firstName' => 'Peter', 'ipp' => null,
            'birthDate' => '09/01/2001',
        ];

        $confirm = View::render('pages/patient/delete', ['identity' => $identity, 'episodes' => 2, 'refusal' => null]);
        self::assertStringContainsString('Par&lt;ker&gt;', $confirm);
        self::assertStringNotContainsString('Par<ker>', $confirm);
        self::assertStringContainsString('sans IPP', $confirm);
        self::assertStringContainsString('2 séjours dans le service', $confirm);
        self::assertStringContainsString('method="post" action="/patients/supprimer?id=4"', $confirm);
        self::assertStringContainsString('Confirmer la suppression', $confirm);
        self::assertStringContainsString('href="/patients">Annuler', $confirm);

        $refused = View::render('pages/patient/delete', [
            'identity' => $identity, 'episodes' => 1, 'refusal' => 'Suppression impossible : <motif>',
        ]);
        self::assertStringContainsString('role="alert"', $refused);
        self::assertStringContainsString('Suppression impossible : &lt;motif&gt;', $refused);
        self::assertStringNotContainsString('<form', $refused, 'pas de confirmation possible');
        self::assertStringContainsString('1 séjour dans le service', $refused);
    }

    /** Agenda du service, refonte du 2026-11-04 : tableau des lits et panneau de détail (vue par défaut). */
    public function testServiceAgendaShowsBedBoardAndDetailPanel(): void
    {
        $html = View::render('pages/service/consolidated-view', self::serviceAgendaModel('lits'));

        self::assertStringContainsString('<table class="board-table">', $html);
        self::assertStringContainsString('aria-current="page">Lits</a>', $html);
        self::assertStringContainsString('1 soin en retard', $html, 'AURA-32 : retards du service');
        self::assertStringContainsString('board-row is-selected', $html, 'premier lit détaillé par défaut');
        self::assertStringContainsString('data-row-link', $html, 'toute la ligne est cliquable (agenda.js)');
        self::assertStringContainsString('href="/agenda-service?idHealthcareService=SRV01&amp;date=2026-10-14'
            . '&amp;sejour=1#detail-lit"', $html);
        self::assertStringContainsString('id="detail-lit"', $html);
        self::assertStringContainsString('allergy-tag--high', $html, 'RG2 : allergies dans le panneau');
        self::assertStringContainsString('Latex &lt;x&gt;', $html);
        self::assertStringContainsString('réf. Dr &lt;Ref&gt;', $html);
        self::assertStringContainsString('Soin &lt;A&gt;', $html);
        self::assertStringNotContainsString('Soin <A>', $html);
        self::assertStringContainsString('par Soignant &lt;Test&gt;', $html);
        self::assertStringContainsString('data-care-action="done" data-appointment="S1"', $html);
        self::assertStringContainsString('action="/agenda-patient/soin"', $html);
        self::assertStringContainsString('href="/agenda-patient?id=1&amp;date=2026-10-14"', $html);
        self::assertStringNotContainsString('service-timeline', $html, 'l\'ancienne grille a disparu');
    }

    public function testServiceAgendaWorklistGroupsCaresBySection(): void
    {
        $html = View::render('pages/service/consolidated-view', self::serviceAgendaModel('liste'));

        self::assertStringContainsString('aria-current="page">Liste de travail</a>', $html);
        self::assertStringContainsString('class="worklist worklist--late" open', $html);
        self::assertStringContainsString('class="worklist worklist--done">', $html, 'réalisés repliés');
        self::assertStringContainsString('Lit L01, DUPONT Test', $html);
        self::assertStringContainsString(
            '<input type="hidden" name="vue" value="liste">',
            $html,
            'la vue reste choisie'
        );
        self::assertStringContainsString('name="back" value="/agenda-service?idHealthcareService=SRV01&amp;'
            . 'date=2026-10-14&amp;vue=liste&amp;sejour=1"', $html);
    }

    /** Relecture du 2026-11-04 : retards montrés sur un jour passé ; allergies seulement dans le panneau du lit. */
    public function testServiceWorklistShowsLateCaresOfAPastDayWithoutAllergies(): void
    {
        $model = self::serviceAgendaModel('liste');
        $model['service']['isToday'] = false;
        $model['board'] = AgendaService::presentBoard($model['service'], new DateTimeImmutable('2026-10-15 10:30'));

        $html = View::render('pages/service/consolidated-view', $model);

        self::assertStringContainsString('class="worklist worklist--late" open', $html);
        self::assertStringContainsString('Soin &lt;A&gt;', $html, 'le retard d\'un jour passé reste visible');
        // Choix du 2026-11-04 : la liste de travail ne répète pas les allergies (panneau du lit seulement).
        self::assertStringNotContainsString('allergy-tag', $html);
    }

    public function testConsolidatedViewWithoutPatient(): void
    {
        $service = ['date' => '2026-10-14', 'dateLabel' => 'mercredi 14 octobre 2026', 'patients' => []];
        $html = View::render('pages/service/consolidated-view', [
            'service' => $service,
            'board' => AgendaService::presentBoard($service, new DateTimeImmutable('2026-10-14 10:30')),
        ]);

        self::assertStringContainsString('Aucun patient en cours dans ce service.', $html);
    }

    /** @return array<string, mixed> */
    private static function serviceAgendaModel(string $view): array
    {
        $late = [
            'id' => 'S1', 'time' => '06:30', 'datetime' => '2026-10-14T06:30', 'hour' => 6, 'label' => 'Soin <A>',
            'details' => null, 'status' => 'late', 'performedBy' => null, 'canValidate' => true,
        ];
        $done = ['id' => 'S2', 'time' => '07:00', 'datetime' => '2026-10-14T07:00', 'status' => 'done',
            'performedBy' => 'Soignant <Test>', 'completedAt' => '07:05', 'canValidate' => false] + $late;
        $service = [
            'date' => '2026-10-14',
            'dateLabel' => 'mercredi 14 octobre 2026',
            'isToday' => true,
            'previous' => '2026-10-13',
            'next' => '2026-10-15',
            'patients' => [[
                'idEpisodeOfCare' => 1, 'lastName' => 'DUPONT', 'firstName' => 'Test', 'bed' => 'L01',
                'episodeNumber' => '2026-000001', 'referent' => 'Dr <Ref>', 'referentKnown' => true,
                'deceased' => null,
                'allergies' => [['label' => 'Latex <x>', 'level' => 'high']],
                'items' => [$late, $done],
            ]],
        ];

        return [
            'service' => $service,
            'board' => AgendaService::presentBoard($service, new DateTimeImmutable('2026-10-14 10:30')),
            'view' => $view,
            'services' => ['SRV01' => 'Réanimation'],
            'idHealthcareService' => 'SRV01',
            'validationEnabled' => true,
            'validators' => ['PRA01' => 'Soignant Test (IDE)'],
            'csrfToken' => 'jeton',
        ];
    }

    // ---- Fabriques ----

    /** @return array<string, mixed> */
    /** @return array<string, mixed> ligne de PatientListService::presentLine() */
    private static function listLine(
        int $idPatient = 1,
        string $lastName = 'DUPONT',
        bool $deceased = false,
        ?array $current = null,
        ?array $record = null,
        array $history = []
    ): array {
        return [
            'idPatient' => $idPatient, 'lastName' => $lastName, 'firstName' => 'Test', 'ipp' => null,
            'birthDate' => null, 'deceased' => $deceased, 'deceasedLabel' => $deceased ? 'Décédée' : null,
            'current' => $current, 'record' => $record, 'history' => $history,
        ];
    }

    /** @return array<string, mixed> séjour en cours, EpisodeHistoryService::presentCurrent() */
    private static function current(int $id): array
    {
        return [
            'idEpisodeOfCare' => $id, 'episodeNumber' => sprintf('2026-%06d', $id), 'bed' => 'L01', 'box' => null,
            'service' => 'Réa <médicale>', 'since' => '10/10/2026',
        ];
    }

    /** @return array<string, mixed> séjour de EpisodeHistoryService::present() */
    private static function historyItem(
        int $id,
        bool $ongoing,
        ?string $discharge,
        ?string $reason = null,
        bool $displayed = false
    ): array {
        return [
            'idEpisodeOfCare' => $id, 'episodeNumber' => sprintf('2026-%06d', $id), 'admissionDate' => '02/06/2026',
            'dischargeDate' => $discharge, 'ongoing' => $ongoing, 'service' => 'Soins continus',
            'admissionReason' => $reason, 'displayed' => $displayed,
        ];
    }

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
            'status' => $status,
            'completedAt' => $completedAt,
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
