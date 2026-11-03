<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\AgendaService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AURA-UI : vue-modèle de l'agenda d'un jour (présentateur pur, sans base). Les données sont des
 * substituts évidents (« Soin A », « Médicament B »), jamais des traitements réels.
 */
final class AgendaServicePresentDayTest extends TestCase
{
    private const DAY = '2026-10-14';
    private const NOW = '2026-10-14 10:30:00';

    public function testEmptyDayWithoutAnyCare(): void
    {
        $agenda = AgendaService::presentDay([], $this->day(), $this->now());

        self::assertSame(self::DAY, $agenda['date']);
        self::assertSame('mercredi 14 octobre 2026', $agenda['dateLabel']);
        self::assertTrue($agenda['isToday']);
        self::assertSame('2026-10-13', $agenda['previous']);
        self::assertSame('2026-10-15', $agenda['next']);
        self::assertSame(['total' => 0, 'late' => 0, 'done' => 0, 'planned' => 0], $agenda['counts']);
        self::assertSame([], $agenda['late']);
        self::assertCount(4, $agenda['shifts'], 'quatre tranches : nuit (fin), matin, après-midi, nuit (début)');
        foreach ($agenda['shifts'] as $shift) {
            self::assertSame([], $shift['items']);
        }
    }

    public function testScheduledCarePastItsTimeIsLateAndPinned(): void
    {
        $plans = [$this->plan(
            cares: [$this->line('Soin A', [$this->appointment('S1', '2026-10-14 08:00:00', 'PLANIFIE')])],
        )];

        $agenda = AgendaService::presentDay($plans, $this->day(), $this->now());

        self::assertCount(1, $agenda['late']);
        self::assertSame('late', $agenda['late'][0]['status']);
        self::assertSame('08:00', $agenda['late'][0]['time']);
        self::assertSame(1, $agenda['counts']['late']);
        self::assertSame(0, $agenda['counts']['planned']);
        self::assertSame([], $agenda['shifts'][1]['items'], 'un retard épinglé ne reste pas dans sa rotation');
    }

    public function testUpcomingScheduledCareStaysInItsShift(): void
    {
        $plans = [$this->plan(
            cares: [$this->line('Soin A', [$this->appointment('S1', '2026-10-14 12:00:00', 'PLANIFIE')])],
        )];

        $agenda = AgendaService::presentDay($plans, $this->day(), $this->now());

        self::assertSame([], $agenda['late']);
        self::assertSame('morning', $agenda['shifts'][1]['key']);
        self::assertCount(1, $agenda['shifts'][1]['items']);
        self::assertSame('planned', $agenda['shifts'][1]['items'][0]['status']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function hoursAndShifts(): iterable
    {
        yield 'minuit → nuit (fin)' => ['2026-10-14 00:00:00', 0];
        yield '5 h 59 → nuit (fin)' => ['2026-10-14 05:59:00', 0];
        yield '6 h → matin' => ['2026-10-14 06:00:00', 1];
        yield '13 h 59 → matin' => ['2026-10-14 13:59:00', 1];
        yield '14 h → après-midi' => ['2026-10-14 14:00:00', 2];
        yield '21 h 59 → après-midi' => ['2026-10-14 21:59:00', 2];
        yield '22 h → nuit (début)' => ['2026-10-14 22:00:00', 3];
        yield '23 h 59 → nuit (début)' => ['2026-10-14 23:59:00', 3];
    }

    #[DataProvider('hoursAndShifts')]
    public function testDistributionByShift(string $plannedTime, int $expectedShift): void
    {
        // Statut REALISE : jamais « en retard », donc toujours dans sa rotation.
        $plans = [$this->plan(
            cares: [$this->line('Soin A', [$this->appointment('S1', $plannedTime, 'REALISE', '2026-10-14 09:00:00')])],
        )];

        $agenda = AgendaService::presentDay($plans, $this->day(), $this->now());

        foreach ($agenda['shifts'] as $index => $shift) {
            self::assertCount($index === $expectedShift ? 1 : 0, $shift['items'], $shift['label']);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function statusesInDatabase(): iterable
    {
        yield 'REALISE → done' => ['REALISE', 'done'];
        yield 'ANNULE → cancelled' => ['ANNULE', 'cancelled'];
        yield 'NON_FAIT → not_done' => ['NON_FAIT', 'not_done'];
        yield 'valeur inconnue transmise telle quelle' => ['BIZARRE', 'BIZARRE'];
    }

    #[DataProvider('statusesInDatabase')]
    public function testDatabaseStatusTranslatedToBadgeKey(string $raw, string $expected): void
    {
        $plans = [$this->plan(
            cares: [$this->line('Soin A', [$this->appointment('S1', '2026-10-14 08:00:00', $raw)])],
        )];

        $agenda = AgendaService::presentDay($plans, $this->day(), $this->now());

        self::assertSame($expected, $agenda['shifts'][1]['items'][0]['status']);
        self::assertSame([], $agenda['late'], 'seul PLANIFIE peut être en retard');
    }

    public function testDoneCareKeepsCompletionTimeAndNote(): void
    {
        $plans = [$this->plan(
            cares: [$this->line('Soin A', [
                $this->appointment('S1', '2026-10-14 08:00:00', 'REALISE', '2026-10-14 08:20:00', 'Note de test'),
            ])],
        )];

        $item = AgendaService::presentDay($plans, $this->day(), $this->now())['shifts'][1]['items'][0];

        self::assertSame('08:20', $item['completedAt']);
        self::assertSame('Note de test', $item['comment']);
        self::assertSame('Soin', $item['kindLabel']);
        self::assertSame('IDE / AS', $item['responsible']);
    }

    public function testMedicationCarriesDoseAndRouteInDetails(): void
    {
        $plans = [$this->plan(
            medications: [$this->line('Médicament B', [
                $this->appointment('M1', '2026-10-14 18:00:00', 'PLANIFIE'),
            ], ['dose' => '1 unité', 'route' => 'IV'])],
        )];

        $item = AgendaService::presentDay($plans, $this->day(), $this->now())['shifts'][2]['items'][0];

        self::assertSame('MEDICATION', $item['kind']);
        self::assertSame('Médicament B', $item['label']);
        self::assertSame('1 unité · IV', $item['details']);
        self::assertSame('IDE', $item['responsible']);
    }

    public function testCareOfOtherDaysIsIgnored(): void
    {
        $plans = [$this->plan(
            cares: [$this->line('Soin A', [
                $this->appointment('S1', '2026-10-13 23:59:00', 'PLANIFIE'),
                $this->appointment('S2', '2026-10-15 00:00:00', 'PLANIFIE'),
            ])],
        )];

        $agenda = AgendaService::presentDay($plans, $this->day(), $this->now());

        self::assertSame(0, $agenda['counts']['total']);
    }

    public function testChronologicalSortAcrossAllLines(): void
    {
        $plans = [$this->plan(
            cares: [$this->line('Soin A', [$this->appointment('S1', '2026-10-14 16:00:00', 'PLANIFIE')])],
            medications: [$this->line('Médicament B', [$this->appointment('M1', '2026-10-14 15:00:00', 'PLANIFIE')])],
        )];

        $items = AgendaService::presentDay($plans, $this->day(), $this->now())['shifts'][2]['items'];

        self::assertSame(['15:00', '16:00'], array_column($items, 'time'));
        self::assertSame(['Médicament B', 'Soin A'], array_column($items, 'label'));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function requestedDays(): iterable
    {
        yield 'jour valide' => ['2026-10-20', '2026-10-20'];
        yield 'absent' => [null, self::DAY];
        yield 'mal formé' => ['20/10/2026', self::DAY];
        yield 'table' => [['2026-10-20'], self::DAY];
        yield 'date impossible' => ['2026-02-30', self::DAY];
    }

    #[DataProvider('requestedDays')]
    public function testParseDayFallsBackToToday(mixed $value, string $expected): void
    {
        $day = AgendaService::parseDay($value, $this->now());

        self::assertSame($expected, $day->format('Y-m-d'));
        self::assertSame('00:00:00', $day->format('H:i:s'));
    }

    // ---- Fabriques : même forme que AgendaService::getAgenda() ----

    /** @return array<string, mixed> */
    private function plan(array $cares = [], array $medications = []): array
    {
        return [
            'idCarePlan' => '1',
            'serviceRequests' => $cares,
            'medicationRequests' => $medications,
        ];
    }

    /** @return array<string, mixed> */
    private function line(string $label, array $appointments, array $extra = []): array
    {
        return ['idActivityDefinition' => $label, 'appointments' => $appointments, 'timeConstraint' => null] + $extra;
    }

    /** @return array<string, mixed> */
    private function appointment(
        string $id,
        string $plannedTime,
        string $status,
        ?string $completedAt = null,
        ?string $comment = null
    ): array {
        return [
            'idAppointment' => $id,
            'plannedTime' => new DateTimeImmutable($plannedTime),
            'status' => $status,
            'timeOfCompletion' => $completedAt === null ? null : new DateTimeImmutable($completedAt),
            'timeRecorded' => $completedAt === null ? null : new DateTimeImmutable($completedAt),
            'comment' => $comment,
        ];
    }

    private function day(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DAY);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
