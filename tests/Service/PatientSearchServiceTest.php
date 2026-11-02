<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Domain\Patient;
use Aura\Service\PatientSearchService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recherche d'un patient existant à l'admission : découpage de la saisie, reconnaissance d'un
 * IPP, présentation d'un résultat (identité seulement), homonymes, limite de 20.
 */
final class PatientSearchServiceTest extends TestCase
{
    private const AUJOURDHUI = '2026-10-14';

    /** @return iterable<string, array{string, list<string>}> */
    public static function saisies(): iterable
    {
        yield 'espaces multiples' => ['  dupont   jean ', ['dupont', 'jean']];
        yield 'tiret' => ['Jean-Pierre', ['Jean', 'Pierre']];
        yield 'apostrophe droite et typographique' => ["D'Arc d’Arc", ['D', 'Arc', 'd', 'Arc']];
        yield 'vide' => ['   ', []];
    }

    /** @param list<string> $attendu */
    #[DataProvider('saisies')]
    public function testDecoupageDeLaSaisieEnMots(string $saisie, array $attendu): void
    {
        self::assertSame($attendu, PatientSearchService::words($saisie));
    }

    public function testUneSaisieDe6A9ChiffresEstUnIpp(): void
    {
        self::assertTrue(PatientSearchService::isIpp(' 100000001 '));
        self::assertFalse(PatientSearchService::isIpp('12345'));
        self::assertFalse(PatientSearchService::isIpp('dupont'));
    }

    public function testUnResultatNeContientQueLIdentiteNecessaire(): void
    {
        $patient = new Patient(9, '100200300', ' Durand ', 'Alice', new DateTimeImmutable('1990-06-15'), 'F', details: [
            'birthLastName' => 'Martin',
            'medicalHistory' => 'HTA SECRET',
            'allergyNote' => 'NOTE SECRETE',
        ]);

        $r = PatientSearchService::presentResult(
            $patient,
            new DateTimeImmutable('2026-10-01 10:00'),
            new DateTimeImmutable(self::AUJOURDHUI)
        );

        self::assertSame([
            'idPatient' => 9, 'nom' => 'Durand', 'prenom' => 'Alice', 'nomNaissance' => 'Martin',
            'dateNaissance' => '15/06/1990', 'age' => 36, 'sexe' => 'F', 'ipp' => '100200300',
            'dernierSejour' => '01/10/2026',
        ], $r);
        self::assertStringNotContainsString('SECRET', json_encode($r, JSON_THROW_ON_ERROR));
    }

    public function testHomonymesDetectesSansTenirCompteDeLaCasse(): void
    {
        $a = ['nom' => 'Dupont', 'prenom' => 'Jean'];
        $b = ['nom' => 'DUPONT', 'prenom' => 'jean'];
        $c = ['nom' => 'Dupont', 'prenom' => 'Jeanne'];

        self::assertTrue(PatientSearchService::hasHomonyms([$a, $b]));
        self::assertFalse(PatientSearchService::hasHomonyms([$a, $c]));
        self::assertFalse(PatientSearchService::hasHomonyms([]));
    }

    public function testSaisieTropCourteDonneUneListeVideSansErreur(): void
    {
        $dao = $this->createMock(PatientDao::class);
        $dao->expects($this->never())->method('searchByWords');

        $r = (new PatientSearchService($dao, null, new DateTimeImmutable(self::AUJOURDHUI)))->search('d');

        self::assertSame('name', $r['mode']);
        self::assertSame([], $r['results']);
    }

    public function testIppConnuDonneUnSeulResultatEnModeIpp(): void
    {
        $dao = $this->createMock(PatientDao::class);
        $dao->method('findByIpp')->with('100200300')->willReturn(
            new Patient(9, '100200300', 'Durand', 'Alice', null, null)
        );
        $dao->method('lastAdmissionDate')->with(9)->willReturn(null);

        $r = (new PatientSearchService($dao, null, new DateTimeImmutable(self::AUJOURDHUI)))->search('100200300');

        self::assertSame('ipp', $r['mode']);
        self::assertCount(1, $r['results']);
        self::assertSame(9, $r['results'][0]['idPatient']);
        self::assertNull($r['results'][0]['dernierSejour']);
    }

    public function testIppInconnuDonneUneListeVide(): void
    {
        $dao = $this->createMock(PatientDao::class);
        $dao->method('findByIpp')->willReturn(null);

        $r = (new PatientSearchService($dao))->search('999999999');

        self::assertSame(['mode' => 'ipp', 'results' => [], 'truncated' => false, 'homonyms' => false], $r);
    }

    public function testPlusDe20ResultatsSontTronquesEtSignales(): void
    {
        $rows = [];
        for ($i = 1; $i <= 21; $i++) {
            $rows[] = ['patient' => new Patient($i, null, 'Dupont', 'P' . $i, null, null), 'lastAdmission' => null];
        }
        $dao = $this->createMock(PatientDao::class);
        $dao->method('searchByWords')->with(['dupont'], 21)->willReturn($rows);

        $r = (new PatientSearchService($dao))->search('dupont');

        self::assertCount(20, $r['results']);
        self::assertTrue($r['truncated']);
        self::assertFalse($r['homonyms']);
    }

    public function testFicheRenvoieIdentiteDetailsEtAllergies(): void
    {
        $dao = $this->createMock(PatientDao::class);
        $dao->method('findById')->with(9)->willReturn(
            new Patient(9, null, 'Durand', 'Alice', new DateTimeImmutable('1990-06-15'), 'F', details: [
                'medicalHistory' => 'HTA',
            ])
        );

        $fiche = (new PatientSearchService($dao))->record(9);

        self::assertNotNull($fiche);
        self::assertSame('1990-06-15', $fiche['identite']['dateNaissance']);
        self::assertNull($fiche['identite']['ipp']);
        self::assertSame('HTA', $fiche['details']['medicalHistory']);
        self::assertArrayHasKey('lifestyle', $fiche['details'], 'toutes les clés du catalogue sont présentes');
        self::assertNull($fiche['details']['lifestyle']);
        self::assertSame([], $fiche['allergies']);
    }

    public function testFicheDUnPatientInconnuEstNulle(): void
    {
        $dao = $this->createMock(PatientDao::class);
        $dao->method('findById')->willReturn(null);

        self::assertNull((new PatientSearchService($dao))->record(404));
    }
}
