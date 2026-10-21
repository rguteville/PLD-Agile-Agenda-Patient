<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Dao\SejourDao;
use Aura\Dao\SubstanceDao;
use Aura\Domain\Allergie;
use Aura\Domain\Patient;
use Aura\Domain\Sejour;
use Aura\Domain\Substance;
use Aura\Service\DossierPatientService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * AURA-14 : bandeau identité + allergies (RG2) et dossier du séjour, sans base (DAO simulés).
 * AURA-13.2 : section « Allergies connues » (id pour le retrait, référentiel pour l'ajout).
 */
final class DossierPatientServiceTest extends TestCase
{
    private const AUJOURDHUI = '2026-10-14';

    public function testAgeRevoluAnniversairePasse(): void
    {
        $age = DossierPatientService::age(new DateTimeImmutable('1990-06-15'), new DateTimeImmutable(self::AUJOURDHUI));
        self::assertSame(36, $age);
    }

    public function testAgeRevoluAnniversaireAVenir(): void
    {
        $age = DossierPatientService::age(new DateTimeImmutable('1990-12-31'), new DateTimeImmutable(self::AUJOURDHUI));
        self::assertSame(35, $age);
    }

    public function testAgeNullSiNaissanceDansLeFutur(): void
    {
        $age = DossierPatientService::age(new DateTimeImmutable('2030-01-01'), new DateTimeImmutable(self::AUJOURDHUI));
        self::assertNull($age);
    }

    public function testBandeauSansIppNiAllergie(): void
    {
        $bandeau = DossierPatientService::presenterBandeau(
            $this->patient(ipp: null),
            $this->sejour(),
            [],
            new DateTimeImmutable(self::AUJOURDHUI)
        );

        self::assertSame('Durand', $bandeau['nom'], 'le nom est nettoyé (trim)');
        self::assertSame('Alice', $bandeau['prenom']);
        self::assertSame('F', $bandeau['sexe']);
        self::assertSame('15/06/1990', $bandeau['dateNaissance']);
        self::assertSame(36, $bandeau['age']);
        self::assertNull($bandeau['ipp'], 'un IPP absent doit rester null, pas devenir une chaîne vide');
        self::assertSame('2026-000009', $bandeau['numeroSejour']);
        self::assertSame('13/10/2026 09:30', $bandeau['dateAdmission']);
        self::assertSame([], $bandeau['allergies'], 'liste vide = « Aucune allergie connue » dans la vue');
    }

    public function testBandeauAvecIppEtAllergies(): void
    {
        $allergies = [
            new Allergie(1, 9, 'PENI', 'Pénicilline', 'sévère'),
            new Allergie(2, 9, 'LATEX', ' Latex ', ''),
        ];

        $bandeau = DossierPatientService::presenterBandeau(
            $this->patient(ipp: '100200300'),
            $this->sejour(),
            $allergies,
            new DateTimeImmutable(self::AUJOURDHUI)
        );

        self::assertSame('100200300', $bandeau['ipp']);
        self::assertSame(
            [
                ['libelle' => 'Pénicilline', 'criticite' => 'sévère'],
                ['libelle' => 'Latex', 'criticite' => null],
            ],
            $bandeau['allergies']
        );
    }

    public function testPatientSousXSansDateDeNaissance(): void
    {
        $sousX = new Patient(7, null, 'X', 'X', null, 'M');

        $bandeau = DossierPatientService::presenterBandeau(
            $sousX,
            $this->sejour(),
            [],
            new DateTimeImmutable(self::AUJOURDHUI)
        );

        self::assertNull($bandeau['dateNaissance']);
        self::assertNull($bandeau['age']);
    }

    public function testNumeroDeSejourVideRemplaceParIdentifiant(): void
    {
        $bandeau = DossierPatientService::presenterBandeau(
            $this->patient(ipp: null),
            $this->sejour(numSejour: ''),
            [],
            new DateTimeImmutable(self::AUJOURDHUI)
        );

        self::assertSame('9', $bandeau['numeroSejour']);
    }

    public function testSejourEnCoursOuClos(): void
    {
        $enCours = DossierPatientService::presenterSejour($this->sejour(), $this->patient(ipp: null));
        self::assertTrue($enCours['enCours']);
        self::assertNull($enCours['dateSortie']);
        self::assertNull($enCours['box']);
        self::assertSame('1', $enCours['lit']);
        self::assertSame('REA', $enCours['service']);

        $clos = DossierPatientService::presenterSejour(
            $this->sejour(dateSortie: new DateTimeImmutable('2026-10-20 11:00:00')),
            $this->patient(ipp: null)
        );
        self::assertFalse($clos['enCours']);
        self::assertSame('20/10/2026 11:00', $clos['dateSortie']);
    }

    /** AURA-10.1 : poids, taille et précautions relevés à l'admission. */
    public function testSejourAvecMesuresDAdmission(): void
    {
        $sejour = DossierPatientService::presenterSejour(
            $this->sejour(poids: 62.5, taille: 164.0, precautions: " Isolement contact\nRisque de chute "),
            $this->patient(ipp: null)
        );

        self::assertSame('62,5 kg', $sejour['poids']);
        self::assertSame('164 cm', $sejour['taille'], 'pas de « ,00 » inutile');
        self::assertSame("Isolement contact\nRisque de chute", $sejour['precautions']);
    }

    public function testSejourSansMesuresDAdmission(): void
    {
        $sejour = DossierPatientService::presenterSejour(
            $this->sejour(precautions: '   '),
            $this->patient(ipp: null)
        );

        self::assertNull($sejour['poids'], 'null = « non renseigné » dans la vue');
        self::assertNull($sejour['taille']);
        self::assertNull($sejour['precautions'], 'des précautions vides ne s\'affichent pas');
    }

    public function testMesureALaFrancaise(): void
    {
        self::assertSame('57,2 kg', DossierPatientService::mesure(57.2, 'kg'));
        self::assertSame('172,46 cm', DossierPatientService::mesure(172.46, 'cm'));
        self::assertSame('100 cm', DossierPatientService::mesure(100.0, 'cm'), 'les zéros des entiers restent');
        self::assertSame('0,05 kg', DossierPatientService::mesure(0.05, 'kg'));
        self::assertNull(DossierPatientService::mesure(null, 'kg'));
    }

    public function testDossierInexistantRenvoieNull(): void
    {
        $sejours = $this->createMock(SejourDao::class);
        $sejours->method('findById')->with(42)->willReturn(null);
        $patients = $this->createMock(PatientDao::class);
        $patients->expects($this->never())->method('findById');
        $allergies = $this->createMock(AllergieDao::class);
        $allergies->expects($this->never())->method('findByPatient');

        $service = new DossierPatientService(
            $sejours,
            $patients,
            $allergies,
            $this->createMock(SubstanceDao::class),
            new DateTimeImmutable(self::AUJOURDHUI)
        );

        self::assertNull($service->dossier(42));
    }

    public function testDossierSansPatientRenvoieNull(): void
    {
        $sejours = $this->createMock(SejourDao::class);
        $sejours->method('findById')->willReturn($this->sejour());
        $patients = $this->createMock(PatientDao::class);
        $patients->method('findById')->with(9)->willReturn(null);
        $allergies = $this->createMock(AllergieDao::class);

        $service = new DossierPatientService(
            $sejours,
            $patients,
            $allergies,
            $this->createMock(SubstanceDao::class),
            new DateTimeImmutable(self::AUJOURDHUI)
        );

        self::assertNull($service->dossier(9), 'séjour orphelin : données incohérentes, pas d\'erreur PHP');
    }

    public function testDossierExistantAssembleBandeauEtSejour(): void
    {
        $sejours = $this->createMock(SejourDao::class);
        $sejours->method('findById')->with(9)->willReturn($this->sejour());
        $patients = $this->createMock(PatientDao::class);
        $patients->method('findById')->with(9)->willReturn($this->patient(ipp: '100200300'));
        $allergies = $this->createMock(AllergieDao::class);
        $allergies->method('findByPatient')->with(9)->willReturn([
            new Allergie(1, 9, 'PENI', 'Pénicilline', 'sévère'),
            new Allergie(2, 9, 'LATEX', 'Latex', 'modérée'),
        ]);

        $substances = $this->createMock(SubstanceDao::class);
        $substances->method('findAll')->willReturn([
            new Substance('ARACH', 'Arachide'),
            new Substance('LATEX', 'Latex'),
            new Substance('PENI', 'Pénicilline'),
        ]);

        $service = new DossierPatientService(
            $sejours,
            $patients,
            $allergies,
            $substances,
            new DateTimeImmutable(self::AUJOURDHUI)
        );

        $dossier = $service->dossier(9);

        self::assertNotNull($dossier);
        self::assertSame('Durand', $dossier['bandeau']['nom']);
        self::assertSame('100200300', $dossier['bandeau']['ipp']);
        self::assertCount(2, $dossier['bandeau']['allergies']);
        self::assertTrue($dossier['sejour']['enCours']);
        self::assertSame(9, $dossier['sejour']['idSejour'], 'id renvoyé par les formulaires d\'allergie');
        self::assertSame([1, 2], array_column($dossier['allergies'], 'idAllergy'), 'ids pour le bouton Retirer');
        self::assertSame(['ARACH' => 'Arachide'], $dossier['substances'], 'les allergènes déjà connus sont retirés');
        self::assertSame(Allergie::CRITICALITIES, $dossier['criticalities']);
    }

    /** AURA-13.2 : section « Allergies connues » du dossier. */
    public function testPresentAllergiesWithIdCriticalityAndDate(): void
    {
        $allergies = [
            new Allergie(1, 9, 'PENI', ' Pénicilline ', 'Élevée', new DateTimeImmutable('2026-06-02 10:20:00')),
            new Allergie(2, 9, 'LATEX', 'Latex', '  ', null),
        ];

        self::assertSame(
            [
                ['idAllergy' => 1, 'label' => 'Pénicilline', 'criticality' => 'Élevée', 'recordedAt' => '02/06/2026'],
                ['idAllergy' => 2, 'label' => 'Latex', 'criticality' => null, 'recordedAt' => null],
            ],
            DossierPatientService::presentAllergies($allergies)
        );
        self::assertSame([], DossierPatientService::presentAllergies([]));
    }

    public function testAvailableSubstancesExcludesPatientAllergiesAndKeepsOrder(): void
    {
        $referentiel = [
            new Substance('ARACH', 'Arachide'),
            new Substance('LATEX', ' Latex '),
            new Substance('PENI', 'Pénicilline'),
        ];
        $allergies = [new Allergie(1, 9, 'PENI', 'Pénicilline')];

        self::assertSame(
            ['ARACH' => 'Arachide', 'LATEX' => 'Latex'],
            DossierPatientService::availableSubstances($referentiel, $allergies)
        );
        self::assertSame(
            ['ARACH' => 'Arachide', 'LATEX' => 'Latex', 'PENI' => 'Pénicilline'],
            DossierPatientService::availableSubstances($referentiel, []),
            'patient sans allergie : tout le référentiel'
        );
        self::assertSame([], DossierPatientService::availableSubstances([], $allergies));
    }

    private function patient(?string $ipp): Patient
    {
        return new Patient(9, $ipp, ' Durand ', 'Alice', new DateTimeImmutable('1990-06-15'), 'F');
    }

    private function sejour(
        string $numSejour = '2026-000009',
        ?DateTimeImmutable $dateSortie = null,
        ?float $poids = null,
        ?float $taille = null,
        ?string $precautions = null
    ): Sejour {
        return new Sejour(
            idSejour: 9,
            idPatient: 9,
            numSejour: $numSejour,
            dateAdmission: new DateTimeImmutable('2026-10-13 09:30:00'),
            dateSortie: $dateSortie,
            numLit: '1',
            numBox: null,
            idService: 'REA',
            poids: $poids,
            taille: $taille,
            precautions: $precautions
        );
    }
}
