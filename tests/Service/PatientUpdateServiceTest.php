<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Domain\Patient;
use Aura\Service\AdmissionService;
use Aura\Service\PatientUpdateService;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** AURA-12.2 : modification d'un patient, sans base (DAO simulé). */
final class PatientUpdateServiceTest extends TestCase
{
    private const DATA = [
        'lastName' => 'Durand',
        'firstName' => 'Alice',
        'ipp' => '123456',
        'birthDate' => '1990-06-15',
        'sex' => 'F',
    ];

    private PatientDao&MockObject $patients;
    private PatientUpdateService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->service = new PatientUpdateService(
            $this->patients,
            new AdmissionService(new DateTimeImmutable('2026-10-19'))
        );
    }

    public function testInvalidIdentityWritesNothing(): void
    {
        $this->patients->method('findById')->with(7)->willReturn($this->patient(7, '100000007'));
        $this->patients->expects($this->never())->method('update');

        $errors = $this->service->edit(7, ['lastName' => ''] + self::DATA);

        self::assertSame(['lastName' => 'Le nom est obligatoire.'], $errors);
    }

    public function testIppOfAnotherPatientIsRefused(): void
    {
        $this->patients->method('findById')->with(7)->willReturn($this->patient(7, '100000007'));
        $this->patients->method('ippOwnerId')->with('123456')->willReturn(99);
        $this->patients->expects($this->never())->method('update');

        $errors = $this->service->edit(7, self::DATA);

        self::assertSame(['ipp'], array_keys($errors));
        self::assertStringNotContainsString('123456', $errors['ipp'], 'le message ne doit pas reprendre la saisie');
    }


    private function patient(int $id, ?string $ipp): Patient
    {
        return new Patient($id, $ipp, 'Ancien', 'Nom', new DateTimeImmutable('1970-01-01'), 'M', '0600000007', null);
    }
}
