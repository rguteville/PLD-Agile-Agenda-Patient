<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Domain\Patient;
use Aura\Service\AdmissionService;
use Aura\Service\ModificationPatientService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** AURA-12.2 : modification d'un patient, sans base (DAO simulé). */
final class ModificationPatientServiceTest extends TestCase
{
    private const DONNEES = [
        'nom' => 'Durand',
        'prenom' => 'Alice',
        'ipp' => '123456',
        'dateNaissance' => '1990-06-15',
        'sexe' => 'F',
    ];

    private PatientDao&MockObject $patients;
    private ModificationPatientService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->service = new ModificationPatientService(
            $this->patients,
            new AdmissionService(new DateTimeImmutable('2026-10-19'))
        );
    }

    public function testModificationValideMetAJourLePatientSansEffacerLeTelephone(): void
    {
        $this->patients->method('findById')->with(7)->willReturn($this->patient(7, '100000007'));
        $this->patients->method('findByIpp')->with('123456')->willReturn(null);
        $this->patients->expects($this->once())->method('update')->with($this->callback(
            fn(Patient $p) => $p->getIdPatient() === 7
                && $p->getNom() === 'Durand'
                && $p->getIpp() === '123456'
                && $p->getDateNaissance()?->format('Y-m-d') === '1990-06-15'
                && $p->getTelephone() === '0600000007'
        ));

        self::assertSame([], $this->service->modifier(7, self::DONNEES));
    }

    public function testErreursDeValidationSansEcriture(): void
    {
        $this->patients->method('findById')->with(7)->willReturn($this->patient(7, '100000007'));
        $this->patients->expects($this->never())->method('update');

        $erreurs = $this->service->modifier(7, ['nom' => ''] + self::DONNEES);

        self::assertSame(['nom' => 'Le nom est obligatoire.'], $erreurs);
    }

    public function testIppPorteParUnAutrePatientEstRefuse(): void
    {
        $this->patients->method('findById')->with(7)->willReturn($this->patient(7, '100000007'));
        $this->patients->method('findByIpp')->with('123456')->willReturn($this->patient(99, '123456'));
        $this->patients->expects($this->never())->method('update');

        $erreurs = $this->service->modifier(7, self::DONNEES);

        self::assertSame(['ipp'], array_keys($erreurs));
        self::assertStringNotContainsString('123456', $erreurs['ipp'], 'le message ne doit pas reprendre la saisie');
    }

    public function testIppDejaPorteParLePatientLuiMemeEstAccepte(): void
    {
        $this->patients->method('findById')->with(7)->willReturn($this->patient(7, '123456'));
        $this->patients->method('findByIpp')->with('123456')->willReturn($this->patient(7, '123456'));
        $this->patients->expects($this->once())->method('update');

        self::assertSame([], $this->service->modifier(7, self::DONNEES));
    }

    public function testPatientIntrouvableLeveUneException(): void
    {
        $this->patients->method('findById')->with(42)->willReturn(null);
        $this->patients->expects($this->never())->method('update');

        $this->expectException(InvalidArgumentException::class);

        $this->service->modifier(42, self::DONNEES);
    }


    private function patient(int $id, ?string $ipp): Patient
    {
        return new Patient($id, $ipp, 'Ancien', 'Nom', new DateTimeImmutable('1970-01-01'), 'M', '0600000007', null);
    }
}
