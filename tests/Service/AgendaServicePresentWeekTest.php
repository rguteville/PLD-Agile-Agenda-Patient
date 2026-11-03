<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\AgendaService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * AURA-25 : vue Semaine de l'agenda du patient (présentateur pur, sans base) et lecture verticale
 * de l'agenda du service (créneaux × patients, retards du service). Soins de substitution évidents.
 */
final class AgendaServicePresentWeekTest extends TestCase
{
    private const NOW = '2026-10-14 10:30:00';

    public function testWeekRunsFromMondayToSundayAroundTheRequestedDay(): void
    {
        $week = AgendaService::presentWeek([], new DateTimeImmutable('2026-10-14'), $this->now());

        self::assertSame('2026-10-12', $week['start'], 'mercredi 14 → lundi 12');
        self::assertCount(7, $week['days']);
        self::assertSame('lun 12', $week['days'][0]['label']);
        self::assertSame('dim 18', $week['days'][6]['label']);
        self::assertTrue($week['days'][2]['isToday']);
        self::assertSame('du lundi 12 au dimanche 18 octobre 2026', $week['label']);
        self::assertSame('2026-10-05', $week['previous']);
        self::assertSame('2026-10-19', $week['next']);
        self::assertSame([], $week['rows']);
    }

    public function testSundayBelongsToTheWeekThatEndsWithIt(): void
    {
        $week = AgendaService::presentWeek([], new DateTimeImmutable('2026-10-18'), $this->now());

        self::assertSame('2026-10-12', $week['start']);
    }

    public function testOneRowPerPrescribedCareWithEachDayTimesAndStatuses(): void
    {
        $plans = [$this->plan([
            $this->appointment('A', '2026-10-13 08:00:00', 'REALISE'),
            $this->appointment('B', '2026-10-14 08:00:00', 'PLANIFIE'),
            $this->appointment('C', '2026-10-14 20:00:00', 'PLANIFIE'),
            $this->appointment('D', '2026-10-20 08:00:00', 'PLANIFIE'),
        ])];

        $week = AgendaService::presentWeek($plans, new DateTimeImmutable('2026-10-14'), $this->now());

        self::assertCount(1, $week['rows']);
        $cells = $week['rows'][0]['cells'];
        self::assertSame('done', $cells['2026-10-13'][0]['status']);
        self::assertSame(['late', 'planned'], array_column($cells['2026-10-14'], 'status'));
        self::assertSame(['08:00', '20:00'], array_column($cells['2026-10-14'], 'time'), 'ordre chronologique');
        self::assertArrayNotHasKey('2026-10-20', $cells, 'la semaine suivante est exclue');
        self::assertSame(['total' => 3, 'late' => 1, 'done' => 1, 'planned' => 1], $week['counts']);
    }

    public function testServiceListsCareBySlotAndGathersLateCareOfAllPatients(): void
    {
        $episodes = [
            $this->episode(1, 'Dupont', [$this->appointment('A', '2026-10-14 08:15:00', 'PLANIFIE')]),
            $this->episode(2, 'Martin', [
                $this->appointment('B', '2026-10-14 06:00:00', 'PLANIFIE'),
                $this->appointment('C', '2026-10-14 16:00:00', 'PLANIFIE'),
            ]),
        ];

        $service = AgendaService::presentService($episodes, new DateTimeImmutable('2026-10-14'), $this->now());

        self::assertSame('10:00', $service['currentSlot'], '10 h 30 tombe dans le créneau 10 h – 12 h');
        self::assertSame(2, $service['late']);
        self::assertSame(
            ['06:00', '08:15'],
            array_column($service['lateItems'], 'time'),
            'retards du service, par heure'
        );
        self::assertSame('MARTIN Test', $service['lateItems'][0]['patient']);
        self::assertSame(2, $service['lateItems'][0]['idEpisodeOfCare']);
        self::assertSame(['B'], array_column($service['patients'][1]['items']['06:00'], 'id'));
        self::assertSame(['C'], array_column($service['patients'][1]['items']['16:00'], 'id'));
        self::assertSame(2, $service['patients'][1]['total']);
    }

    public function testAnotherDayHasNoCurrentSlot(): void
    {
        $service = AgendaService::presentService([], new DateTimeImmutable('2026-10-15'), $this->now());

        self::assertNull($service['currentSlot']);
        self::assertSame([], $service['lateItems']);
    }

    public function testMissingReferentIsShownOnlyWhenTheSourceKnowsIt(): void
    {
        $day = new DateTimeImmutable('2026-10-14');
        $toAssign = $this->episode(1, 'Ripley', []) + ['referent' => null];
        $named = $this->episode(2, 'Simpson', []) + ['referent' => 'Dr Claire Morel'];

        $service = AgendaService::presentService([$toAssign, $named, $this->episode(3, 'X', [])], $day, $this->now());

        self::assertNull($service['patients'][0]['referent']);
        self::assertTrue($service['patients'][0]['referentKnown'], 'garde : « à désigner »');
        self::assertSame('Dr Claire Morel', $service['patients'][1]['referent']);
        self::assertFalse($service['patients'][2]['referentKnown'], 'source sans référent : rien n\'est affiché');
    }

    /** @return array<string, mixed> */
    private function episode(int $id, string $lastName, array $appointments): array
    {
        return [
            'idEpisodeOfCare' => $id, 'lastName' => $lastName, 'firstName' => 'Test', 'bed' => 'L0' . $id,
            'episodeNumber' => sprintf('2026-%06d', $id), 'allergies' => [],
            'carePlans' => [$this->plan($appointments)],
        ];
    }

    /** @return array<string, mixed> */
    private function plan(array $appointments): array
    {
        return [
            'idCarePlan' => '1',
            'serviceRequests' => [['idActivityDefinition' => 'Soin A', 'appointments' => $appointments,
                'timeConstraint' => null]],
            'medicationRequests' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function appointment(string $id, string $plannedTime, string $status): array
    {
        return [
            'idAppointment' => $id,
            'plannedTime' => new DateTimeImmutable($plannedTime),
            'status' => $status,
            'timeOfCompletion' => null,
            'timeRecorded' => null,
            'comment' => null,
        ];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
