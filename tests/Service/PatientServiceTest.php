<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\Patient;
use Aura\Domain\Sejour;
use Aura\Service\PatientService;
use Aura\Service\SejourService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AURA-41 / AURA-10.2 / AURA-10.3 : inscription d'un patient, validation côté
 * serveur et ouverture de son séjour.
 */
final class PatientServiceTest extends TestCase
{
    private const DONNEES_VALIDES = [
        'nom' => '  Durand ',
        'prenom' => 'Alice',
        'date_naissance' => '1990-06-15',
        'sexe' => 'f',
        'ipp' => '123456',
        'telephone' => '0601020304',
        'email' => 'alice.durand@example.org',
    ];

    private PatientDao&MockObject $dao;
    private SejourService&MockObject $sejours;
    private PatientService $service;

    protected function setUp(): void
    {
        $this->dao = $this->createMock(PatientDao::class);
        $this->sejours = $this->createMock(SejourService::class);

        // La transaction exécute simplement le traitement : pas de base ici.
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('run')->willReturnCallback(fn(callable $traitement) => $traitement());

        $this->service = new PatientService($this->dao, $this->sejours, $transaction);
    }

    public function testDonneesValidesInserentLePatientEtOuvrentSonSejour(): void
    {
        $this->dao->method('existsByIpp')->with('123456')->willReturn(false);
        $this->dao->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Patient $p): bool {
                $this->assertNull($p->getIdPatient());
                $this->assertSame('Durand', $p->getNom(), 'le nom est nettoyé (trim)');
                $this->assertSame('Alice', $p->getPrenom());
                $this->assertSame('F', $p->getSexe(), 'le sexe est normalisé en majuscule');
                $this->assertSame('1990-06-15', $p->getDateNaissance()->format('Y-m-d'));
                $this->assertSame('123456', $p->getIpp());
                $this->assertSame('0601020304', $p->getTelephone());
                $this->assertSame('alice.durand@example.org', $p->getEmail());
                $this->assertFalse($p->estAnonyme());

                return true;
            }))
            ->willReturn(42);

        $sejour = $this->sejour(idPatient: 42);
        $this->sejours->expects($this->once())
            ->method('ouvrirSejour')
            ->with(42, null, null)
            ->willReturn($sejour);

        $this->assertSame($sejour, $this->service->inscrirePatient(self::DONNEES_VALIDES));
    }

    public function testLitEtBoxDuFormulaireSontTransmisAuSejour(): void
    {
        $this->dao->method('existsByIpp')->willReturn(false);
        $this->dao->method('insert')->willReturn(42);

        $this->sejours->expects($this->once())
            ->method('ouvrirSejour')
            ->with(42, 'L12', 'B3')
            ->willReturn($this->sejour(idPatient: 42, numLit: 'L12', numBox: 'B3'));

        $sejour = $this->service->inscrirePatient(['num_lit' => ' L12 ', 'num_box' => 'B3'] + self::DONNEES_VALIDES);

        $this->assertSame('L12', $sejour->getNumLit());
    }

    public function testChampsOptionnelsAbsentsSontNuls(): void
    {
        $this->dao->expects($this->never())->method('existsByIpp');
        $this->dao->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Patient $p): bool {
                $this->assertNull($p->getIpp());
                $this->assertNull($p->getTelephone());
                $this->assertNull($p->getEmail());

                return true;
            }))
            ->willReturn(7);
        $this->sejours->method('ouvrirSejour')->willReturn($this->sejour(idPatient: 7));

        $sejour = $this->service->inscrirePatient([
            'nom' => 'Martin',
            'prenom' => 'Paul',
            'date_naissance' => '1975-01-31',
            'sexe' => 'M',
            'ipp' => '   ',
            'email' => '',
        ]);

        $this->assertSame(7, $sejour->getIdPatient());
    }

    public function testPatientSousXSansDateDeNaissanceEstAccepte(): void
    {
        $this->dao->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Patient $p): bool {
                $this->assertSame('X', $p->getNom(), 'le nom est normalisé en majuscule');
                $this->assertSame('X', $p->getPrenom());
                $this->assertNull($p->getDateNaissance());
                $this->assertTrue($p->estAnonyme());

                return true;
            }))
            ->willReturn(8);
        $this->sejours->method('ouvrirSejour')->willReturn($this->sejour(idPatient: 8));

        $sejour = $this->service->inscrirePatient(['nom' => 'x', 'prenom' => 'x', 'sexe' => 'F']);

        $this->assertSame(8, $sejour->getIdPatient());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function donneesInvalides(): iterable
    {
        $base = self::DONNEES_VALIDES;

        yield 'nom vide' => [['nom' => '  '] + $base, 'obligatoires'];
        yield 'nom absent' => [array_diff_key($base, ['nom' => 1]), 'obligatoires'];
        yield 'prenom vide' => [['prenom' => ''] + $base, 'obligatoires'];
        yield 'nom trop long' => [['nom' => str_repeat('a', 51)] + $base, '50 caractères'];
        yield 'sexe inconnu' => [['sexe' => 'X'] + $base, 'sexe'];
        yield 'sexe absent' => [array_diff_key($base, ['sexe' => 1]), 'sexe'];
        yield 'date absente (patient identifié)' => [array_diff_key($base, ['date_naissance' => 1]), 'obligatoire'];
        yield 'date mal formée' => [['date_naissance' => '15/06/1990'] + $base, 'date de naissance'];
        yield 'date inexistante' => [['date_naissance' => '1990-02-30'] + $base, 'date de naissance'];
        yield 'date future' => [['date_naissance' => '2999-01-01'] + $base, 'futur'];
        yield 'ipp trop court' => [['ipp' => '12345'] + $base, 'IPP'];
        yield 'ipp non numérique' => [['ipp' => 'ABC1234'] + $base, 'IPP'];
        yield 'ipp pour un patient sous X' => [['nom' => 'X', 'prenom' => 'X'] + $base, 'sous X'];
        yield 'téléphone invalide' => [['telephone' => '06 01 02 03 04'] + $base, 'téléphone'];
        yield 'email invalide' => [['email' => 'pas-un-email'] + $base, 'email'];
    }

    /** @param array<string, mixed> $donnees */
    #[DataProvider('donneesInvalides')]
    public function testDonneesInvalidesSontRefuseesSansInsertion(array $donnees, string $fragmentMessage): void
    {
        $this->dao->method('existsByIpp')->willReturn(false);
        $this->dao->expects($this->never())->method('insert');
        $this->sejours->expects($this->never())->method('ouvrirSejour');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($fragmentMessage);

        $this->service->inscrirePatient($donnees);
    }

    public function testIppDejaConnuEstRefuse(): void
    {
        $this->dao->method('existsByIpp')->with('123456')->willReturn(true);
        $this->dao->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IPP 123456');

        $this->service->inscrirePatient(self::DONNEES_VALIDES);
    }

    public function testSejourRefuseAnnuleLInscription(): void
    {
        $this->dao->method('existsByIpp')->willReturn(false);
        $this->dao->method('insert')->willReturn(42);
        $this->sejours->method('ouvrirSejour')
            ->willThrowException(new InvalidArgumentException('Le lit L1 est déjà occupé'));

        // L'exception traverse la transaction : c'est elle qui annule l'insertion du patient.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('déjà occupé');

        $this->service->inscrirePatient(self::DONNEES_VALIDES);
    }

    public function testRechercheParIppQuandIlEstRenseigne(): void
    {
        $alice = $this->patient(3);
        $this->dao->method('findByIpp')->with('123456')->willReturn($alice);
        $this->dao->expects($this->never())->method('findByIdentite');

        $this->assertSame($alice, $this->service->rechercherPatientExistant(self::DONNEES_VALIDES));
    }

    public function testRechercheParIdentiteSeulementSiUnique(): void
    {
        $donnees = array_diff_key(self::DONNEES_VALIDES, ['ipp' => 1]);
        $alice = $this->patient(3);

        $this->dao->method('findByIdentite')
            ->with('Durand', 'Alice', $this->callback(fn($d) => $d->format('Y-m-d') === '1990-06-15'))
            ->willReturnOnConsecutiveCalls([$alice], [$alice, $this->patient(4)], []);

        $this->assertSame($alice, $this->service->rechercherPatientExistant($donnees), 'un seul : trouvé');
        $this->assertNull($this->service->rechercherPatientExistant($donnees), 'homonymes : ambigu');
        $this->assertNull($this->service->rechercherPatientExistant($donnees), 'aucun');
    }

    public function testRechercheJamaisPourUnPatientSousX(): void
    {
        $this->dao->expects($this->never())->method('findByIpp');
        $this->dao->expects($this->never())->method('findByIdentite');

        $donnees = ['nom' => 'X', 'prenom' => 'X', 'ipp' => '123456'];

        $this->assertNull($this->service->rechercherPatientExistant($donnees));
    }

    public function testRechercheIncompleteRenvoieNull(): void
    {
        $this->dao->expects($this->never())->method('findByIdentite');

        $this->assertNull($this->service->rechercherPatientExistant(['nom' => 'Durand', 'prenom' => 'Alice']));
    }

    public function testAdmettrePatientExistantOuvreUnSejour(): void
    {
        $this->dao->method('findById')->with(3)->willReturn($this->patient(3));
        $sejour = $this->sejour(idPatient: 3, numLit: 'L2');
        $this->sejours->expects($this->once())->method('ouvrirSejour')->with(3, 'L2', null)->willReturn($sejour);

        $this->assertSame($sejour, $this->service->admettrePatientExistant(3, 'L2'));
    }

    public function testAdmettrePatientInconnuEstRefuse(): void
    {
        $this->dao->method('findById')->with(99)->willReturn(null);
        $this->sejours->expects($this->never())->method('ouvrirSejour');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Patient 99 introuvable');

        $this->service->admettrePatientExistant(99);
    }

    public function testGetPatientInconnuLeveUneException(): void
    {
        $this->dao->method('findById')->with(99)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Patient 99 introuvable');

        $this->service->getPatient(99);
    }

    private function patient(int $id): Patient
    {
        return new Patient($id, null, 'Durand', 'Alice', new DateTimeImmutable('1990-06-15'), 'F');
    }

    private function sejour(int $idPatient, ?string $numLit = null, ?string $numBox = null): Sejour
    {
        return new Sejour(
            idSejour: 1,
            idPatient: $idPatient,
            numSejour: '2026-000001',
            dateAdmission: new DateTimeImmutable(),
            numLit: $numLit,
            numBox: $numBox
        );
    }
}
