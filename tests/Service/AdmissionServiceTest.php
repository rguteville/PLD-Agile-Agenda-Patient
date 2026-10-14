<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\AdmissionService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AURA-10.2 : contrôle serveur des champs d'admission (RG1).
 */
final class AdmissionServiceTest extends TestCase
{
    /** Date de référence fixe pour que « strictement dans le passé » soit déterministe. */
    private const AUJOURDHUI = '2026-10-14';

    private const DONNEES_VALIDES = [
        'nom' => 'Durand',
        'prenom' => 'Alice',
        'ipp' => '123456',
        'dateNaissance' => '1990-06-15',
        'sexe' => 'F',
        'taille' => '168',
        'poids' => '61.5',
        'precautions' => 'Isolement contact',
    ];

    private AdmissionService $service;

    protected function setUp(): void
    {
        $this->service = new AdmissionService(new DateTimeImmutable(self::AUJOURDHUI));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function champsObligatoiresManquants(): iterable
    {
        yield 'nom vide' => [['nom' => ''] + self::DONNEES_VALIDES, 'nom'];
        yield 'nom espaces' => [['nom' => '   '] + self::DONNEES_VALIDES, 'nom'];
        yield 'nom absent' => [array_diff_key(self::DONNEES_VALIDES, ['nom' => 1]), 'nom'];
        yield 'nom non scalaire' => [['nom' => ['x']] + self::DONNEES_VALIDES, 'nom'];
        yield 'prenom vide' => [['prenom' => ''] + self::DONNEES_VALIDES, 'prenom'];
        yield 'prenom espaces' => [['prenom' => "\t "] + self::DONNEES_VALIDES, 'prenom'];
        yield 'prenom absent' => [array_diff_key(self::DONNEES_VALIDES, ['prenom' => 1]), 'prenom'];
    }

    /** @param array<string, mixed> $donnees */
    #[DataProvider('champsObligatoiresManquants')]
    public function testChampObligatoireManquantDonneUneSeuleErreurSurCeChamp(array $donnees, string $champ): void
    {
        $erreurs = $this->service->valider($donnees);

        $this->assertSame([$champ], array_keys($erreurs), 'seul le champ manquant est en erreur');
        $this->assertStringContainsString('obligatoire', $erreurs[$champ]);
    }

    public function testNomEtPrenomSansAutreChampSontValides(): void
    {
        $this->assertSame([], $this->service->valider(['nom' => ' Martin ', 'prenom' => 'Paul']));
    }

    public function testNomEtPrenomAvecAutresChampsVidesSontValides(): void
    {
        $donnees = array_fill_keys(array_keys(self::DONNEES_VALIDES), '');
        $donnees['nom'] = 'Martin';
        $donnees['prenom'] = 'Paul';

        $this->assertSame([], $this->service->valider($donnees));
    }

    public function testIppEstFacultatif(): void
    {
        $this->assertSame([], $this->service->valider(['nom' => 'Martin', 'prenom' => 'Paul', 'ipp' => '']));
        $this->assertSame([], $this->service->valider(['nom' => 'Martin', 'prenom' => 'Paul', 'ipp' => ' 987654 ']));
    }

    /** @return iterable<string, array{string}> */
    public static function ippInvalides(): iterable
    {
        yield 'trop court' => ['12345'];
        yield 'trop long' => ['1234567890'];
        yield 'lettres' => ['ABC123'];
        yield 'espaces internes' => ['123 456'];
        yield 'négatif' => ['-123456'];
    }

    #[DataProvider('ippInvalides')]
    public function testIppHorsFormatEstRefuse(string $ipp): void
    {
        $erreurs = $this->service->valider(['nom' => 'Martin', 'prenom' => 'Paul', 'ipp' => $ipp]);

        $this->assertSame(['ipp'], array_keys($erreurs));
    }

    public function testIppDeSixANeufChiffresEstAccepte(): void
    {
        foreach (['100000', '999999999'] as $ipp) {
            $this->assertSame([], $this->service->valider(['nom' => 'Martin', 'prenom' => 'Paul', 'ipp' => $ipp]));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function datesDeNaissanceInvalides(): iterable
    {
        yield 'mois et jour inexistants' => ['2026-13-45'];
        yield '30 février' => ['1990-02-30'];
        yield 'format français' => ['15/06/1990'];
        yield 'texte' => ['hier'];
        yield 'date et heure' => ['1990-06-15 10:00'];
    }

    #[DataProvider('datesDeNaissanceInvalides')]
    public function testDateDeNaissanceInvalideEstRefusee(string $date): void
    {
        $erreurs = $this->service->valider(['dateNaissance' => $date] + self::DONNEES_VALIDES);

        $this->assertSame(['dateNaissance'], array_keys($erreurs));
        $this->assertStringContainsString('invalide', $erreurs['dateNaissance']);
        $this->assertStringNotContainsString($date, $erreurs['dateNaissance'], 'la valeur saisie ne figure pas');
    }

    /** @return iterable<string, array{string}> */
    public static function datesDeNaissanceNonPassees(): iterable
    {
        yield 'future' => ['2999-01-01'];
        yield 'demain' => ['2026-10-15'];
        yield "aujourd'hui" => [self::AUJOURDHUI];
    }

    #[DataProvider('datesDeNaissanceNonPassees')]
    public function testDateDeNaissanceNonPasseeEstRefusee(string $date): void
    {
        $erreurs = $this->service->valider(['dateNaissance' => $date] + self::DONNEES_VALIDES);

        $this->assertSame(['dateNaissance'], array_keys($erreurs));
        $this->assertStringContainsString('antérieure', $erreurs['dateNaissance']);
    }

    public function testDateDeNaissanceDeLaVeilleEstAcceptee(): void
    {
        $this->assertSame([], $this->service->valider(['dateNaissance' => '2026-10-13'] + self::DONNEES_VALIDES));
    }

    /** @return iterable<string, array{string}> */
    public static function sexesHorsListe(): iterable
    {
        yield 'inconnu' => ['X'];
        yield 'valeur formulaire non convertie (H)' => ['H'];
        yield 'minuscule' => ['f'];
    }

    #[DataProvider('sexesHorsListe')]
    public function testSexeHorsListeEstRefuse(string $sexe): void
    {
        $erreurs = $this->service->valider(['sexe' => $sexe] + self::DONNEES_VALIDES);

        $this->assertSame(['sexe'], array_keys($erreurs));
    }

    public function testSexesAutorisesSontAcceptes(): void
    {
        foreach (AdmissionService::SEXES_AUTORISES as $sexe) {
            $this->assertSame([], $this->service->valider(['sexe' => $sexe] + self::DONNEES_VALIDES));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function mesuresInvalides(): iterable
    {
        yield 'taille texte' => ['taille', 'abc'];
        yield 'taille négative' => ['taille', '-5'];
        yield 'taille nulle' => ['taille', '0'];
        yield 'poids nul' => ['poids', '0'];
        yield 'poids négatif' => ['poids', '-70'];
        yield 'poids avec unité' => ['poids', '70kg'];
    }

    #[DataProvider('mesuresInvalides')]
    public function testTailleOuPoidsNonPositifEstRefuse(string $champ, string $valeur): void
    {
        $erreurs = $this->service->valider([$champ => $valeur] + self::DONNEES_VALIDES);

        $this->assertSame([$champ], array_keys($erreurs));
        $this->assertStringContainsString('strictement positif', $erreurs[$champ]);
    }

    public function testJeuCompletValideNeDonneAucuneErreur(): void
    {
        $this->assertSame([], $this->service->valider(self::DONNEES_VALIDES));
    }

    public function testLesErreursSeCumulentUneParChamp(): void
    {
        $erreurs = $this->service->valider([
            'nom' => '',
            'prenom' => ' ',
            'ipp' => 'n’importe quoi',
            'dateNaissance' => '2026-13-45',
            'sexe' => 'X',
            'taille' => 'abc',
            'poids' => '0',
        ]);

        $this->assertSame(['nom', 'prenom', 'ipp', 'dateNaissance', 'sexe', 'taille', 'poids'], array_keys($erreurs));
    }

    public function testLaValidationNeModifiePasLesDonnees(): void
    {
        $donnees = self::DONNEES_VALIDES;
        $this->service->valider($donnees);

        $this->assertSame(self::DONNEES_VALIDES, $donnees);
    }
}
