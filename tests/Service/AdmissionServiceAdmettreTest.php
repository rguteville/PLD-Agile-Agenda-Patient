<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\Patient;
use Aura\Domain\Sejour;
use Aura\Service\AdmissionService;
use Aura\Service\SejourService;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** AURA-10.4 : admettre() enregistre le patient et ouvre son séjour, sans base (DAO simulés). */
final class AdmissionServiceAdmettreTest extends TestCase
{
    private const DONNEES = [
        'nom' => ' Durand ',
        'prenom' => 'Alice',
        'ipp' => '123456',
        'dateNaissance' => '1990-06-15',
        'sexe' => 'F',
    ];

    private PatientDao&MockObject $patients;
    private SejourService&MockObject $sejours;
    private AdmissionService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->sejours = $this->createMock(SejourService::class);

        // La transaction exécute simplement le traitement : pas de base ici.
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('run')->willReturnCallback(fn(callable $traitement) => $traitement());

        $this->service = new AdmissionService(
            new DateTimeImmutable('2026-10-14'),
            $this->patients,
            $this->sejours,
            $transaction
        );
    }

    public function testNouveauPatientInsereEtSejourOuvert(): void
    {
        $this->patients->method('findByIpp')->with('123456')->willReturn(null);
        $this->patients->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Patient $p): bool {
                $this->assertNull($p->getIdPatient());
                $this->assertSame('Durand', $p->getNom(), 'le nom est nettoyé (trim)');
                $this->assertSame('Alice', $p->getPrenom());
                $this->assertSame('123456', $p->getIpp());
                $this->assertSame('1990-06-15', $p->getDateNaissance()?->format('Y-m-d'));
                $this->assertSame('F', $p->getSexe());

                return true;
            }))
            ->willReturn(42);
        $sejour = $this->sejour(idPatient: 42);
        $this->sejours->expects($this->once())->method('ouvrirSejour')->with(42)->willReturn($sejour);

        $this->assertSame($sejour, $this->service->admettre(self::DONNEES));
    }

    public function testNomEtPrenomSeulsSuffisent(): void
    {
        $this->patients->expects($this->never())->method('findByIpp');
        $this->patients->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Patient $p): bool {
                $this->assertNull($p->getIpp());
                $this->assertNull($p->getDateNaissance());
                $this->assertNull($p->getSexe(), 'RG1 : le sexe n\'est pas obligatoire');

                return true;
            }))
            ->willReturn(7);
        $this->sejours->method('ouvrirSejour')->with(7)->willReturn($this->sejour(idPatient: 7));

        $sejour = $this->service->admettre(['nom' => 'Martin', 'prenom' => 'Paul']);

        $this->assertSame(7, $sejour->getIdPatient());
    }

    public function testPatientDejaConnuParSonIppEstReutilise(): void
    {
        $existant = new Patient(3, '123456', 'Durand', 'Alice', new DateTimeImmutable('1990-06-15'), 'F');
        $this->patients->method('findByIpp')->with('123456')->willReturn($existant);
        $this->patients->expects($this->never())->method('insert');
        $sejour = $this->sejour(idPatient: 3);
        $this->sejours->expects($this->once())->method('ouvrirSejour')->with(3)->willReturn($sejour);

        $this->assertSame($sejour, $this->service->admettre(self::DONNEES));
    }

    public function testDonneesInvalidesNeTouchentPasLaBase(): void
    {
        $this->patients->expects($this->never())->method('insert');
        $this->sejours->expects($this->never())->method('ouvrirSejour');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('obligatoire');

        $this->service->admettre(['nom' => '', 'prenom' => 'Paul']);
    }

    public function testSejourRefuseFaitEchouerLAdmission(): void
    {
        $this->patients->method('findByIpp')->willReturn(null);
        $this->patients->method('insert')->willReturn(42);
        $this->sejours->method('ouvrirSejour')
            ->willThrowException(new InvalidArgumentException('Le patient a déjà un séjour en cours (n° 2026-000001)'));

        // L'exception traverse la transaction : c'est elle qui annule l'insertion du patient.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('déjà un séjour en cours');

        $this->service->admettre(self::DONNEES);
    }

    public function testSansAccesALaBaseAdmettreEstImpossible(): void
    {
        $this->expectException(LogicException::class);

        (new AdmissionService())->admettre(self::DONNEES);
    }

    private function sejour(int $idPatient): Sejour
    {
        return new Sejour(11, $idPatient, '2026-000011', new DateTimeImmutable('2026-10-14 10:00:00'));
    }
}
