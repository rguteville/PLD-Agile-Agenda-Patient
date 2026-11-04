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
 * Rotations (6 h, 14 h, 22 h) et équipes d'une rotation, règles pures sans base. La nuit porte la date de
 * son début. Soins de substitution évidents.
 */
final class CareTeamServiceTest extends TestCase
{
    private const PRACTITIONERS = ['PRA04' => 'Thomas Girard (IDE)', 'PRA06' => 'Julien Perrin (AS)'];
    private const EPISODES = [1 => 'Lit L01, SIMPSON Marge', 2 => 'Lit L02, SKYWALKER Luke'];

    public function testThreeRotationsStartAtSixFourteenAndTwentyTwo(): void
    {
        self::assertSame(
            ['MATIN' => [6, 14], 'APRES_MIDI' => [14, 22], 'NUIT' => [22, 6]],
            array_map(static fn (array $shift): array => [$shift['from'], $shift['to']], CareTeam::SHIFTS)
        );
    }

    /** Une seule référence : la fin de rotation de l'agenda (validation d'un soin) suit les mêmes rotations. */
    public function testAgendaShiftEndUsesTheSameRotations(): void
    {
        foreach (array_keys(CareTeam::SHIFTS) as $shift) {
            [$start, $end] = CareTeamService::window(new DateTimeImmutable('2026-10-14'), $shift);
            self::assertEquals($end, AgendaService::shiftEnd($start), $shift);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function moments(): iterable
    {
        yield 'début du matin' => ['2026-10-14 06:00:00', '2026-10-14', 'MATIN'];
        yield 'début d\'après-midi' => ['2026-10-14 14:00:00', '2026-10-14', 'APRES_MIDI'];
        yield 'début de nuit' => ['2026-10-14 22:00:00', '2026-10-14', 'NUIT'];
        yield 'nuit après minuit : datée de la veille' => ['2026-10-15 03:00:00', '2026-10-14', 'NUIT'];
    }

    #[DataProvider('moments')]
    public function testCurrentShiftCarriesTheDateOfItsStart(string $now, string $date, string $shift): void
    {
        $current = CareTeamService::currentShift(new DateTimeImmutable($now));

        self::assertSame($date, $current['date']->format('Y-m-d'));
        self::assertSame($shift, $current['shift']);
    }

    public function testCompleteTeamGivesNoError(): void
    {
        $input = ['name' => 'Équipe C', 'members' => ['PRA04', 'PRA06'], 'patients' => ['2']];

        self::assertSame([], CareTeamService::validate($input, self::PRACTITIONERS, self::EPISODES, []));
    }

    public function testTeamWithoutNameOrCaregiverIsRefused(): void
    {
        $errors = CareTeamService::validate(
            ['name' => '  ', 'members' => [], 'patients' => []],
            self::PRACTITIONERS,
            self::EPISODES,
            []
        );

        self::assertArrayHasKey('name', $errors);
        self::assertArrayHasKey('members', $errors);
    }

    public function testPatientAlreadyInATeamOfTheShiftIsRefused(): void
    {
        $existing = [new CareTeam(1, 'SRV01', new DateTimeImmutable('2026-10-14'), 'MATIN', 'Équipe A', [], [1])];

        $errors = CareTeamService::validate(
            ['name' => 'Équipe C', 'members' => ['PRA04'], 'patients' => ['1']],
            self::PRACTITIONERS,
            self::EPISODES,
            $existing
        );

        self::assertSame(['patients'], array_keys($errors));
    }

    public function testUnknownRotationInTheUrlIsIgnored(): void
    {
        self::assertSame('NUIT', CareTeamService::parseShift('nuit'));
        self::assertNull(CareTeamService::parseShift('jour'), 'l\'ancienne vacation de jour n\'existe plus');
        self::assertNull(CareTeamService::parseShift(['MATIN']));
    }

    /** La nuit va de 22 h à 6 h le lendemain ; un retard de la rotation précédente n'y disparaît pas. */
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

        self::assertSame(['A', 'B', 'C'], array_column($shift['items'], 'id'), '6 h exclu ; 19 h repris en retard');
        self::assertSame(['late', 'late', 'planned'], array_column($shift['items'], 'status'));
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
