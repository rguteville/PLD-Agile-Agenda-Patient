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
    public function testLayoutCompletContientLienDEvitementThemeEtTitreEchappe(): void
    {
        ob_start();
        View::page('erreur', ['code' => 404, 'message' => 'Page <introuvable>'], 'AURA - <Erreur>');
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<html lang="fr">', $html);
        self::assertStringContainsString('<title>AURA - &lt;Erreur&gt;</title>', $html);
        self::assertStringContainsString('name="color-scheme" content="light dark"', $html);
        self::assertStringContainsString('<script src="/js/theme.js"></script>', $html);
        self::assertStringContainsString('class="skip-link" href="#main"', $html);
        self::assertStringContainsString('id="theme-toggle"', $html);
        self::assertStringContainsString('<main id="main" tabindex="-1">', $html);
        self::assertStringContainsString('Page &lt;introuvable&gt;', $html);
        self::assertStringContainsString('role="alert"', $html);
    }

    public function testAgendaVideAfficheUnEtatVideEtLesQuatreRotations(): void
    {
        $html = View::render('pages/sejour/agenda-patient', [
            'bandeau' => ComponentsRenderTest::bandeau(),
            'idEpisodeOfCare' => 1,
            'agenda' => self::agenda(),
        ]);

        self::assertStringContainsString('class="bandeau-patient"', $html);
        self::assertStringContainsString('Aucun soin ni médicament prévu ce jour.', $html);
        self::assertStringContainsString('Mercredi 14 octobre 2026', $html);
        self::assertStringContainsString('href="/agenda-patient?id=1&amp;date=2026-10-13"', $html);
        self::assertStringNotContainsString('agenda-section--late', $html);
        self::assertStringNotContainsString('<dialog', $html, 'validation désactivée : pas de dialogue');
    }

    public function testAgendaAvecRetardEpingleEtValidationActivee(): void
    {
        $late = self::item('S1', '08:00', 'late');
        $done = self::item('S2', '09:00', 'done', completedAt: '09:10', comment: 'Note <test>');
        $agenda = self::agenda(late: [$late], shiftItems: [1 => [$done]], counts: [
            'total' => 2, 'late' => 1, 'done' => 1, 'planned' => 0,
        ]);

        $html = View::render('pages/sejour/agenda-patient', [
            'bandeau' => ComponentsRenderTest::bandeau(),
            'idEpisodeOfCare' => 1,
            'agenda' => $agenda,
            'validationEnabled' => true,
            'validators' => ['PRA01' => 'Soignant Test (IDE)'],
        ]);

        self::assertStringContainsString('agenda-section--late', $html);
        self::assertSame(1, substr_count($html, 'data-appointment="S1"'), 'le retard n\'apparaît qu\'une fois');
        self::assertStringContainsString('badge--late', $html);
        self::assertStringContainsString('badge--done', $html);
        self::assertStringContainsString('à 09:10', $html, 'traçabilité : heure de réalisation');
        self::assertStringContainsString('Note &lt;test&gt;', $html);
        self::assertStringContainsString('<dialog id="validation-dialog"', $html);
        self::assertStringContainsString('Soignant Test (IDE)', $html);
        self::assertStringContainsString('data-validate', $html);
        self::assertStringContainsString('<time datetime="2026-10-14T08:00">08:00</time>', $html);
    }

    public function testListeDesPrescriptionsAvecLesTroisStatuts(): void
    {
        $html = View::render('pages/careplan/list', [
            'banner' => ComponentsRenderTest::bandeau(),
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

    public function testListeDesPrescriptionsVideSejourClos(): void
    {
        $html = View::render('pages/careplan/list', [
            'banner' => ComponentsRenderTest::bandeau(),
            'idEpisodeOfCare' => 1,
            'isOpen' => false,
            'lines' => [],
        ]);

        self::assertStringContainsString('Aucune prescription pour ce séjour.', $html);
        self::assertStringContainsString('Le séjour est clos.', $html);
        self::assertStringNotContainsString('Nouvelle prescription', $html);
    }

    public function testDossierDuSejourSansPrescription(): void
    {
        $html = View::render('pages/sejour/dossier', [
            'bandeau' => ComponentsRenderTest::bandeau(),
            'sejour' => [
                'enCours' => true, 'dateSortie' => null, 'service' => 'Réa <1>', 'lit' => 'L01', 'box' => null,
                'poids' => '62,5 kg', 'taille' => null, 'precautions' => null, 'telephone' => null, 'email' => null,
            ],
            'idSejour' => 1,
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

    public function testListeDesPatientsVideEtAvecRecherche(): void
    {
        $vide = View::render('pages/patient/list', ['patients' => [], 'recherche' => 'zz<q>']);
        self::assertStringContainsString('Aucun patient ne correspond à « zz&lt;q&gt; »', $vide);

        $pleine = View::render('pages/patient/list', ['patients' => [[
            'idPatient' => 1, 'nom' => 'DUPONT', 'prenom' => 'Test', 'ipp' => null, 'dateNaissance' => null,
            'idSejour' => 7, 'numeroSejour' => '2026-000007',
        ]], 'recherche' => null]);
        self::assertStringContainsString('href="/sejour?id=7"', $pleine);
        self::assertStringContainsString('aria-label="Dossier de DUPONT Test"', $pleine);
        self::assertStringContainsString('<th scope="col">', $pleine);
    }

    public function testVueConsolideeDuServiceAffichePatientsCreneauxEtRetards(): void
    {
        $html = View::render('pages/service/vue-consolidee', ['service' => [
            'date' => '2026-10-14',
            'dateLabel' => 'mercredi 14 octobre 2026',
            'slots' => ['06:00', '08:00'],
            'patients' => [[
                'idSejour' => 1, 'nom' => 'DUPONT', 'prenom' => 'Test', 'lit' => 'L01',
                'numeroSejour' => '2026-000001', 'late' => 2,
                'cells' => ['06:00' => ['done' => 1, 'late' => 2]],
            ]],
        ]]);

        self::assertStringContainsString('<th scope="row" class="col-patient">', $html);
        self::assertStringContainsString('href="/agenda-patient?id=1&amp;date=2026-10-14"', $html);
        self::assertStringContainsString('2 en retard', $html);
        self::assertStringContainsString('Rien de prévu', $html, 'créneau vide annoncé aux lecteurs d\'écran');
        self::assertStringContainsString('aria-label="Légende"', $html);
    }

    public function testVueConsolideeSansPatient(): void
    {
        $html = View::render('pages/service/vue-consolidee', ['service' => [
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
