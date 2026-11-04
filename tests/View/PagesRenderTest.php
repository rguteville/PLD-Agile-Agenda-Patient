<?php

declare(strict_types=1);

namespace Aura\Tests\View;

use Aura\Core\View;
use Aura\Domain\CarePlan;
use Aura\Service\AgendaService;
use PHPUnit\Framework\TestCase;

/**
 * Rendu de deux écrans essentiels avec un vue-modèle fixe (sans base) : le retard en tête de l'agenda
 * (RG8) et l'allergène signalé dans la liste des prescriptions, données échappées.
 */
final class PagesRenderTest extends TestCase
{
    public function testLateCareIsShownOnceAtTheTopOfTheAgenda(): void
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

        self::assertStringContainsString('agenda-section--late', $html, 'section des retards en tête');
        self::assertSame(
            1,
            substr_count($html, 'data-care-action="done" data-appointment="S1"'),
            'le retard n\'apparaît qu\'une fois'
        );
        self::assertStringContainsString('à 09:10', $html, 'traçabilité : heure de réalisation');
        self::assertStringContainsString('Note &lt;test&gt;', $html);
    }

    /** Note de l'ordonnance et allergène signalés (et échappés) dans la liste. */
    public function testPrescriptionListFlagsThePatientAllergen(): void
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

    // ---- Fabriques ----

    private static function agenda(array $late = [], array $shiftItems = [], ?array $counts = null): array
    {
        $shifts = [];
        foreach (AgendaService::daySections() as $index => $shift) {
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
