<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\CarePlanService;
use PHPUnit\Framework\TestCase;

/** Retours du 2026-11-02 : période d'une prescription « du … au … » et catégorie « Protocoles ». */
final class CarePlanServicePeriodTest extends TestCase
{
    public function testPeriodWithEndDate(): void
    {
        self::assertSame(
            'du 20/10/2026 08:00 au 27/10/2026 08:00',
            CarePlanService::period('20/10/2026 08:00', '27/10/2026 08:00')
        );
    }

    public function testPeriodWithoutEndDateSaysSoExplicitly(): void
    {
        self::assertSame('du 20/10/2026 08:00, sans date de fin', CarePlanService::period('20/10/2026 08:00', null));
    }

    /** Retours du 2026-11-04 : une prescription « Une fois » a une date, « le … », pas de « du » sans « au ». */
    public function testOneOffPrescriptionReadsLe(): void
    {
        self::assertSame('le 20/10/2026 08:00', CarePlanService::period('20/10/2026 08:00', null, true));
    }

    public function testGroupCategoryIsCalledProtocols(): void
    {
        self::assertSame('Protocoles', CarePlanService::CATEGORIES['GROUPS']['label']);
    }

    public function testEndDateBeforeStartRejectedWithFromToWording(): void
    {
        $service = (new \ReflectionClass(CarePlanService::class))->newInstanceWithoutConstructor();

        $errors = $service->checkInput([
            'mode' => 'MEDICAL',
            'idPrescriber' => 'PRA01',
            'lines' => [[
                'kind' => 'CARE', 'idActivityDefinition' => 'TYP02', 'frequencyType' => 'SCHEDULED',
                'frequencyHours' => '4', 'startDate' => '2026-10-20T08:00', 'endDate' => '2026-10-19T08:00',
            ]],
        ]);

        self::assertStringContainsString('« au »', implode(' ', $errors));
    }
}
