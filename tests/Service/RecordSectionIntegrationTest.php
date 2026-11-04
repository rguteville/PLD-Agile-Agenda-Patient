<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AppointmentDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\Transaction;
use Aura\Domain\AuditEvent;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;
use Aura\Service\RecordEditService;
use Aura\Service\RecordSectionService;
use Aura\Service\EpisodeOfCareService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Modification d'une partie du dossier, fin de séjour et déclaration du décès, validées par un médecin
 * (authentification factice), sur la base du CI. Tout est tracé (RG10). Transaction annulée en tearDown.
 */
final class RecordSectionIntegrationTest extends TestCase
{
    private PDO $pdo;
    private RecordSectionService $service;
    private int $idPatient;
    private int $idEpisodeOfCare;
    private string $idDoctor;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }
        $this->pdo = Database::get();
        $this->pdo->beginTransaction();

        $patients = new PatientDao($this->pdo);
        $episodesOfCare = new EpisodeOfCareDao($this->pdo);
        $this->idPatient = $patients->insert(new Patient(
            null,
            '987654320',
            'Zzpartie',
            'Alice',
            new DateTimeImmutable('1970-01-01'),
            'F',
            details: ['medicalHistory' => 'HTA']
        ));
        $this->idEpisodeOfCare = (int) $episodesOfCare->insert(new EpisodeOfCare(
            null,
            $this->idPatient,
            '',
            new DateTimeImmutable('2026-10-01 08:00'),
            bedNumber: 'ZZL1',
            boxNumber: 'ZZB1'
        ))->getIdEpisodeOfCare();

        $references = new ReferenceDataDao($this->pdo);
        $this->service = new RecordSectionService(
            $patients,
            $episodesOfCare,
            new EpisodeOfCareService($episodesOfCare),
            new RecordEditService(
                $patients,
                $episodesOfCare,
                new AuditEventDao($this->pdo),
                new Transaction($this->pdo),
                $references
            ),
            new AuditEventDao($this->pdo),
            new Transaction($this->pdo),
            $references,
            appointmentDao: new AppointmentDao($this->pdo)
        );
        // Médecin réanimateur des données de démo (droit de validation).
        $this->idDoctor = 'PRA01';
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testPartIsSavedAndEachChangedFieldTracedUnderTheDoctorName(): void
    {
        $result = $this->service->updatePart(
            $this->idEpisodeOfCare,
            'history',
            ['medicalHistory' => 'HTA, diabète', 'lifestyle' => 'Autonome', 'comorbidities' => ''],
            ['medicalHistory' => 'HTA', 'lifestyle' => '', 'comorbidities' => ''],
            $this->idDoctor,
            'fake'
        );

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status']);
        $patient = (new PatientDao($this->pdo))->findById($this->idPatient);
        self::assertSame('HTA, diabète', $patient?->getDetail('medicalHistory'));
        self::assertSame('Autonome', $patient?->getDetail('lifestyle'));
        $traces = $this->traces();
        self::assertSame(['lifestyle', 'medicalHistory'], $this->fields($traces), 'un champ inchangé n\'est pas tracé');
        self::assertStringContainsString('authentification factice', $traces[0]->getAuthor());
    }

    public function testOneRejectedFieldCancelsTheWholePart(): void
    {
        $result = $this->service->updatePart(
            $this->idEpisodeOfCare,
            'summary',
            ['firstName' => 'Alicia', 'email' => 'pas-un-mail'],
            ['firstName' => 'Alice', 'email' => ''],
            $this->idDoctor,
            'fake'
        );

        self::assertSame(RecordSectionService::STATUS_INVALID, $result['status']);
        self::assertArrayHasKey('email', $result['errors']);
        $firstName = (new PatientDao($this->pdo))->findById($this->idPatient)?->getFirstName();
        self::assertSame('Alice', $firstName, 'rien d\'écrit');
        self::assertSame([], $this->traces());
    }

    public function testPartNeedsTheFactitiousAuthenticationOfADoctor(): void
    {
        foreach ([['', 'x'], [$this->idDoctor, '  '], ['PRA-INCONNU', 'x']] as [$doctor, $password]) {
            $result = $this->service->updatePart(
                $this->idEpisodeOfCare,
                'history',
                ['lifestyle' => 'x'],
                ['lifestyle' => ''],
                $doctor,
                $password
            );
            self::assertSame(RecordSectionService::STATUS_AUTH, $result['status']);
        }
        self::assertSame([], $this->traces());
    }

    public function testStayEndIsDatedTracedAndNotRepeated(): void
    {
        $result = $this->service->endStay($this->idEpisodeOfCare, '02/10/2026', '09:30', $this->idDoctor, 'fake');

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status'], json_encode($result['errors']));
        $episodeOfCare = (new EpisodeOfCareDao($this->pdo))->findById($this->idEpisodeOfCare);
        self::assertSame('2026-10-02 09:30', $episodeOfCare?->getDischargeDate()?->format('Y-m-d H:i'));
        self::assertSame(['dischargeDate'], $this->fields($this->traces()));

        $again = $this->service->endStay($this->idEpisodeOfCare, '', '', $this->idDoctor, 'fake');
        self::assertSame(RecordSectionService::STATUS_INVALID, $again['status'], 'séjour déjà terminé');
    }

    /**
     * Retours du 2026-11-04 : déclarer le décès met fin au séjour à l'heure du décès et annule les soins
     * prévus après ; ceux d'avant restent (à tracer s'ils ont été faits). Tout est tracé au nom du médecin.
     */
    public function testDeathEndsTheStayAtTheTimeOfDeathAndCancelsLaterCare(): void
    {
        $this->plannedCares(['ZZ_AVANT' => '2026-10-02 09:00:00', 'ZZ_APRES' => '2026-10-02 12:00:00']);

        $result = $this->service->declareDeath($this->idEpisodeOfCare, '02/10/2026', '10:00', '', $this->idDoctor, 'x');

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status'], json_encode($result['errors']));
        self::assertSame('1', (new PatientDao($this->pdo))->findById($this->idPatient)?->getDetail('deceased'));
        $episode = (new EpisodeOfCareDao($this->pdo))->findById($this->idEpisodeOfCare);
        self::assertSame('2026-10-02 10:00', $episode?->getDischargeDate()?->format('Y-m-d H:i'), 'sortie = décès');
        self::assertSame(
            ['ZZ_APRES' => 'ANNULE|Séjour terminé : décès', 'ZZ_AVANT' => 'PLANIFIE|'],
            $this->pdo->query(
                "SELECT idAppointment, CONCAT(status, '|', COALESCE(comment, '')) FROM Appointment
                 WHERE idAppointment LIKE 'ZZ\\_%' ORDER BY idAppointment"
            )->fetchAll(PDO::FETCH_KEY_PAIR)
        );
        $end = $this->trace('dischargeDate');
        self::assertSame('02/10/2026 10:00', $end->getNewValue());
        self::assertSame('fin du séjour : décès déclaré, 1 soin prévu annulé', $end->getDetail());
        self::assertStringContainsString('authentification factice', $end->getAuthor());
    }

    /** Heure du décès dans le futur ou invalide : rien n'est écrit (ni décès, ni fin de séjour). */
    public function testDeathWithAFutureOrImpossibleDateWritesNothing(): void
    {
        $future = $this->service->declareDeath(
            $this->idEpisodeOfCare,
            (new DateTimeImmutable('+2 days'))->format('d/m/Y'),
            '10:00',
            '',
            $this->idDoctor,
            'x'
        );
        $garbled = $this->service
            ->declareDeath($this->idEpisodeOfCare, '31/02/2026', '10:00', '', $this->idDoctor, 'x');

        foreach (['futur' => $future, 'date impossible' => $garbled] as $case => $result) {
            self::assertSame(RecordSectionService::STATUS_INVALID, $result['status'], $case);
            self::assertArrayHasKey('dischargeDate', $result['errors'], $case);
        }
        self::assertNull((new PatientDao($this->pdo))->findById($this->idPatient)?->getDetail('deceased'));
        self::assertTrue((new EpisodeOfCareDao($this->pdo))->findById($this->idEpisodeOfCare)?->isOngoing());
        self::assertSame([], $this->traces());
    }

    /**
     * Relecture adversariale du 2026-11-04 : le décès déclaré depuis le dossier d'un ANCIEN séjour (clos) termine
     * quand même le séjour en cours du patient.
     */
    public function testDeathDeclaredFromAnOldStayEndsTheOngoingStay(): void
    {
        $episodes = new EpisodeOfCareDao($this->pdo);
        $old = (int) $episodes->insert(new EpisodeOfCare(
            null,
            $this->idPatient,
            '',
            new DateTimeImmutable('2026-06-01 08:00')
        ))->getIdEpisodeOfCare();
        $episodes->updateDischarge($old, new DateTimeImmutable('2026-06-10 10:00'));

        $result = $this->service->declareDeath($old, '02/10/2026', '10:00', '', $this->idDoctor, 'x');

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status'], json_encode($result['errors']));
        self::assertFalse($episodes->findById($this->idEpisodeOfCare)?->isOngoing(), 'séjour en cours terminé');
        self::assertSame('2026-06-10 10:00', $episodes->findById($old)?->getDischargeDate()?->format('Y-m-d H:i'));
    }

    /** Relecture adversariale du 2026-11-04 : heure invalide (« 12:75 ») refusée, pas corrigée en silence. */
    public function testInvalidDischargeTimeIsRefusedNotCorrected(): void
    {
        $result = $this->service->endStay($this->idEpisodeOfCare, '02/10/2026', '12:75', $this->idDoctor, 'x');

        self::assertSame(RecordSectionService::STATUS_INVALID, $result['status']);
        self::assertTrue((new EpisodeOfCareDao($this->pdo))->findById($this->idEpisodeOfCare)?->isOngoing());
    }

    /** @param array<string, string> $cares idAppointment => heure prévue, sur une ligne de soin du séjour */
    private function plannedCares(array $cares): void
    {
        $this->pdo->prepare(
            "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idValidator, validatedAt)
             VALUES ('ZZCP1', ?, 'PRA01', 'MEDICAL', 'PRA01', '2026-10-01 09:00:00')"
        )->execute([(string) $this->idEpisodeOfCare]);
        $this->pdo->exec(
            "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, frequencyHours)
             VALUES ('ZZSR1', 'ZZCP1', 'TYP04', '2026-10-01 09:00:00', 3)"
        );
        $insert = $this->pdo->prepare(
            "INSERT INTO Appointment (idAppointment, idServiceRequest, plannedTime, status)
             VALUES (?, 'ZZSR1', ?, 'PLANIFIE')"
        );
        foreach ($cares as $id => $at) {
            $insert->execute([$id, $at]);
        }
    }

    private function trace(string $field): AuditEvent
    {
        foreach ($this->traces() as $event) {
            if ($event->getField() === $field) {
                return $event;
            }
        }
        self::fail('Aucune trace sur ' . $field . '.');
    }

    /** @return list<AuditEvent> */
    private function traces(): array
    {
        return array_values(
            (new AuditEventDao($this->pdo))->findForRecord($this->idPatient, $this->idEpisodeOfCare, 50)
        );
    }

    /**
     * @param list<AuditEvent> $traces
     * @return list<string>
     */
    private function fields(array $traces): array
    {
        $fields = array_map(static fn (AuditEvent $e): string => $e->getField(), $traces);
        sort($fields);

        return $fields;
    }
}
