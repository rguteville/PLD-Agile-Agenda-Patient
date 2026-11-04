<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Domain\CareTeam;
use Aura\Service\AgendaService;
use Aura\Service\CareTeamService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AURA-30 / AURA-31 : vacations (JOUR 8 h – 20 h, NUIT 20 h – 8 h), contrôle d'une équipe, et soins
 * d'une vacation pour « Mon planning ». Purs, sans base ; soins de substitution évidents.
 */
final class CareTeamServiceTest extends TestCase
{
    private const PRACTITIONERS = ['PRA04' => 'Thomas Girard (IDE)', 'PRA06' => 'Julien Perrin (AS)'];
    private const EPISODES = [1 => 'Lit L01, SIMPSON Marge', 2 => 'Lit L02, SKYWALKER Luke'];

    /** @return iterable<string, array{string, string, string}> */
    public static function moments(): iterable
    {
        // Trois rotations (décision du 2026-11-04) : 6 h, 14 h, 22 h ; la nuit est datée de son début.
        yield 'début du matin' => ['2026-10-14 06:00:00', '2026-10-14', 'MATIN'];
        yield 'matin' => ['2026-10-14 09:00:00', '2026-10-14', 'MATIN'];
        yield 'début d\'après-midi' => ['2026-10-14 14:00:00', '2026-10-14', 'APRES_MIDI'];
        yield 'soir' => ['2026-10-14 21:59:00', '2026-10-14', 'APRES_MIDI'];
        yield 'début de nuit' => ['2026-10-14 22:00:00', '2026-10-14', 'NUIT'];
        yield 'nuit après minuit' => ['2026-10-15 03:00:00', '2026-10-14', 'NUIT'];
        yield 'fin de nuit' => ['2026-10-15 05:59:00', '2026-10-14', 'NUIT'];
    }

    #[DataProvider('moments')]
    public function testCurrentShiftCarriesTheDateOfItsStart(string $now, string $date, string $shift): void
    {
        $current = CareTeamService::currentShift(new DateTimeImmutable($now));

        self::assertSame($date, $current['date']->format('Y-m-d'));
        self::assertSame($shift, $current['shift']);
    }

    public function testNightWindowEndsTheNextMorning(): void
    {
        [$start, $end] = CareTeamService::window(new DateTimeImmutable('2026-10-14'), CareTeam::SHIFT_NIGHT);

        self::assertSame('2026-10-14 22:00', $start->format('Y-m-d H:i'));
        self::assertSame('2026-10-15 06:00', $end->format('Y-m-d H:i'));
    }

    /** Navigation : avant le matin, la nuit de la veille ; après la nuit, le matin du lendemain. */
    public function testNeighboursCycleThroughTheThreeShifts(): void
    {
        $day = new DateTimeImmutable('2026-10-14');
        $fmt = static fn (array $at): string => $at[0]->format('Y-m-d') . ' ' . $at[1];

        $morning = CareTeamService::neighbours($day, CareTeam::SHIFT_MORNING);
        self::assertSame('2026-10-13 NUIT', $fmt($morning['previous']));
        self::assertSame('2026-10-14 APRES_MIDI', $fmt($morning['next']));
        $night = CareTeamService::neighbours($day, CareTeam::SHIFT_NIGHT);
        self::assertSame('2026-10-14 APRES_MIDI', $fmt($night['previous']));
        self::assertSame('2026-10-15 MATIN', $fmt($night['next']));
    }

    /** Une seule référence : la fin de rotation de l'agenda suit les rotations des équipes. */
    public function testAgendaShiftEndUsesTheSameRotations(): void
    {
        foreach (CareTeamService::SHIFTS as $shift => $definition) {
            [$start, $end] = CareTeamService::window(new DateTimeImmutable('2026-10-14'), $shift);
            self::assertEquals($end, AgendaService::shiftEnd($start), $shift);
        }
    }

    public function testShiftIsReadCaseInsensitively(): void
    {
        self::assertSame('NUIT', CareTeamService::parseShift('nuit'));
        self::assertSame('MATIN', CareTeamService::parseShift(' Matin '));
        self::assertSame('APRES_MIDI', CareTeamService::parseShift('apres-midi'));
        self::assertSame('APRES_MIDI', CareTeamService::parseShift('après-midi'));
        self::assertSame('apres-midi', CareTeamService::urlValue(CareTeam::SHIFT_AFTERNOON));
        self::assertNull(CareTeamService::parseShift('jour'), 'l\'ancienne vacation de jour n\'existe plus');
        self::assertNull(CareTeamService::parseShift(['MATIN']));
    }

    public function testCompleteTeamGivesNoError(): void
    {
        $input = ['name' => 'Équipe C', 'members' => ['PRA04', 'PRA06'], 'patients' => ['2']];

        self::assertSame([], CareTeamService::validate($input, self::PRACTITIONERS, self::EPISODES, []));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidTeams(): iterable
    {
        $base = ['name' => 'Équipe C', 'members' => ['PRA04'], 'patients' => []];
        yield 'nom vide' => [['name' => '  '] + $base, 'name'];
        yield 'nom trop long' => [['name' => str_repeat('a', 31)] + $base, 'name'];
        yield 'nom déjà pris (casse ignorée)' => [['name' => 'équipe a'] + $base, 'name'];
        yield 'sans soignant' => [['members' => []] + $base, 'members'];
        yield 'soignant inconnu' => [['members' => ['ZZZ']] + $base, 'members'];
        yield 'patient hors service' => [['patients' => ['99']] + $base, 'patients'];
        yield 'patient déjà dans une équipe' => [['patients' => ['1']] + $base, 'patients'];
    }

    #[DataProvider('invalidTeams')]
    public function testInvalidTeamGivesErrorOnThatField(array $input, string $field): void
    {
        $existing = [new CareTeam(1, 'SRV01', new DateTimeImmutable('2026-10-14'), 'MATIN', 'Équipe A', [], [1])];

        $errors = CareTeamService::validate($input, self::PRACTITIONERS, self::EPISODES, $existing);

        self::assertArrayHasKey($field, $errors);
    }

    public function testNightShiftCollectsCareOfBothCalendarDays(): void
    {
        $episodes = [[
            'idEpisodeOfCare' => 1, 'lastName' => 'Simpson', 'firstName' => 'Marge', 'bed' => 'L01', 'allergies' => [],
            'carePlans' => [[
                'idCarePlan' => '1',
                'serviceRequests' => [['idActivityDefinition' => 'Soin A', 'timeConstraint' => null, 'appointments' => [
                    $this->appointment('A', '2026-10-14 19:00:00'),
                    $this->appointment('B', '2026-10-14 22:00:00'),
                    $this->appointment('C', '2026-10-15 02:00:00'),
                    $this->appointment('D', '2026-10-15 08:00:00'),
                ]]],
                'medicationRequests' => [],
            ]],
        ]];
        [$start, $end] = CareTeamService::window(new DateTimeImmutable('2026-10-14'), CareTeam::SHIFT_NIGHT);

        $shift = AgendaService::presentShift($episodes, $start, $end, new DateTimeImmutable('2026-10-15 01:00:00'));

        // 20 h inclus, 8 h exclu ; la dose de 19 h, non faite, est reprise de la vacation précédente
        // (relecture adversariale du 2026-11-04 : un retard ne disparaît pas à la relève).
        self::assertSame(['A', 'B', 'C'], array_column($shift['items'], 'id'));
        self::assertSame(['late', 'late', 'planned'], array_column($shift['items'], 'status'));
        self::assertSame('SIMPSON Marge', $shift['items'][0]['patient']);
        self::assertSame(2, $shift['patients'][0]['late']);
        self::assertSame(['total' => 3, 'late' => 2, 'done' => 0, 'planned' => 1], $shift['counts']);
    }

    /** @return array<string, mixed> */
    private function appointment(string $id, string $plannedTime): array
    {
        return [
            'idAppointment' => $id, 'plannedTime' => new DateTimeImmutable($plannedTime), 'status' => 'PLANIFIE',
            'timeOfCompletion' => null, 'timeRecorded' => null, 'comment' => null,
        ];
    }
}
