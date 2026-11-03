<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\PatientDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\SubstanceDao;
use Aura\Domain\AllergyIntolerance;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;
use Aura\Domain\Substance;
use Aura\Service\PatientRecordService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * AURA-14 : bandeau identité + allergies (RG2) et dossier du séjour, sans base (DAO simulés).
 * AURA-13.2 : section « Allergies connues » (id pour le retrait, référentiel pour l'ajout).
 */
final class PatientRecordServiceTest extends TestCase
{
    private const TODAY = '2026-10-14';

    public function testAgeInFullYearsBirthdayPassed(): void
    {
        $age = PatientRecordService::age(new DateTimeImmutable('1990-06-15'), new DateTimeImmutable(self::TODAY));
        self::assertSame(36, $age);
    }

    public function testAgeInFullYearsBirthdayUpcoming(): void
    {
        $age = PatientRecordService::age(new DateTimeImmutable('1990-12-31'), new DateTimeImmutable(self::TODAY));
        self::assertSame(35, $age);
    }

    public function testAgeNullWhenBirthIsInTheFuture(): void
    {
        $age = PatientRecordService::age(new DateTimeImmutable('2030-01-01'), new DateTimeImmutable(self::TODAY));
        self::assertNull($age);
    }

    public function testBannerWithoutIppOrAllergy(): void
    {
        $banner = PatientRecordService::presentBanner(
            $this->patient(ipp: null),
            $this->episodeOfCare(),
            [],
            new DateTimeImmutable(self::TODAY)
        );

        self::assertSame('Durand', $banner['lastName'], 'le nom est nettoyé (trim)');
        self::assertSame('Alice', $banner['firstName']);
        self::assertSame('F', $banner['sex']);
        self::assertSame('15/06/1990', $banner['birthDate']);
        self::assertSame(36, $banner['age']);
        self::assertNull($banner['ipp'], 'un IPP absent doit rester null, pas devenir une chaîne vide');
        self::assertSame('2026-000009', $banner['episodeNumber']);
        self::assertSame('13/10/2026 09:30', $banner['admissionDate']);
        self::assertNull($banner['weight'], 'mesure absente = null, la vue affiche « non renseigné »');
        self::assertNull($banner['height']);
        self::assertSame([], $banner['allergies'], 'liste vide = « Aucune allergie connue » dans la vue');
    }

    public function testBannerShowsBloodType(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        $patient = new Patient(9, null, 'Durand', 'Alice', null, 'F', details: ['bloodType' => 'O-']);

        $avec = PatientRecordService::presentBanner($patient, $this->episodeOfCare(), [], $today);
        $sans = PatientRecordService::presentBanner($this->patient(null), $this->episodeOfCare(), [], $today);

        self::assertSame('O-', $avec['bloodType']);
        self::assertNull($sans['bloodType'], 'non renseigné');
    }

    public function testBannerCarriesAllergyStatus(): void
    {
        $sans = PatientRecordService::presentBanner(
            $this->patient(null),
            $this->episodeOfCare(),
            [],
            new DateTimeImmutable(self::TODAY)
        );
        $avec = PatientRecordService::presentBanner(
            $this->patient(null),
            $this->episodeOfCare(),
            [new AllergyIntolerance(1, 9, 'PENI', 'Pénicilline', 'Élevée')],
            new DateTimeImmutable(self::TODAY)
        );

        self::assertSame('none', $sans['allergiesStatus']);
        self::assertSame('known', $avec['allergiesStatus']);
    }

    public function testBannerShowsEpisodeWeightAndHeight(): void
    {
        $banner = PatientRecordService::presentBanner(
            $this->patient(ipp: null),
            $this->episodeOfCare(weight: 62.5, height: 164.0),
            [],
            new DateTimeImmutable(self::TODAY)
        );

        self::assertSame('62,5 kg', $banner['weight']);
        self::assertSame('164 cm', $banner['height']);
    }

    public function testBannerWithIppAndAllergies(): void
    {
        $allergies = [
            new AllergyIntolerance(1, 9, 'PENI', 'Pénicilline', 'sévère'),
            new AllergyIntolerance(2, 9, 'LATEX', ' Latex ', ''),
        ];

        $banner = PatientRecordService::presentBanner(
            $this->patient(ipp: '100200300'),
            $this->episodeOfCare(),
            $allergies,
            new DateTimeImmutable(self::TODAY)
        );

        self::assertSame('100200300', $banner['ipp']);
        self::assertSame(
            [
                ['label' => 'Pénicilline', 'criticality' => 'sévère', 'level' => 'unknown'],
                ['label' => 'Latex', 'criticality' => null, 'level' => 'unknown'],
            ],
            $banner['allergies']
        );
    }

    public function testAnonymousBirthPatientWithoutBirthDate(): void
    {
        $underX = new Patient(7, null, 'X', 'X', null, 'M');

        $banner = PatientRecordService::presentBanner(
            $underX,
            $this->episodeOfCare(),
            [],
            new DateTimeImmutable(self::TODAY)
        );

        self::assertNull($banner['birthDate']);
        self::assertNull($banner['age']);
    }

    public function testEmptyEpisodeNumberReplacedById(): void
    {
        $banner = PatientRecordService::presentBanner(
            $this->patient(ipp: null),
            $this->episodeOfCare(episodeNumber: ''),
            [],
            new DateTimeImmutable(self::TODAY)
        );

        self::assertSame('9', $banner['episodeNumber']);
    }

    public function testOngoingOrClosedEpisode(): void
    {
        $ongoing = PatientRecordService::presentEpisodeOfCare($this->episodeOfCare(), $this->patient(ipp: null));
        self::assertTrue($ongoing['ongoing']);
        self::assertNull($ongoing['dischargeDate']);
        self::assertNull($ongoing['box']);
        self::assertSame('1', $ongoing['bed']);
        self::assertSame('REA', $ongoing['service']);

        $closed = PatientRecordService::presentEpisodeOfCare(
            $this->episodeOfCare(dischargeDate: new DateTimeImmutable('2026-10-20 11:00:00')),
            $this->patient(ipp: null)
        );
        self::assertFalse($closed['ongoing']);
        self::assertSame('20/10/2026 11:00', $closed['dischargeDate']);
    }

    /** AURA-10.1 : poids, taille et précautions relevés à l'admission. */
    public function testEpisodeWithAdmissionMeasures(): void
    {
        $episodeOfCare = PatientRecordService::presentEpisodeOfCare(
            $this->episodeOfCare(weight: 62.5, height: 164.0, precautions: " Isolement contact\nRisque de chute "),
            $this->patient(ipp: null)
        );

        self::assertSame('62,5 kg', $episodeOfCare['weight']);
        self::assertSame('164 cm', $episodeOfCare['height'], 'pas de « ,00 » inutile');
        self::assertSame("Isolement contact\nRisque de chute", $episodeOfCare['precautions']);
    }

    public function testEpisodeWithoutAdmissionMeasures(): void
    {
        $episodeOfCare = PatientRecordService::presentEpisodeOfCare(
            $this->episodeOfCare(precautions: '   '),
            $this->patient(ipp: null)
        );

        self::assertNull($episodeOfCare['weight'], 'null = « non renseigné » dans la vue');
        self::assertNull($episodeOfCare['height']);
        self::assertNull($episodeOfCare['precautions'], 'des précautions vides ne s\'affichent pas');
    }

    public function testFrenchStyleMeasure(): void
    {
        self::assertSame('57,2 kg', PatientRecordService::measure(57.2, 'kg'));
        self::assertSame('172,46 cm', PatientRecordService::measure(172.46, 'cm'));
        self::assertSame('100 cm', PatientRecordService::measure(100.0, 'cm'), 'les zéros des entiers restent');
        self::assertSame('0,05 kg', PatientRecordService::measure(0.05, 'kg'));
        self::assertNull(PatientRecordService::measure(null, 'kg'));
    }

    public function testNonexistentRecordReturnsNull(): void
    {
        $episodesOfCare = $this->createMock(EpisodeOfCareDao::class);
        $episodesOfCare->method('findById')->with(42)->willReturn(null);
        $patients = $this->createMock(PatientDao::class);
        $patients->expects($this->never())->method('findById');
        $allergies = $this->createMock(AllergyIntoleranceDao::class);
        $allergies->expects($this->never())->method('findByPatient');

        $service = new PatientRecordService(
            $episodesOfCare,
            $patients,
            $allergies,
            $this->createMock(SubstanceDao::class),
            new DateTimeImmutable(self::TODAY)
        );

        self::assertNull($service->record(42));
    }

    public function testRecordWithoutPatientReturnsNull(): void
    {
        $episodesOfCare = $this->createMock(EpisodeOfCareDao::class);
        $episodesOfCare->method('findById')->willReturn($this->episodeOfCare());
        $patients = $this->createMock(PatientDao::class);
        $patients->method('findById')->with(9)->willReturn(null);
        $allergies = $this->createMock(AllergyIntoleranceDao::class);

        $service = new PatientRecordService(
            $episodesOfCare,
            $patients,
            $allergies,
            $this->createMock(SubstanceDao::class),
            new DateTimeImmutable(self::TODAY)
        );

        self::assertNull($service->record(9), 'séjour orphelin : données incohérentes, pas d\'erreur PHP');
    }

    public function testExistingRecordAssemblesBannerAndEpisode(): void
    {
        $episodesOfCare = $this->createMock(EpisodeOfCareDao::class);
        $episodesOfCare->method('findById')->with(9)->willReturn($this->episodeOfCare());
        $patients = $this->createMock(PatientDao::class);
        $patients->method('findById')->with(9)->willReturn($this->patient(ipp: '100200300'));
        $allergies = $this->createMock(AllergyIntoleranceDao::class);
        $allergies->method('findByPatient')->with(9)->willReturn([
            new AllergyIntolerance(1, 9, 'PENI', 'Pénicilline', 'sévère'),
            new AllergyIntolerance(2, 9, 'LATEX', 'Latex', 'modérée'),
        ]);

        $substances = $this->createMock(SubstanceDao::class);
        $substances->method('findAll')->willReturn([
            new Substance('ARACH', 'Arachide'),
            new Substance('LATEX', 'Latex'),
            new Substance('PENI', 'Pénicilline'),
        ]);

        $service = new PatientRecordService(
            $episodesOfCare,
            $patients,
            $allergies,
            $substances,
            new DateTimeImmutable(self::TODAY)
        );

        $record = $service->record(9);

        self::assertNotNull($record);
        self::assertSame('Durand', $record['banner']['lastName']);
        self::assertSame('100200300', $record['banner']['ipp']);
        self::assertCount(2, $record['banner']['allergies']);
        self::assertTrue($record['episodeOfCare']['ongoing']);
        self::assertSame(9, $record['episodeOfCare']['idEpisodeOfCare'], 'id renvoyé par les formulaires d\'allergie');
        self::assertSame([1, 2], array_column($record['allergies'], 'idAllergy'), 'ids pour le bouton Retirer');
        self::assertSame(['ARACH' => 'Arachide'], $record['substances'], 'les allergènes déjà connus sont retirés');
        self::assertSame(AllergyIntolerance::CRITICALITIES, $record['criticalities']);
    }

    /** AURA-13.2 : section « Allergies connues » du dossier. */
    public function testPresentAllergiesWithIdCriticalityAndDate(): void
    {
        $allergies = [
            new AllergyIntolerance(
                1,
                9,
                'PENI',
                ' Pénicilline ',
                'Élevée',
                new DateTimeImmutable('2026-06-02 10:20:00')
            ),
            new AllergyIntolerance(2, 9, 'LATEX', 'Latex', '  ', null),
        ];

        self::assertSame(
            [
                [
                    'idAllergy' => 1, 'label' => 'Pénicilline', 'criticality' => 'Élevée', 'level' => 'high',
                    'recordedAt' => '02/06/2026',
                ],
                [
                    'idAllergy' => 2, 'label' => 'Latex', 'criticality' => null, 'level' => 'unknown',
                    'recordedAt' => null,
                ],
            ],
            PatientRecordService::presentAllergies($allergies)
        );
        self::assertSame([], PatientRecordService::presentAllergies([]));
    }

    public function testAvailableSubstancesExcludesPatientAllergiesAndKeepsOrder(): void
    {
        $referenceData = [
            new Substance('ARACH', 'Arachide'),
            new Substance('LATEX', ' Latex '),
            new Substance('PENI', 'Pénicilline'),
        ];
        $allergies = [new AllergyIntolerance(1, 9, 'PENI', 'Pénicilline')];

        self::assertSame(
            ['ARACH' => 'Arachide', 'LATEX' => 'Latex'],
            PatientRecordService::availableSubstances($referenceData, $allergies)
        );
        self::assertSame(
            ['ARACH' => 'Arachide', 'LATEX' => 'Latex', 'PENI' => 'Pénicilline'],
            PatientRecordService::availableSubstances($referenceData, []),
            'patient sans allergie : tout le référentiel'
        );
        self::assertSame([], PatientRecordService::availableSubstances([], $allergies));
    }

    /** Dossier complet : chaque section reste présente, une valeur absente est null (« Non renseigné »). */
    public function testPresentSectionsCountsFilledFieldsAndTranslatesScales(): void
    {
        $patient = new Patient(9, null, 'Durand', 'Alice', new DateTimeImmutable('1990-06-15'), 'F', details: [
            'medicalHistory' => "HTA\nDiabète",
        ]);
        $episodeOfCare = new EpisodeOfCare(
            idEpisodeOfCare: 9,
            idPatient: 9,
            episodeNumber: '2026-000009',
            admissionDate: new DateTimeImmutable('2026-10-13 09:30:00'),
            idService: 'SRV01',
            weight: 60.0,
            height: 160.0,
            details: ['asaScore' => 'III', 'surgeryDate' => '2026-10-12', 'admissionReason' => 'Choc']
        );

        $sections = PatientRecordService::presentSections(
            $patient,
            $episodeOfCare,
            ['SRV01' => 'Réanimation médicale']
        );

        self::assertSame(
            ['admission', 'history', 'disease', 'scores', 'measures', 'admin'],
            array_column($sections, 'id')
        );
        $byId = array_column($sections, null, 'id');
        self::assertSame(2, $byId['admission']['filled'], 'motif + service');
        self::assertSame('Réanimation médicale', $byId['admission']['rows'][1]['value']);
        self::assertSame(1, $byId['history']['filled']);
        self::assertSame(12, $byId['history']['total']);
        self::assertSame(0, $byId['disease']['filled'], 'section vide mais présente');
        self::assertSame(5, $byId['disease']['total']);

        $scores = array_column($byId['scores']['rows'], 'value', 'field');
        self::assertSame('ASA III – maladie systémique sévère', $scores['asaScore'], 'clé traduite en libellé');
        self::assertNull($scores['nyhaClass']);

        $measures = array_column($byId['measures']['rows'], 'value', 'field');
        self::assertSame('60 kg', $measures['weight']);
        self::assertSame('23,4 kg/m²', $measures['bmi'], 'IMC calculé');
        self::assertSame('52,4 kg', $measures['predictedBodyWeight'], 'femme, 160 cm');
        self::assertSame('12/10/2026', $measures['surgeryDate'], 'date à la française');
        $computed = array_column($byId['measures']['rows'], 'computed', 'field');
        self::assertTrue($computed['tidalVolume']);
        self::assertFalse($computed['weight']);
        self::assertSame(6, $byId['measures']['total'], 'les valeurs calculées ne comptent pas');
    }

    public function testPresentSectionsWithoutMeasuresExplainsMissingComputations(): void
    {
        $patient = new Patient(9, null, 'Durand', 'Alice', null, null);
        $sections = PatientRecordService::presentSections($patient, $this->episodeOfCare());
        $byId = array_column($sections, null, 'id');
        $rows = array_column($byId['measures']['rows'], null, 'field');

        self::assertNull($rows['bmi']['value']);
        self::assertSame('Non calculable : poids manquant, taille manquante.', $rows['bmi']['help']);
        self::assertNull($rows['weight']['help']);
        self::assertSame(0, $byId['measures']['filled']);
    }

    private function patient(?string $ipp): Patient
    {
        return new Patient(9, $ipp, ' Durand ', 'Alice', new DateTimeImmutable('1990-06-15'), 'F');
    }

    private function episodeOfCare(
        string $episodeNumber = '2026-000009',
        ?DateTimeImmutable $dischargeDate = null,
        ?float $weight = null,
        ?float $height = null,
        ?string $precautions = null
    ): EpisodeOfCare {
        return new EpisodeOfCare(
            idEpisodeOfCare: 9,
            idPatient: 9,
            episodeNumber: $episodeNumber,
            admissionDate: new DateTimeImmutable('2026-10-13 09:30:00'),
            dischargeDate: $dischargeDate,
            bedNumber: '1',
            boxNumber: null,
            idService: 'REA',
            weight: $weight,
            height: $height,
            precautions: $precautions
        );
    }
}
