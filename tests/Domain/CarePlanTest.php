<?php

declare(strict_types=1);

namespace Aura\Tests\Domain;

use Aura\Domain\CarePlan;
use Aura\Domain\MedicationRequest;
use Aura\Domain\ServiceRequest;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Epic 4 : règles portées par les objets du domaine (aucun accès à la base).
 */
final class CarePlanTest extends TestCase
{
    public function testIsRecurring(): void
    {
        $single = new ServiceRequest(null, 'TYP03', new DateTimeImmutable('2026-10-12 15:00'));
        $recurring = new ServiceRequest(null, 'TYP02', new DateTimeImmutable('2026-10-12 16:00'), frequencyHours: 4);

        self::assertFalse($single->isRecurring(), 'RG3 : sans fréquence = soin unique');
        self::assertTrue($recurring->isRecurring(), 'RG3 : avec fréquence = récurrente');
    }

    public function testNotValidated(): void
    {
        $line = new ServiceRequest(null, 'TYP02', new DateTimeImmutable('2026-10-12 16:00'), frequencyHours: 4);
        $carePlan = new CarePlan(null, 3, 'PRA04', 'PARAMEDICAL', [$line]);

        self::assertFalse($carePlan->isValidated());
        self::assertSame(CarePlan::STATUS_NOT_VALIDATED, $carePlan->statusOf($line), 'RG5');
    }

    public function testStoppedLine(): void
    {
        $paracetamol = new MedicationRequest(
            null,
            'TYP01',
            'MED01',
            '1 g',
            'IV',
            new DateTimeImmutable('2026-10-12 18:00'),
            frequencyHours: 6
        );
        $midazolam = new MedicationRequest(
            null,
            'TYP01',
            'MED05',
            '2 mg/h',
            'IVSE',
            new DateTimeImmutable('2026-10-12 15:00'),
            frequencyHours: 24,
            idStoppedBy: 'PRA02',
            stoppedAt: new DateTimeImmutable('2026-10-13 08:00')
        );
        $carePlan = new CarePlan(
            1,
            3,
            'PRA02',
            'MEDICAL',
            [$paracetamol, $midazolam],
            idValidator: 'PRA02',
            validatedAt: new DateTimeImmutable('2026-10-12 14:30')
        );

        self::assertSame(CarePlan::STATUS_VALIDATED, $carePlan->statusOf($paracetamol));
        self::assertSame(CarePlan::STATUS_STOPPED, $carePlan->statusOf($midazolam), 'AURA-22');
        self::assertCount(2, $carePlan->getLines());
    }
}
