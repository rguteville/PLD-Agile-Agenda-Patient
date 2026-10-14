<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\SejourDao;
use Aura\Domain\Patient;
use Aura\Domain\Sejour;
use Aura\Service\ListePatientsService;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** AURA-12.1 : liste des patients avec leur séjour en cours, sans base (DAO simulés). */
final class ListePatientsServiceTest extends TestCase
{
    private PatientDao&MockObject $patients;
    private SejourDao&MockObject $sejours;
    private ListePatientsService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->sejours = $this->createMock(SejourDao::class);
        $this->service = new ListePatientsService($this->patients, $this->sejours);
    }

    public function testTousLesPatientsApparaissentAvecOuSansSejourEnCours(): void
    {
        $this->patients->method('findAll')->willReturn([
            $this->patient(1, 'Dupont', 'Marie', '100000001', '1958-03-21'),
            $this->patient(2, 'Martin', 'Jean', null, null),
        ]);
        $this->patients->expects($this->never())->method('search');
        $this->sejours->method('findEnCours')->willReturn([
            $this->sejour(idSejour: 10, idPatient: 1, numero: '2026-000101'),
            $this->sejour(idSejour: 11, idPatient: 99, numero: '2026-000199'),
        ]);

        $lignes = $this->service->lister(null);

        self::assertSame([
            [
                'idPatient' => 1,
                'nom' => 'Dupont',
                'prenom' => 'Marie',
                'ipp' => '100000001',
                'dateNaissance' => '21/03/1958',
                'idSejour' => 10,
                'numeroSejour' => '2026-000101',
            ],
            [
                'idPatient' => 2,
                'nom' => 'Martin',
                'prenom' => 'Jean',
                'ipp' => null,
                'dateNaissance' => null,
                'idSejour' => null,
                'numeroSejour' => null,
            ],
        ], $lignes);
    }

    public function testLaRechercheEstDelegueeAuDaoApresNettoyage(): void
    {
        $this->patients->expects($this->never())->method('findAll');
        $this->patients->expects($this->once())
            ->method('search')
            ->with('dup')
            ->willReturn([$this->patient(1, 'Dupont', 'Marie', null, null)]);
        $this->sejours->method('findEnCours')->willReturn([]);

        $lignes = $this->service->lister('  dup ');

        self::assertCount(1, $lignes);
        self::assertSame('Dupont', $lignes[0]['nom']);
        self::assertNull($lignes[0]['idSejour']);
    }

    public function testUneRechercheVideListeToutLeMonde(): void
    {
        $this->patients->expects($this->once())->method('findAll')->willReturn([]);
        $this->patients->expects($this->never())->method('search');
        $this->sejours->method('findEnCours')->willReturn([]);

        self::assertSame([], $this->service->lister('   '));
    }

    public function testLeTermeDeRechercheEstTronque(): void
    {
        $long = str_repeat('a', ListePatientsService::LONGUEUR_MAX_RECHERCHE + 10);

        self::assertSame(
            str_repeat('a', ListePatientsService::LONGUEUR_MAX_RECHERCHE),
            ListePatientsService::nettoyerRecherche($long)
        );
        self::assertNull(ListePatientsService::nettoyerRecherche(null));
        self::assertNull(ListePatientsService::nettoyerRecherche(''));
    }

    private function patient(int $id, string $nom, string $prenom, ?string $ipp, ?string $naissance): Patient
    {
        return new Patient(
            $id,
            $ipp,
            $nom,
            $prenom,
            $naissance !== null ? new DateTimeImmutable($naissance) : null,
            'F'
        );
    }

    private function sejour(int $idSejour, int $idPatient, string $numero): Sejour
    {
        return new Sejour($idSejour, $idPatient, $numero, new DateTimeImmutable('2026-10-12 08:15:00'));
    }
}
