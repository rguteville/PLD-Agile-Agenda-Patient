<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Domain\Patient;
use Aura\Service\PatientService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AURA-41 / AURA-10.2 : inscription d'un patient, validation côté serveur.
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
    private PatientService $service;

    protected function setUp(): void
    {
        $this->dao = $this->createMock(PatientDao::class);
        $this->service = new PatientService($this->dao);
    }

    public function testDonneesValidesInserentLePatientEtRenvoientSonId(): void
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

                return true;
            }))
            ->willReturn(42);

        $this->assertSame(42, $this->service->inscrirePatient(self::DONNEES_VALIDES));
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

        $id = $this->service->inscrirePatient([
            'nom' => 'Martin',
            'prenom' => 'Paul',
            'date_naissance' => '1975-01-31',
            'sexe' => 'M',
            'ipp' => '   ',
            'email' => '',
        ]);

        $this->assertSame(7, $id);
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
        yield 'date mal formée' => [['date_naissance' => '15/06/1990'] + $base, 'date de naissance'];
        yield 'date inexistante' => [['date_naissance' => '1990-02-30'] + $base, 'date de naissance'];
        yield 'date future' => [['date_naissance' => '2999-01-01'] + $base, 'futur'];
        yield 'ipp trop court' => [['ipp' => '12345'] + $base, 'IPP'];
        yield 'ipp non numérique' => [['ipp' => 'ABC1234'] + $base, 'IPP'];
        yield 'téléphone invalide' => [['telephone' => '06 01 02 03 04'] + $base, 'téléphone'];
        yield 'email invalide' => [['email' => 'pas-un-email'] + $base, 'email'];
    }

    /** @param array<string, mixed> $donnees */
    #[DataProvider('donneesInvalides')]
    public function testDonneesInvalidesSontRefuseesSansInsertion(array $donnees, string $fragmentMessage): void
    {
        $this->dao->method('existsByIpp')->willReturn(false);
        $this->dao->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($fragmentMessage);

        $this->service->inscrirePatient($donnees);
    }

    public function testIppDejaConnuEstRefuse(): void
    {
        $this->dao->method('existsByIpp')->with('123456')->willReturn(true);
        $this->dao->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('123456 existe déjà');

        $this->service->inscrirePatient(self::DONNEES_VALIDES);
    }

    public function testGetPatientInconnuLeveUneException(): void
    {
        $this->dao->method('findById')->with(99)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Patient 99 introuvable');

        $this->service->getPatient(99);
    }
}
