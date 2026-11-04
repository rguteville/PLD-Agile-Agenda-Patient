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
 * Bandeau identité + allergies en tête de chaque écran de séjour (RG2) et dossier du séjour, sans base
 * (DAO doublés).
 */
final class PatientRecordServiceTest extends TestCase
{
    private const TODAY = '2026-10-14';

    public function testAgeIsCountedInFullYears(): void
    {
        $today = new DateTimeImmutable(self::TODAY);

        self::assertSame(36, PatientRecordService::age(new DateTimeImmutable('1990-06-15'), $today));
        self::assertSame(35, PatientRecordService::age(new DateTimeImmutable('1990-12-31'), $today), 'pas encore');
    }

    public function testBannerWithoutAllergySaysNoKnownAllergy(): void
    {
        $banner = PatientRecordService::presentBanner(
            $this->patient(ipp: null),
            $this->episodeOfCare(),
            [],
            new DateTimeImmutable(self::TODAY)
        );

        self::assertSame('Durand', $banner['lastName']);
        self::assertSame('15/06/1990', $banner['birthDate']);
        self::assertSame([], $banner['allergies']);
        self::assertSame('none', $banner['allergiesStatus'], 'la vue affiche « Aucune allergie connue »');
    }

    public function testBannerListsThePatientAllergies(): void
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

    public function testRecordAssemblesBannerAllergiesAndAddableAllergens(): void
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
        self::assertCount(2, $record['banner']['allergies']);
        self::assertSame([1, 2], array_column($record['allergies'], 'idAllergy'), 'ids pour le bouton Retirer');
        self::assertSame(['ARACH' => 'Arachide'], $record['substances'], 'les allergènes déjà connus sont retirés');
    }

    /** Retours des médecins du 2026-11-03 : décès dans le bandeau (texte accordé, jamais la couleur seule). */
    public function testBannerShowsADeceasedPatient(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        $deceased = new Patient(5, null, 'Fletcher', 'Jessica', null, 'F', details: ['deceased' => '1']);

        $banner = PatientRecordService::presentBanner($deceased, $this->episodeOfCare(), [], $today);
        $alive = PatientRecordService::presentBanner($this->patient(ipp: null), $this->episodeOfCare(), [], $today);

        self::assertTrue($banner['deceased']);
        self::assertSame('Décédée', $banner['deceasedLabel']);
        self::assertFalse($alive['deceased'], 'deceased non renseigné : pas de mention');
        self::assertNull($alive['deceasedLabel']);
    }

    /** Ligne « Patient(e) décédé(e) » du dossier complet : réservée à la modification validée par un médecin. */
    public function testDeathIsNotEditableInline(): void
    {
        $patient = new Patient(5, null, 'Fletcher', 'Jessica', null, 'F', details: ['deceased' => '1']);

        $sections = PatientRecordService::presentSections($patient, $this->episodeOfCare());
        $admin = array_values(array_filter($sections, static fn (array $s): bool => $s['id'] === 'admin'))[0];
        $rows = array_column($admin['rows'], null, 'field');

        self::assertSame('Oui', $rows['deceased']['value']);
        self::assertTrue($rows['deceased']['validatedByDoctor']);
        self::assertFalse($rows['medicalHistory']['validatedByDoctor'] ?? false);
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
        ?string $precautions = null,
        ?string $idReferringPractitioner = null,
        ?string $referringPractitionerName = null
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
            precautions: $precautions,
            idReferringPractitioner: $idReferringPractitioner,
            referringPractitionerName: $referringPractitionerName
        );
    }
}
