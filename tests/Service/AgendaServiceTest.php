<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\AgendaService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Agenda du patient et du service (RG8), présentateurs purs sans base : retard dès l'heure passée, soin de
 * la veille reporté, rotations, fin de la rotation en cours, ligne arrêtée, ordonnance non validée (RG5).
 * Le 14/10/2026 à 10 h 30 ; soins de substitution évidents (« Soin A »).
 */
final class AgendaServiceTest extends TestCase
{
    private const DAY = '2026-10-14';
    private const NOW = '2026-10-14 10:30:00';

    // ---- Agenda du jour d'un patient ----

    public function testPlannedCarePastItsTimeIsLateAndPinnedOnTop(): void
    {
        $plans = [$this->plan([$this->line('Soin A', [
            $this->appointment('S1', '2026-10-14 08:00:00', 'PLANIFIE'),
            $this->appointment('S2', '2026-10-14 12:00:00', 'PLANIFIE'),
        ])])];

        $agenda = AgendaService::presentDay($plans, $this->day(), $this->now());

        self::assertSame(['S1'], array_column($agenda['late'], 'id'));
        self::assertSame('late', $agenda['late'][0]['status']);
        self::assertSame(['S2'], array_column($agenda['shifts'][1]['items'], 'id'), 'à venir : reste dans sa rotation');
        self::assertSame('planned', $agenda['shifts'][1]['items'][0]['status']);
    }

    public function testOnlyAPlannedCareCanBeLate(): void
    {
        $plans = [$this->plan([$this->line('Soin A', [
            $this->appointment('S1', '2026-10-14 07:00:00', 'REALISE', '2026-10-14 07:05:00'),
            $this->appointment('S2', '2026-10-14 08:00:00', 'NON_FAIT'),
            $this->appointment('S3', '2026-10-14 09:00:00', 'ANNULE'),
        ])])];

        $agenda = AgendaService::presentDay($plans, $this->day(), $this->now());

        self::assertSame([], $agenda['late']);
        self::assertSame(['done', 'not_done', 'cancelled'], array_column($agenda['shifts'][1]['items'], 'status'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function hoursAndShifts(): iterable
    {
        yield '5 h 59 : nuit (fin)' => ['2026-10-14 05:59:00', 0];
        yield '6 h : matin' => ['2026-10-14 06:00:00', 1];
        yield '14 h : après-midi' => ['2026-10-14 14:00:00', 2];
        yield '22 h : nuit (début)' => ['2026-10-14 22:00:00', 3];
    }

    #[DataProvider('hoursAndShifts')]
    public function testCareIsShownInItsRotation(string $plannedTime, int $expectedShift): void
    {
        // Statut REALISE : jamais en retard, donc toujours dans sa rotation.
        $plans = [$this->plan([$this->line('Soin A', [
            $this->appointment('S1', $plannedTime, 'REALISE', '2026-10-14 09:00:00'),
        ])])];

        $agenda = AgendaService::presentDay($plans, $this->day(), $this->now());

        foreach ($agenda['shifts'] as $index => $shift) {
            self::assertCount($index === $expectedShift ? 1 : 0, $shift['items'], $shift['label']);
        }
    }

    /**
     * Une dose de la veille encore prévue reste en retard aujourd'hui au lieu de disparaître à minuit ; une
     * dose plus ancienne, faite ou d'une ligne arrêtée, non.
     */
    public function testOverdueCareOfYesterdayIsCarriedIntoTodayLateList(): void
    {
        $plans = [$this->plan([
            $this->line('Soin A', [
                $this->appointment('VEILLE', '2026-10-13 23:00:00', 'PLANIFIE'),
                $this->appointment('FAIT', '2026-10-13 22:00:00', 'REALISE', '2026-10-13 22:05:00'),
                $this->appointment('AVANT-HIER', '2026-10-12 23:00:00', 'PLANIFIE'),
            ]),
            $this->line('Soin B', [$this->appointment('ARRET', '2026-10-13 21:00:00', 'PLANIFIE')], [
                'stoppedAt' => new DateTimeImmutable('2026-10-13 20:00:00'),
            ]),
        ])];

        $agenda = AgendaService::presentDay($plans, $this->day(), $this->now(), carryOverdue: true);

        self::assertSame(['VEILLE'], array_column($agenda['late'], 'id'));
        self::assertSame('23:00 (veille)', $agenda['late'][0]['time']);
    }

    /**
     * Ligne arrêtée : ni à faire ni en retard, mais une dose faite avant l'arrêt reste traçable. Un soin de la
     * rotation en cours se valide en avance, pas un soin d'une rotation à venir. RG5 : ordonnance non validée
     * signalée sur chaque soin.
     */
    public function testStoppedLineAndUnvalidatedPrescriptionAreFlagged(): void
    {
        $plans = [[
            'idCarePlan' => '1',
            'validatedAt' => null,
            'serviceRequests' => [
                $this->line('Arrêtée', [$this->appointment('S', '2026-10-14 09:00:00', 'PLANIFIE')], [
                    'stoppedAt' => '2026-10-14 09:30',
                ]),
                $this->line('Active', [
                    $this->appointment('SOON', '2026-10-14 11:15:00', 'PLANIFIE'),
                    $this->appointment('LATER', '2026-10-14 20:00:00', 'PLANIFIE'),
                ], ['stoppedAt' => null]),
            ],
            'medicationRequests' => [],
        ]];

        $agenda = AgendaService::presentDay($plans, $this->day(), $this->now());
        $items = [];
        foreach ([$agenda['late'], ...array_column($agenda['shifts'], 'items')] as $list) {
            foreach ($list as $item) {
                $items[$item['id']] = $item;
            }
        }

        self::assertSame('stopped', $items['S']['status']);
        self::assertSame(0, $agenda['counts']['late']);
        self::assertTrue($items['S']['canValidate'], 'dose faite avant l\'arrêt');
        self::assertTrue($items['SOON']['canValidate'], 'rotation en cours (matin)');
        self::assertFalse($items['LATER']['canValidate'], 'rotation à venir (20 h)');
        self::assertTrue($items['LATER']['notValidated'], 'RG5');
    }

    public function testMalformedDayFallsBackToToday(): void
    {
        foreach ([null, '20/10/2026', ['2026-10-20'], '2026-02-30'] as $value) {
            self::assertSame(self::DAY, AgendaService::parseDay($value, $this->now())->format('Y-m-d'));
        }
        self::assertSame('2026-10-20', AgendaService::parseDay('2026-10-20', $this->now())->format('Y-m-d'));
    }

    // ---- Fin de la rotation en cours (limite de validation d'un soin fait en avance) ----

    /** @return array<string, array{string, string}> */
    public static function instants(): array
    {
        return [
            'nuit, après minuit' => ['2026-10-14 00:30:00', '2026-10-14 06:00'],
            'après-midi' => ['2026-10-14 14:00:00', '2026-10-14 22:00'],
            'fin de mois : la nuit finit le lendemain' => ['2026-10-31 22:00:00', '2026-11-01 06:00'],
        ];
    }

    #[DataProvider('instants')]
    public function testShiftEndFollowsTheRotations(string $now, string $expected): void
    {
        self::assertSame($expected, AgendaService::shiftEnd(new DateTimeImmutable($now))->format('Y-m-d H:i'));
    }

    // ---- Agenda du service ----

    public function testBedsWithLateCareComeFirst(): void
    {
        $service = ['isToday' => true, 'patients' => [
            $this->bed(1, 'L1', [$this->item('06:30', 'done')]),
            $this->bed(2, 'L2', [$this->item('11:00', 'planned')]),
            $this->bed(10, 'L10', [$this->item('07:00', 'late'), $this->item('08:00', 'late')]),
        ]];

        $board = AgendaService::presentBoard($service, $this->now());

        self::assertSame(['L10', 'L2', 'L1'], array_column($board['rows'], 'bed'), 'retards, puis à faire');
        self::assertSame(2, $board['totals']['late']);
    }

    /** Dose d'une ligne arrêtée, ni faite ni annulée : listée à part et comptée à faire, jamais perdue. */
    public function testStoppedLineDoseIsListedAndCounted(): void
    {
        $service = ['isToday' => true, 'patients' => [
            $this->bed(1, 'L1', [$this->item('09:00', 'stopped'), $this->item('12:00', 'cancelled')]),
        ]];

        $board = AgendaService::presentBoard($service, new DateTimeImmutable('2026-10-14 15:00:00'));

        self::assertSame(['09:00'], array_column($board['worklist']['stopped'], 'time'));
        self::assertSame(1, $board['rows'][0]['todo']);
    }

    /** « Maintenant » s'arrête à la fin de la rotation : à 13 h, un soin de 14 h 30 est de la suivante. */
    public function testWorklistStopsAtTheEndOfTheCurrentShift(): void
    {
        $service = ['isToday' => true, 'patients' => [
            $this->bed(1, 'L1', [$this->item('13:30', 'planned'), $this->item('14:30', 'planned')]),
        ]];

        $board = AgendaService::presentBoard($service, new DateTimeImmutable('2026-10-14 13:00:00'));

        self::assertSame(['13:30'], array_column($board['worklist']['now'], 'time'));
        self::assertSame([], $board['worklist']['later']);
        self::assertSame(1, $board['totals']['todo']);
    }

    /** RG2 : chaque soin de la liste de travail porte les allergies et le décès de son patient. */
    public function testWorklistCareCarriesAllergiesAndDeath(): void
    {
        $patient = $this->bed(1, 'L1', [$this->item('08:00', 'late')]);
        $patient['allergies'] = [['label' => 'Pénicilline', 'level' => 'high']];
        $patient['deceased'] = 'Décédé';

        $board = AgendaService::presentBoard(['isToday' => true, 'patients' => [$patient]], $this->now());

        self::assertSame('Pénicilline', $board['worklist']['late'][0]['allergies'][0]['label']);
        self::assertSame('Décédé', $board['worklist']['late'][0]['deceased']);
    }

    // ---- Fabriques : même forme que AgendaService::getAgenda() et presentService() ----

    /** @param list<array<string, mixed>> $cares */
    private function plan(array $cares): array
    {
        return ['idCarePlan' => '1', 'serviceRequests' => $cares, 'medicationRequests' => []];
    }

    /**
     * @param list<array<string, mixed>> $appointments
     * @param array<string, mixed> $extra
     */
    private function line(string $label, array $appointments, array $extra = []): array
    {
        return ['idActivityDefinition' => $label, 'appointments' => $appointments, 'timeConstraint' => null] + $extra;
    }

    private function appointment(string $id, string $plannedTime, string $status, ?string $completedAt = null): array
    {
        return [
            'idAppointment' => $id,
            'plannedTime' => new DateTimeImmutable($plannedTime),
            'status' => $status,
            'timeOfCompletion' => $completedAt === null ? null : new DateTimeImmutable($completedAt),
            'timeRecorded' => $completedAt === null ? null : new DateTimeImmutable($completedAt),
            'comment' => null,
        ];
    }

    /** Soin minimal de presentItem(). */
    private function item(string $time, string $status): array
    {
        return [
            'id' => 'A' . $time, 'time' => $time, 'datetime' => self::DAY . 'T' . $time, 'status' => $status,
            'label' => 'Soin ' . $time, 'details' => null,
        ];
    }

    /** Patient minimal de presentService(). */
    private function bed(int $id, string $bed, array $items): array
    {
        return [
            'idEpisodeOfCare' => $id, 'lastName' => 'Zz' . $bed, 'firstName' => 'Test', 'bed' => $bed,
            'episodeNumber' => sprintf('2026-%06d', $id), 'referent' => null, 'referentKnown' => true,
            'deceased' => null, 'allergies' => [], 'items' => ['00:00' => $items],
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
