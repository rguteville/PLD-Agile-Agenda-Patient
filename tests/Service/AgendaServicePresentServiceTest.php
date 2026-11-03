<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\AgendaService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * AURA-29 : vue-modèle de l'agenda du service (présentateur pur, sans base). Mêmes règles que
 * l'agenda d'un patient ; les soins sont des substituts évidents (« Soin A »).
 */
final class AgendaServicePresentServiceTest extends TestCase
{
    private const DAY = '2026-10-14';
    private const NOW = '2026-10-14 10:30:00';

    public function testServiceWithoutPatientHasTwelveSlotsAndNoRow(): void
    {
        $service = AgendaService::presentService([], $this->day(), $this->now());

        self::assertSame(self::DAY, $service['date']);
        self::assertSame('mercredi 14 octobre 2026', $service['dateLabel']);
        self::assertTrue($service['isToday']);
        self::assertSame('2026-10-13', $service['previous']);
        self::assertSame('2026-10-15', $service['next']);
        self::assertCount(12, $service['slots'], 'un créneau de 2 h de 0 h à 22 h');
        self::assertSame('00:00', $service['slots'][0]);
        self::assertSame('22:00', $service['slots'][11]);
        self::assertSame([], $service['patients']);
        self::assertSame(0, $service['late']);
    }

    public function testCareIsCountedByStatusInItsSlot(): void
    {
        $episodes = [$this->episode(1, 'DUPONT', [
            $this->appointment('2026-10-14 08:00:00', 'REALISE'),
            $this->appointment('2026-10-14 09:15:00', 'PLANIFIE'),
            $this->appointment('2026-10-14 09:45:00', 'PLANIFIE'),
            $this->appointment('2026-10-14 14:00:00', 'PLANIFIE'),
        ])];

        $service = AgendaService::presentService($episodes, $this->day(), $this->now());
        $patient = $service['patients'][0];

        self::assertSame(['late' => 2, 'done' => 1], $patient['cells']['08:00'], '8 h – 10 h : 1 fait, 2 en retard');
        self::assertSame(['planned' => 1], $patient['cells']['14:00']);
        self::assertArrayNotHasKey('10:00', $patient['cells'], 'créneau vide absent');
        self::assertSame(2, $patient['late']);
        self::assertSame(2, $service['late'], 'total des retards du service');
        self::assertSame('L01', $patient['bed']);
        self::assertSame('2026-000001', $patient['episodeNumber']);
    }

    public function testOtherDaysAndPatientsAreKeptApart(): void
    {
        $episodes = [
            $this->episode(1, 'DUPONT', [$this->appointment('2026-10-15 08:00:00', 'PLANIFIE')]),
            $this->episode(2, 'MARTIN', [$this->appointment('2026-10-14 23:30:00', 'PLANIFIE')]),
        ];

        $service = AgendaService::presentService($episodes, $this->day(), $this->now());

        self::assertSame([], $service['patients'][0]['cells'], 'le soin du lendemain n\'apparaît pas');
        self::assertSame(['planned' => 1], $service['patients'][1]['cells']['22:00']);
        self::assertSame(0, $service['late']);
    }

    /** @return array<string, mixed> */
    private function episode(int $id, string $lastName, array $appointments): array
    {
        return [
            'idEpisodeOfCare' => $id,
            'lastName' => $lastName,
            'firstName' => 'Test',
            'bed' => 'L0' . $id,
            'episodeNumber' => sprintf('2026-%06d', $id),
            'carePlans' => [[
                'idCarePlan' => '1',
                'serviceRequests' => [[
                    'idActivityDefinition' => 'Soin A',
                    'appointments' => $appointments,
                    'timeConstraint' => null,
                ]],
                'medicationRequests' => [],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function appointment(string $plannedTime, string $status): array
    {
        return [
            'idAppointment' => $plannedTime,
            'plannedTime' => new DateTimeImmutable($plannedTime),
            'status' => $status,
            'timeOfCompletion' => null,
            'timeRecorded' => null,
            'comment' => null,
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
