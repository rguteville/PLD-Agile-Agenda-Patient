<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\AgendaService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Agenda du service, refonte du 2026-11-04 : tableau des lits trié par urgence, panneau du lit choisi et
 * liste de travail de la rotation (AgendaService::presentBoard(), pur).
 */
final class AgendaServicePresentBoardTest extends TestCase
{
    private const NOW = '2026-10-14 10:30:00';

    public function testBedsAreSortedByLateThenToDoThenBed(): void
    {
        $board = AgendaService::presentBoard($this->service(), new DateTimeImmutable(self::NOW));

        self::assertSame(['L10', 'L2', 'L1'], array_column($board['rows'], 'bed'), 'retards d\'abord, puis à faire');
        self::assertSame(
            ['late' => 2, 'todo' => 1],
            ['late' => $board['rows'][0]['late'], 'todo' => $board['rows'][0]['todo']]
        );
        self::assertSame(['patients' => 3, 'late' => 2, 'todo' => 3], $board['totals']);
        self::assertSame('6 h à 14 h', $board['rotation']['label']);
    }

    public function testToDoCountsOnlyTheCurrentShiftAndNextIsTheFirstPlannedCare(): void
    {
        $board = AgendaService::presentBoard($this->service(), new DateTimeImmutable(self::NOW));
        $l2 = $this->row($board, 'L2');

        self::assertSame(2, $l2['todo'], '11 h et 13 h ; pas le soin de 20 h (rotation suivante)');
        self::assertSame(['time' => '11:00', 'label' => 'Soin 11h'], $l2['next']);
        self::assertNull($this->row($board, 'L1')['next'], 'aucun soin prévu');
    }

    public function testFirstBedIsDetailedByDefaultAndSelectionIsKept(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $default = AgendaService::presentBoard($this->service(), $now);
        self::assertSame(10, $default['selected']['idEpisodeOfCare']);
        self::assertSame(
            ['07:00', '08:00', '09:00', '12:00'],
            array_column($default['selected']['items'], 'time'),
            'retards et soins de la rotation ; pas le soin fait à 5 h (rotation précédente)'
        );

        $chosen = AgendaService::presentBoard($this->service(), $now, 2);
        self::assertSame(2, $chosen['selected']['idEpisodeOfCare']);
        self::assertSame(['11:00', '13:00'], array_column($chosen['selected']['items'], 'time'), 'rotation seulement');

        $unknown = AgendaService::presentBoard($this->service(), $now, 999);
        self::assertNull($unknown['selected'], 'séjour clos ou d\'un autre service : pas de panneau trompeur');
        self::assertTrue($unknown['selectionMissing']);
        self::assertFalse($default['selectionMissing']);
    }

    /** À égalité de retards, le plus ancien retard d'abord ; un patient sans lit en dernier. */
    public function testOldestLateBreaksTiesAndBedlessPatientComesLast(): void
    {
        $service = ['isToday' => true, 'patients' => [
            $this->patient(1, 'L1', [$this->item('03:00', 'late')]),
            $this->patient(2, 'L2', [$this->item('10:25', 'late')]),
            $this->patient(3, null, []),
            $this->patient(4, 'L4', []),
        ]];

        $board = AgendaService::presentBoard($service, new DateTimeImmutable(self::NOW));

        self::assertSame(['L1', 'L2', 'L4', null], array_column($board['rows'], 'bed'));
    }

    /** « Maintenant » s'arrête à la fin de la rotation : à 13 h, un soin de 14 h 30 est de la rotation suivante. */
    public function testNowDoesNotSpillIntoTheNextShift(): void
    {
        $service = ['isToday' => true, 'patients' => [
            $this->patient(1, 'L1', [$this->item('13:30', 'planned'), $this->item('14:30', 'planned')]),
        ]];

        $board = AgendaService::presentBoard($service, new DateTimeImmutable('2026-10-14 13:00:00'));

        self::assertSame(['13:30'], array_column($board['worklist']['now'], 'time'));
        self::assertSame([], $board['worklist']['later']);
        self::assertSame('14:00', $board['rotation']['until']);
        self::assertSame(1, $board['totals']['todo']);
    }

    /** Dose d'une ligne arrêtée, ni faite ni annulée : listée à part et comptée à faire, jamais perdue. */
    public function testStoppedLineDoseIsListedAndCounted(): void
    {
        $service = ['isToday' => true, 'patients' => [
            $this->patient(1, 'L1', [$this->item('09:00', 'stopped'), $this->item('12:00', 'cancelled')]),
        ]];

        $board = AgendaService::presentBoard($service, new DateTimeImmutable('2026-10-14 15:00:00'));

        self::assertSame(['09:00'], array_column($board['worklist']['stopped'], 'time'));
        self::assertSame(1, $board['rows'][0]['todo']);
        self::assertContains('09:00', array_column($board['selected']['items'], 'time'), 'hors rotation, mais visible');
    }

    /** La nuit est bornée au jour affiché : « 22 h à 0 h » le soir, « 0 h à 6 h » après minuit. */
    public function testNightShiftIsClippedToTheDisplayedDay(): void
    {
        $service = ['isToday' => true, 'date' => '2026-10-14', 'patients' => [
            $this->patient(1, 'L1', [$this->item('23:30', 'planned')]),
        ]];

        $evening = AgendaService::presentBoard($service, new DateTimeImmutable('2026-10-14 23:00:00'));
        self::assertSame('22 h à 0 h', $evening['rotation']['label']);
        self::assertSame('00:00', $evening['rotation']['until']);
        self::assertSame(['23:30'], array_column($evening['worklist']['now'], 'time'));

        $service['date'] = '2026-10-15';
        $night = AgendaService::presentBoard($service, new DateTimeImmutable('2026-10-15 02:00:00'));
        self::assertSame('0 h à 6 h', $night['rotation']['label']);
    }

    /**
     * Chaque soin de la liste de travail porte, dans le vue-modèle, les allergies (RG2) et le décès de son
     * patient. Depuis le 2026-11-04, l'écran n'affiche les allergies que dans le panneau du patient choisi.
     */
    public function testWorklistItemsCarryAllergiesAndDeath(): void
    {
        $patient = $this->patient(1, 'L1', [$this->item('08:00', 'late')]);
        $patient['allergies'] = [['label' => 'Pénicilline', 'level' => 'high']];
        $patient['deceased'] = 'Décédé';

        $board = AgendaService::presentBoard(
            ['isToday' => true, 'patients' => [$patient]],
            new DateTimeImmutable(self::NOW)
        );

        self::assertSame('Pénicilline', $board['worklist']['late'][0]['allergies'][0]['label']);
        self::assertSame('Décédé', $board['worklist']['late'][0]['deceased']);
    }

    public function testWorklistSplitsLateNowLaterAndDone(): void
    {
        $board = AgendaService::presentBoard($this->service(), new DateTimeImmutable(self::NOW));
        $times = static fn (string $key): array => array_column($board['worklist'][$key], 'time');

        self::assertSame(['07:00', '08:00'], $times('late'));
        self::assertSame(['11:00', '12:00'], $times('now'), 'dans les deux heures');
        self::assertSame(['13:00'], $times('later'), 'plus tard dans la rotation, pas 20 h');
        self::assertSame(['09:00', '06:30'], $times('done'), 'du plus récent au plus ancien');
        self::assertSame('L10', $board['worklist']['late'][0]['bed'], 'chaque soin porte son patient');
        self::assertSame('12:30', $board['rotation']['until']);
    }

    public function testAnotherDayHasNoShiftAndListsTheWholeDay(): void
    {
        $service = ['isToday' => false] + $this->service();
        $board = AgendaService::presentBoard($service, new DateTimeImmutable(self::NOW));

        self::assertNull($board['rotation']);
        self::assertSame(
            ['07:00', '08:00'],
            array_column($board['worklist']['late'], 'time'),
            'retards toujours listés'
        );
        self::assertSame([], $board['worklist']['now']);
        self::assertSame(['11:00', '12:00', '13:00', '20:00'], array_column($board['worklist']['later'], 'time'));
        self::assertSame(3, $this->row($board, 'L2')['todo'], 'tous les soins prévus du jour');
    }

    public function testEmptyServiceHasNoSelection(): void
    {
        $board = AgendaService::presentBoard(['isToday' => true, 'patients' => []], new DateTimeImmutable(self::NOW));

        self::assertSame([], $board['rows']);
        self::assertNull($board['selected']);
        self::assertSame(['patients' => 0, 'late' => 0, 'todo' => 0], $board['totals']);
    }

    /** @return array<string, mixed> */
    private function row(array $board, string $bed): array
    {
        foreach ($board['rows'] as $row) {
            if ($row['bed'] === $bed) {
                return $row;
            }
        }
        self::fail('Lit ' . $bed . ' absent.');
    }

    /** @return array<string, mixed> soin minimal de presentItem() */
    private function item(string $time, string $status, string $day = '2026-10-14'): array
    {
        return [
            'id' => 'A' . $time, 'time' => $time, 'datetime' => $day . 'T' . $time, 'status' => $status,
            'label' => 'Soin ' . (int) substr($time, 0, 2) . 'h', 'details' => null,
        ];
    }

    /** @return array<string, mixed> patient minimal de presentService() */
    private function patient(int $id, ?string $bed, array $items): array
    {
        return [
            'idEpisodeOfCare' => $id, 'lastName' => 'Zz' . $bed, 'firstName' => 'Test', 'bed' => $bed,
            'episodeNumber' => '2026-00000' . $id, 'referent' => null, 'referentKnown' => true, 'deceased' => null,
            'allergies' => [], 'items' => ['00:00' => $items],
        ];
    }

    /** Sortie minimale de presentService() : trois patients, le 14/10/2026 à 10 h 30 (rotation 6 h – 14 h). */
    private function service(): array
    {
        $item = fn (string $time, string $status): array => $this->item($time, $status);
        $patient = fn (int $id, string $bed, array $items): array => $this->patient($id, $bed, $items);

        return [
            'isToday' => true,
            'patients' => [
                $patient(1, 'L1', [$item('06:30', 'done')]),
                $patient(2, 'L2', [$item('13:00', 'planned'), $item('11:00', 'planned'), $item('20:00', 'planned')]),
                $patient(10, 'L10', [
                    $item('07:00', 'late'), $item('08:00', 'late'), $item('09:00', 'done'),
                    $item('12:00', 'planned'), $item('05:00', 'done'),
                ]),
            ],
        ];
    }
}
