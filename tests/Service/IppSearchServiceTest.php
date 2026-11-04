<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\PatientDao;
use Aura\Domain\AllergyIntolerance;
use Aura\Domain\Patient;
use Aura\Service\IppSearchService;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Recherche d'un patient déjà connu par son IPP, sans base (DAO simulés). */
final class IppSearchServiceTest extends TestCase
{
    private PatientDao&MockObject $patients;
    private AllergyIntoleranceDao&MockObject $allergies;
    private IppSearchService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->allergies = $this->createMock(AllergyIntoleranceDao::class);
        $this->service = new IppSearchService($this->patients, $this->allergies);
    }

    public function testKnownIppReturnsIdentityAndAllergies(): void
    {
        $this->patients->method('findByIpp')->with('100200300')->willReturn($this->patient('100200300'));
        $this->allergies->method('findByPatient')->with(9)->willReturn([
            new AllergyIntolerance(1, 9, 'PENI', ' Pénicilline ', 'Élevée'),
            new AllergyIntolerance(2, 9, 'LATEX', 'Latex', '  '),
        ]);

        self::assertSame([
            'idPatient' => 9,
            'ipp' => '100200300',
            'lastName' => 'Durand',
            'firstName' => 'Alice',
            'birthDate' => '1990-06-15',
            'sex' => 'F',
            'allergies' => [
                ['label' => 'Pénicilline', 'criticality' => 'Élevée'],
                ['label' => 'Latex', 'criticality' => null],
            ],
        ], $this->service->search('100200300'));
    }

    public function testUnknownIppReturnsNullWithoutReadingAllergies(): void
    {
        $this->patients->method('findByIpp')->with('999999999')->willReturn(null);
        $this->allergies->expects($this->never())->method('findByPatient');

        self::assertNull($this->service->search('999999999'));
    }

    private function patient(string $ipp): Patient
    {
        return new Patient(9, $ipp, ' Durand ', 'Alice', new DateTimeImmutable('1990-06-15'), 'F');
    }
}
