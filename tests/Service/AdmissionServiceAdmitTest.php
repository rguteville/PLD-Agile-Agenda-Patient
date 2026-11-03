<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;
use Aura\Service\AdmissionService;
use Aura\Service\EpisodeOfCareService;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** AURA-10.4 : admit() enregistre le patient et ouvre son séjour, sans base (DAO simulés). */
final class AdmissionServiceAdmitTest extends TestCase
{
    private const DATA = [
        'lastName' => ' Durand ',
        'firstName' => 'Alice',
        'ipp' => '123456',
        'birthDate' => '1990-06-15',
        'sex' => 'F',
    ];

    private PatientDao&MockObject $patients;
    private EpisodeOfCareService&MockObject $episodesOfCare;
    private AdmissionService $service;

    protected function setUp(): void
    {
        $this->patients = $this->createMock(PatientDao::class);
        $this->episodesOfCare = $this->createMock(EpisodeOfCareService::class);

        // La transaction exécute simplement le traitement : pas de base ici.
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('run')->willReturnCallback(fn(callable $treatment) => $treatment());

        $this->service = new AdmissionService(
            new DateTimeImmutable('2026-10-14'),
            $this->patients,
            $this->episodesOfCare,
            $transaction
        );
    }

    public function testNewPatientInsertedAndEpisodeOpened(): void
    {
        $this->patients->method('findByIpp')->with('123456')->willReturn(null);
        $this->patients->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Patient $p): bool {
                $this->assertNull($p->getIdPatient());
                $this->assertSame('Durand', $p->getLastName(), 'le nom est nettoyé (trim)');
                $this->assertSame('Alice', $p->getFirstName());
                $this->assertSame('123456', $p->getIpp());
                $this->assertSame('1990-06-15', $p->getBirthDate()?->format('Y-m-d'));
                $this->assertSame('F', $p->getSex());

                return true;
            }))
            ->willReturn(42);
        $episodeOfCare = $this->episodeOfCare(idPatient: 42);
        $this->episodesOfCare->expects($this->once())
            ->method('openEpisodeOfCare')
            ->with(42)
            ->willReturn($episodeOfCare);

        $this->assertSame($episodeOfCare, $this->service->admit(self::DATA));
    }

    /** AURA-10.1 : les mesures saisies sont enregistrées sur le séjour ouvert. */
    public function testMeasuresPassedToEpisode(): void
    {
        $this->patients->method('findByIpp')->willReturn(null);
        $this->patients->method('insert')->willReturn(42);
        $episodeOfCare = $this->episodeOfCare(idPatient: 42);
        $this->episodesOfCare->expects($this->once())
            ->method('openEpisodeOfCare')
            // idPatient, lit, box, poids (kg), taille (cm), précautions
            ->with(42, null, null, 68.5, 172.46, "Isolement contact\nRisque de chute")
            ->willReturn($episodeOfCare);

        $this->service->admit([
            'weight' => ' 68.5 ',
            'height' => '172.456',
            'precautions' => " Isolement contact\r\nRisque de chute ",
        ] + self::DATA);
    }

    public function testEmptyMeasuresBecomeNull(): void
    {
        $this->patients->method('findByIpp')->willReturn(null);
        $this->patients->method('insert')->willReturn(42);
        $this->episodesOfCare->expects($this->once())
            ->method('openEpisodeOfCare')
            ->with(42, null, null, null, null, null)
            ->willReturn($this->episodeOfCare(idPatient: 42));

        $this->service->admit(['weight' => '', 'height' => '  ', 'precautions' => ' '] + self::DATA);
    }

    public function testInvalidMeasureDoesNotTouchDatabase(): void
    {
        $this->patients->expects($this->never())->method('insert');
        $this->episodesOfCare->expects($this->never())->method('openEpisodeOfCare');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inférieur à 1000 kg');

        $this->service->admit(['weight' => '1200'] + self::DATA);
    }

    public function testLastAndFirstNameAloneSuffice(): void
    {
        $this->patients->expects($this->never())->method('findByIpp');
        $this->patients->expects($this->once())->method('nextIpp')->willReturn(100000042);
        $this->patients->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Patient $p): bool {
                $this->assertSame('100000042', $p->getIpp(), 'IPP attribué automatiquement');
                $this->assertNull($p->getBirthDate());
                $this->assertNull($p->getSex(), 'RG1 : le sexe n\'est pas obligatoire');

                return true;
            }))
            ->willReturn(7);
        $this->episodesOfCare->method('openEpisodeOfCare')->with(7)->willReturn($this->episodeOfCare(idPatient: 7));

        $episodeOfCare = $this->service->admit(['lastName' => 'Martin', 'firstName' => 'Paul']);

        $this->assertSame(7, $episodeOfCare->getIdPatient());
    }

    public function testPatientKnownByIppIsReused(): void
    {
        $existing = new Patient(3, '123456', 'Durand', 'Alice', new DateTimeImmutable('1990-06-15'), 'F');
        $this->patients->method('findByIpp')->with('123456')->willReturn($existing);
        $this->patients->expects($this->never())->method('insert');
        $episodeOfCare = $this->episodeOfCare(idPatient: 3);
        $this->episodesOfCare->expects($this->once())->method('openEpisodeOfCare')->with(3)->willReturn($episodeOfCare);

        $this->assertSame($episodeOfCare, $this->service->admit(self::DATA));
    }

    /** AURA-12.3 : l'IPP d'un patient supprimé reste réservé ; l'admission est refusée, rien n'est écrit. */
    public function testIppOfRemovedPatientIsRefused(): void
    {
        $this->patients->method('findByIpp')->with('123456')->willReturn(null);
        $this->patients->method('existsByIpp')->with('123456')->willReturn(true);
        $this->patients->expects($this->never())->method('insert');
        $this->episodesOfCare->expects($this->never())->method('openEpisodeOfCare');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AdmissionService::REMOVED_PATIENT_IPP_MESSAGE);

        $this->service->admit(self::DATA);
    }

    public function testInvalidDataDoesNotTouchDatabase(): void
    {
        $this->patients->expects($this->never())->method('insert');
        $this->episodesOfCare->expects($this->never())->method('openEpisodeOfCare');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('obligatoire');

        $this->service->admit(['lastName' => '', 'firstName' => 'Paul']);
    }

    public function testRejectedEpisodeFailsAdmission(): void
    {
        $this->patients->method('findByIpp')->willReturn(null);
        $this->patients->method('insert')->willReturn(42);
        $this->episodesOfCare->method('openEpisodeOfCare')
            ->willThrowException(new InvalidArgumentException('Le patient a déjà un séjour en cours (n° 2026-000001)'));

        // L'exception traverse la transaction : c'est elle qui annule l'insertion du patient.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('déjà un séjour en cours');

        $this->service->admit(self::DATA);
    }

    public function testWithoutDatabaseAccessAdmitIsImpossible(): void
    {
        $this->expectException(LogicException::class);

        (new AdmissionService())->admit(self::DATA);
    }

    private function episodeOfCare(int $idPatient): EpisodeOfCare
    {
        return new EpisodeOfCare(11, $idPatient, '2026-000011', new DateTimeImmutable('2026-10-14 10:00:00'));
    }
    /** Plage des IPP épuisée : message clair à l'utilisateur, jamais une erreur technique. */
    public function testExhaustedIppRangeGivesMessageAndInsertsNothing(): void
    {
        $this->patients->method('nextIpp')->willThrowException(new \LogicException('Plus aucun IPP'));
        $this->patients->expects($this->never())->method('insert');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('saisissez l\'IPP du patient');

        $this->service->admit(['lastName' => 'Martin', 'firstName' => 'Paul']);
    }
}
