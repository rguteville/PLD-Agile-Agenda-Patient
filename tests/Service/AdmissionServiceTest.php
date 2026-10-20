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

    /** @return iterable<string, array{string, string, string}> */
    public static function mesuresTropGrandes(): iterable
    {
        yield 'taille de 1000 cm' => ['taille', '1000', 'inférieure à 1000 cm'];
        yield 'poids de 1000 kg' => ['poids', '1000', 'inférieur à 1000 kg'];
        yield 'poids arrondi à 1000' => ['poids', '999.995', 'inférieur à 1000 kg'];
        yield 'notation exponentielle' => ['taille', '1e400', 'inférieure à 1000 cm'];
    }

    /** AURA-10.1 : colonnes DECIMAL(5,2), une valeur trop grande serait refusée par MariaDB. */
    #[DataProvider('mesuresTropGrandes')]
    public function testTailleOuPoidsTropGrandEstRefuse(string $champ, string $valeur, string $message): void
    {
        $erreurs = $this->service->valider([$champ => $valeur] + self::DONNEES_VALIDES);

        $this->assertSame([$champ], array_keys($erreurs));
        $this->assertStringContainsString($message, $erreurs[$champ]);
    }

    public function testMesuresALaLimiteDeLaColonneSontAcceptees(): void
    {
        $limites = ['taille' => '999.99', 'poids' => '0.01'];

        $this->assertSame([], $this->service->valider($limites + self::DONNEES_VALIDES));
    }

    public function testPrecautionsDeCinqCentsCaracteresSontAcceptees(): void
    {
        // Caractères accentués : la limite porte sur les caractères, pas les octets.
        $precautions = str_repeat('é', AdmissionService::PRECAUTIONS_LONGUEUR_MAX);

        $this->assertSame([], $this->service->valider(['precautions' => $precautions] + self::DONNEES_VALIDES));
    }

    public function testPrecautionsTropLonguesSontRefusees(): void
    {
        $precautions = str_repeat('a', AdmissionService::PRECAUTIONS_LONGUEUR_MAX + 1);

        $erreurs = $this->service->valider(['precautions' => $precautions] + self::DONNEES_VALIDES);

        $this->assertSame(['precautions'], array_keys($erreurs));
        $this->assertStringContainsString('500 caractères', $erreurs['precautions']);
    }

    public function testUnRetourALaLigneDuNavigateurCompteUnSeulCaractere(): void
    {
        // Le navigateur envoie \r\n, mais maxlength du textarea le compte pour un caractère.
        $precautions = str_repeat("a\r\n", 200);

        $this->assertSame([], $this->service->valider(['precautions' => $precautions] + self::DONNEES_VALIDES));
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

    public function testValiderPatientIgnoreLesChampsDuSejour(): void
    {
        $donnees = ['taille' => '-5', 'poids' => 'abc'] + self::DONNEES_VALIDES;

        $this->assertSame([], $this->service->validerPatientIdentite($donnees));
        $this->assertSame(['taille', 'poids'], array_keys($this->service->valider($donnees)));
    }

    public function testValiderPatientAppliqueLesMemesReglesQueValider(): void
    {
        $donnees = ['nom' => '', 'ipp' => '12', 'sexe' => 'X'] + self::DONNEES_VALIDES;

        $this->assertSame($this->service->valider($donnees), $this->service->validerPatientIdentite($donnees));
        $this->assertSame(['nom', 'ipp', 'sexe'], array_keys($this->service->validerPatientIdentite($donnees)));
    }
}
