<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\CarePlanService;
use PHPUnit\Framework\TestCase;

/** Retours du 2026-11-02 : période d'une prescription « du … au … » et catégorie « Protocoles ». */
final class CarePlanServicePeriodTest extends TestCase
{
    public function testPeriodeAvecDateDeFin(): void
    {
        self::assertSame(
            'du 20/10/2026 08:00 au 27/10/2026 08:00',
            CarePlanService::period('20/10/2026 08:00', '27/10/2026 08:00')
        );
    }

    public function testPeriodeSansDateDeFinLeDitExplicitement(): void
    {
        self::assertSame('du 20/10/2026 08:00, sans date de fin', CarePlanService::period('20/10/2026 08:00', null));
    }

    public function testLaCategorieDesGroupesSAppelleProtocoles(): void
    {
        self::assertSame('Protocoles', CarePlanService::CATEGORIES['GROUPS']['label']);
    }

    public function testDateDeFinAvantLeDebutRefuseeAvecLeVocabulaireDuAu(): void
    {
        $service = (new \ReflectionClass(CarePlanService::class))->newInstanceWithoutConstructor();

        $erreurs = $service->checkInput([
            'mode' => 'MEDICAL',
            'idPrescriber' => 'PRA01',
            'lines' => [[
                'kind' => 'CARE', 'idActivityDefinition' => 'TYP02', 'frequencyType' => 'SCHEDULED',
                'frequencyHours' => '4', 'startDate' => '2026-10-20T08:00', 'endDate' => '2026-10-19T08:00',
            ]],
        ]);

        self::assertStringContainsString('« au »', implode(' ', $erreurs));
    }
}
