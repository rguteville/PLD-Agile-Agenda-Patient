<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Domain\Patient;
use Aura\Service\AdmissionService;
use Aura\Service\PatientUpdateService;
use DateTimeImmutable;
use InvalidArgumentException;
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

    public function testValidUpdateUpdatesPatientWithoutErasingPhone(): void
    {
        $this->patients->method('findById')->with(7)->willReturn($this->patient(7, '100000007'));
        $this->patients->method('ippOwnerId')->with('123456')->willReturn(null);
        $this->patients->expects($this->once())->method('update')->with($this->callback(
            fn(Patient $p) => $p->getIdPatient() === 7
                && $p->getLastName() === 'Durand'
                && $p->getIpp() === '123456'
                && $p->getBirthDate()?->format('Y-m-d') === '1990-06-15'
                && $p->getPhone() === '0600000007'
        ));

        self::assertSame([], $this->service->edit(7, self::DATA));
    }

    public function testValidationErrorsWithoutWrite(): void
    {
        $this->patients->method('findById')->with(7)->willReturn($this->patient(7, '100000007'));
        $this->patients->expects($this->never())->method('update');

        $errors = $this->service->edit(7, ['lastName' => ''] + self::DATA);

        self::assertSame(['lastName' => 'Le nom est obligatoire.'], $errors);
    }

    public function testIppHeldByAnotherPatientIsRejected(): void
    {
        $this->patients->method('findById')->with(7)->willReturn($this->patient(7, '100000007'));
        $this->patients->method('ippOwnerId')->with('123456')->willReturn(99);
        $this->patients->expects($this->never())->method('update');

        $errors = $this->service->edit(7, self::DATA);

        self::assertSame(['ipp'], array_keys($errors));
        self::assertStringNotContainsString('123456', $errors['ipp'], 'le message ne doit pas reprendre la saisie');
    }

    public function testIppAlreadyHeldByPatientItselfIsAccepted(): void
    {
        $this->patients->method('findById')->with(7)->willReturn($this->patient(7, '123456'));
        $this->patients->method('ippOwnerId')->with('123456')->willReturn(7);
        $this->patients->expects($this->once())->method('update');

        self::assertSame([], $this->service->edit(7, self::DATA));
    }

    public function testPatientNotFoundThrows(): void
    {
        $this->patients->method('findById')->with(42)->willReturn(null);
        $this->patients->expects($this->never())->method('update');

        $this->expectException(InvalidArgumentException::class);

        $this->service->edit(42, self::DATA);
    }


    private function patient(int $id, ?string $ipp): Patient
    {
        return new Patient($id, $ipp, 'Ancien', 'Nom', new DateTimeImmutable('1970-01-01'), 'M', '0600000007', null);
    }
}
