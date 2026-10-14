<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\SejourDaoMemoire;
use Aura\Service\DossierPatientService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DossierPatientServiceTest extends TestCase
{
    private const PATIENT = [
        'id_patient' => 'P-9',
        'ipp' => null,
        'nom' => ' Durand ',
        'prenom' => 'Alice',
        'date_de_naissance' => '1990-06-15',
        'sexe' => 'F',
    ];

    private const SEJOUR = [
        'id_sejour' => '9',
        'num_sejour' => '2026-000009',
        'date_admission' => '2026-10-13 09:30:00',
        'date_sortie' => null,
        'num_lit' => '1',
        'num_box' => null,
        'id_service' => 'REA',
    ];

    public function testAgeRevoluAnniversairePasse(): void
    {
        $age = DossierPatientService::age(new DateTimeImmutable('1990-06-15'), new DateTimeImmutable('2026-10-14'));
        self::assertSame(36, $age);
    }

    public function testAgeRevoluAnniversaireAVenir(): void
    {
        $age = DossierPatientService::age(new DateTimeImmutable('1990-12-31'), new DateTimeImmutable('2026-10-14'));
        self::assertSame(35, $age);
    }

    public function testAgeNullSiNaissanceDansLeFutur(): void
    {
        $age = DossierPatientService::age(new DateTimeImmutable('2030-01-01'), new DateTimeImmutable('2026-10-14'));
        self::assertNull($age);
    }

    public function testBandeauSansIppNiAllergie(): void
    {
        $bandeau = DossierPatientService::presenterBandeau(
            self::PATIENT,
            self::SEJOUR,
            [],
            new DateTimeImmutable('2026-10-14')
        );

        self::assertSame('Durand', $bandeau['nom']);
        self::assertSame('Alice', $bandeau['prenom']);
        self::assertSame('15/06/1990', $bandeau['dateNaissance']);
        self::assertSame(36, $bandeau['age']);
        self::assertNull($bandeau['ipp'], 'un IPP vide doit rester null, pas devenir une chaîne vide');
        self::assertSame('2026-000009', $bandeau['numeroSejour']);
        self::assertSame('13/10/2026 09:30', $bandeau['dateAdmission']);
        self::assertSame([], $bandeau['allergies'], 'liste vide = « Aucune allergie connue » dans la vue');
    }

    public function testBandeauAvecIppEtAllergies(): void
    {
        $patient = ['ipp' => 100200300] + self::PATIENT;
        $allergies = [
            ['libelle' => 'Pénicilline', 'criticite' => 'sévère'],
            ['libelle' => 'Latex', 'criticite' => ''],
        ];

        $bandeau = DossierPatientService::presenterBandeau(
            $patient,
            self::SEJOUR,
            $allergies,
            new DateTimeImmutable('2026-10-14')
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

    public function testDateDeNaissanceInvalideIgnoree(): void
    {
        $patient = ['date_de_naissance' => 'inconnue'] + self::PATIENT;

        $bandeau = DossierPatientService::presenterBandeau(
            $patient,
            self::SEJOUR,
            [],
            new DateTimeImmutable('2026-10-14')
        );

        self::assertNull($bandeau['dateNaissance']);
        self::assertNull($bandeau['age']);
    }

    public function testSejourEnCoursOuClos(): void
    {
        $enCours = DossierPatientService::presenterSejour(self::SEJOUR, self::PATIENT);
        self::assertTrue($enCours['enCours']);
        self::assertNull($enCours['dateSortie']);
        self::assertNull($enCours['box']);

        $clos = DossierPatientService::presenterSejour(
            ['date_sortie' => '2026-10-20 11:00:00'] + self::SEJOUR,
            self::PATIENT
        );
        self::assertFalse($clos['enCours']);
        self::assertSame('20/10/2026 11:00', $clos['dateSortie']);
    }

    public function testDossierInexistantRenvoieNull(): void
    {
        $service = new DossierPatientService(new SejourDaoMemoire(), new DateTimeImmutable('2026-10-14'));

        self::assertNull($service->dossier('inexistant'));
    }

    public function testDossierExistantAssembleBandeauEtSejour(): void
    {
        $service = new DossierPatientService(new SejourDaoMemoire(), new DateTimeImmutable('2026-10-14'));

        $dossier = $service->dossier('2');

        self::assertNotNull($dossier);
        self::assertSame('Moreau', $dossier['bandeau']['nom']);
        self::assertCount(2, $dossier['bandeau']['allergies']);
        self::assertTrue($dossier['sejour']['enCours']);
    }
}
