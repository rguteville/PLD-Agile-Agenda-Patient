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

    public function testServiceWithoutPatientHasDayNavigationAndNoRow(): void
    {
        $service = AgendaService::presentService([], $this->day(), $this->now());

        self::assertSame(self::DAY, $service['date']);
        self::assertSame('mercredi 14 octobre 2026', $service['dateLabel']);
        self::assertTrue($service['isToday']);
        self::assertSame('2026-10-13', $service['previous']);
        self::assertSame('2026-10-15', $service['next']);
        self::assertSame([], $service['patients']);
    }

    public function testOtherDaysAndPatientsAreKeptApart(): void
    {
        $episodes = [
            $this->episode(1, 'DUPONT', [$this->appointment('2026-10-15 08:00:00', 'PLANIFIE')]),
            $this->episode(2, 'MARTIN', [$this->appointment('2026-10-14 23:30:00', 'PLANIFIE')]),
        ];

        $service = AgendaService::presentService($episodes, $this->day(), $this->now());

        self::assertSame([], $service['patients'][0]['items'], 'le soin du lendemain n\'apparaît pas');
        self::assertSame(['23:30'], array_column($service['patients'][1]['items'], 'time'));
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
