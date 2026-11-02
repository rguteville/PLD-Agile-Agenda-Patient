<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Domain\Allergie;
use Aura\Domain\Patient;
use Aura\Service\RechercheIppService;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** AURA-15 : recherche d'un patient déjà connu par son IPP, sans base (DAO simulés). */
final class RechercheIppServiceTest extends TestCase
{
    private PatientDao&MockObject $patients;
    private AllergieDao&MockObject $allergies;
    private RechercheIppService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->allergies = $this->createMock(AllergieDao::class);
        $this->service = new RechercheIppService($this->patients, $this->allergies);
    }

    public function testIppConnuRenvoieIdentiteEtAllergies(): void
    {
        $this->patients->method('findByIpp')->with('100200300')->willReturn($this->patient('100200300'));
        $this->allergies->method('findByPatient')->with(9)->willReturn([
            new Allergie(1, 9, 'PENI', ' Pénicilline ', 'Élevée'),
            new Allergie(2, 9, 'LATEX', 'Latex', '  '),
        ]);

        self::assertSame([
            'idPatient' => 9,
            'ipp' => '100200300',
            'nom' => 'Durand',
            'prenom' => 'Alice',
            'dateNaissance' => '1990-06-15',
            'sexe' => 'F',
            'allergies' => [
                ['libelle' => 'Pénicilline', 'criticite' => 'Élevée'],
                ['libelle' => 'Latex', 'criticite' => null],
            ],
        ], $this->service->rechercher('100200300'));
    }

    public function testIppInconnuRenvoieNullSansLireLesAllergies(): void
    {
        $this->patients->method('findByIpp')->with('999999999')->willReturn(null);
        $this->allergies->expects($this->never())->method('findByPatient');

        self::assertNull($this->service->rechercher('999999999'));
    }

    public function testPatientSansAllergieNiDateNiSexe(): void
    {
        $sousX = new Patient(7, '123456', 'X', 'X', null, null);

        $vue = RechercheIppService::presenter($sousX, []);

        self::assertNull($vue['dateNaissance'], 'date inconnue : champ laissé vide');
        self::assertNull($vue['sexe']);
        self::assertSame([], $vue['allergies'], 'liste vide = « Aucune allergie connue » côté formulaire');
    }

    public function testLeSexeVideDevientNull(): void
    {
        $vue = RechercheIppService::presenter(new Patient(7, '123456', 'Martin', 'Paul', null, ' '), []);

        self::assertNull($vue['sexe']);
    }

    private function patient(string $ipp): Patient
    {
        return new Patient(9, $ipp, ' Durand ', 'Alice', new DateTimeImmutable('1990-06-15'), 'F');
    }
}
