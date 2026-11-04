<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\AgendaService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * AURA-25 : vue Semaine de l'agenda du patient (présentateur pur, sans base) et données transmises
 * à l'agenda du service (référent, décès). Soins de substitution évidents.
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

    public function testStoppedLineAndUnvalidatedPrescriptionAreFlagged(): void
    {
        $plans = [[
            'idCarePlan' => '1',
            'validatedAt' => null,
            'serviceRequests' => [
                ['idActivityDefinition' => 'Arrêtée', 'timeConstraint' => null, 'stoppedAt' => '2026-10-14 09:30',
                    'appointments' => [$this->appointment('S', '2026-10-14 09:00:00', 'PLANIFIE')]],
                ['idActivityDefinition' => 'Active', 'timeConstraint' => null, 'stoppedAt' => null,
                    'appointments' => [
                        $this->appointment('SOON', '2026-10-14 11:15:00', 'PLANIFIE'),
                        $this->appointment('LATER', '2026-10-14 20:00:00', 'PLANIFIE'),
                    ]],
            ],
            'medicationRequests' => [],
        ]];

        $agenda = AgendaService::presentDay($plans, new DateTimeImmutable('2026-10-14'), $this->now());
        $items = [];
        foreach ([$agenda['late'], ...array_column($agenda['shifts'], 'items')] as $list) {
            foreach ($list as $item) {
                $items[$item['id']] = $item;
            }
        }

        self::assertSame('stopped', $items['S']['status'], 'ligne arrêtée : ni à faire ni en retard');
        self::assertSame(0, $agenda['counts']['late']);
        self::assertTrue($items['S']['canValidate'], 'une dose faite avant l\'arrêt reste traçable');
        self::assertTrue($items['SOON']['canValidate'], 'soin de la rotation en cours (matin) fait en avance');
        self::assertFalse($items['LATER']['canValidate'], 'soin de la rotation à venir (20 h) : pas encore');
        self::assertTrue($items['LATER']['notValidated'], 'RG5 : ordonnance non validée signalée');
        self::assertSame('1', $items['LATER']['idCarePlan'], 'le badge mène à la validation de cette ordonnance');
    }

    public function testEachCareCarriesItsPrescriptionCard(): void
    {
        $plans = [[
            'idCarePlan' => '1', 'mode' => 'MEDICAL', 'idPrescriber' => 'PRA03',
            'prescriberName' => 'Léa Fontaine (Interne)',
            'recordedAt' => new DateTimeImmutable('2026-10-10 09:00'), 'validatedAt' => null, 'idValidator' => null,
            'note' => 'Prévenir si fièvre > 38,5 °C',
            'serviceRequests' => [],
            'medicationRequests' => [[
                'idActivityDefinition' => 'Paracétamol', 'dose' => '1 g', 'route' => 'IV', 'frequencyHours' => 6,
                'startDate' => new DateTimeImmutable('2026-10-10 12:00'), 'endDate' => null,
                'timeConstraint' => null, 'stoppedAt' => null,
                'appointments' => [$this->appointment('M', '2026-10-14 12:00:00', 'PLANIFIE')],
            ]],
        ]];

        $agenda = AgendaService::presentDay($plans, new DateTimeImmutable('2026-10-14'), $this->now());
        $card = array_column($agenda['shifts'][1]['items'][0]['prescription'], 'value', 'label');

        self::assertSame('1 g', $card['Dose']);
        self::assertSame('IV', $card['Voie']);
        self::assertSame('Toutes les 6 h', $card['Fréquence']);
        self::assertSame('du 10/10/2026 12:00, sans date de fin', $card['Période']);
        self::assertSame('Léa Fontaine (Interne)', $card['Prescrit par']);
        self::assertSame('en attente de validation par un médecin', $card['Validation']);
        self::assertSame('Prévenir si fièvre > 38,5 °C', $card['Note'], 'la note du prescripteur parvient à l\'IDE');
        self::assertArrayNotHasKey('Arrêtée le', $card);
    }

    public function testDeathIsRelayedToServiceAndShiftViews(): void
    {
        $episode = $this->episode(1, 'Fletcher', []) + ['deceased' => 'Décédée'];
        $day = new DateTimeImmutable('2026-10-14');

        $service = AgendaService::presentService([$episode], $day, $this->now());
        $shift = AgendaService::presentShift([$episode], $day->setTime(8, 0), $day->setTime(20, 0), $this->now());

        self::assertSame('Décédée', $service['patients'][0]['deceased']);
        self::assertSame('Décédée', $shift['patients'][0]['deceased']);
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
