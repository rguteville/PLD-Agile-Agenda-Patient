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
 * Retours du 2026-11-02 : modification d'une partie entière du dossier et fin de séjour, validées par un
 * médecin (authentification factice), sur la base du CI. Transaction annulée en fin de test.
 */
final class RecordSectionServiceTest extends TestCase
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
        $validators = $this->service->validators();
        self::assertNotEmpty($validators, 'test.sql fournit au moins un médecin habilité');
        $this->idDoctor = (string) array_key_first($validators);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testPartIsSavedAndEachFieldTracedUnderDoctorName(): void
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

    public function testRejectedFieldCancelsWholePart(): void
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

    public function testIdentityAndLocationUpdatedTogether(): void
    {
        $result = $this->service->updatePart(
            $this->idEpisodeOfCare,
            'summary',
            ['lastName' => 'Zzpartie', 'firstName' => 'Alicia', 'phone' => '06 11 22 33 44', 'bedNumber' => 'ZZL2'],
            ['lastName' => 'Zzpartie', 'firstName' => 'Alice', 'phone' => '', 'bedNumber' => 'ZZL1'],
            $this->idDoctor,
            'fake'
        );

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status'], json_encode($result['errors']));
        $patient = (new PatientDao($this->pdo))->findById($this->idPatient);
        self::assertSame('Alicia', $patient?->getFirstName());
        self::assertSame('0611223344', $patient?->getPhone(), 'téléphone enregistré sans espaces');
        self::assertSame('ZZL2', (new EpisodeOfCareDao($this->pdo))->findById($this->idEpisodeOfCare)?->getBedNumber());
        self::assertSame(['bedNumber', 'firstName', 'phoneNumber'], $this->fields($this->traces()));
    }

    public function testConflictWhenOriginalValueIsNoLongerInDatabase(): void
    {
        $result = $this->service->updatePart(
            $this->idEpisodeOfCare,
            'history',
            ['medicalHistory' => 'HTA, asthme'],
            ['medicalHistory' => 'Autre valeur affichée'],
            $this->idDoctor,
            'fake'
        );

        self::assertSame(RecordSectionService::STATUS_CONFLICT, $result['status']);
        self::assertSame('HTA', (new PatientDao($this->pdo))->findById($this->idPatient)?->getDetail('medicalHistory'));
    }

    public function testFakeAuthenticationRejectedWithoutDoctorOrPassword(): void
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

    public function testFieldOutsidePartIsIgnored(): void
    {
        $result = $this->service->updatePart(
            $this->idEpisodeOfCare,
            'history',
            ['lastName' => 'Pirate', 'idPatient' => '1'],
            ['lastName' => 'Zzpartie', 'idPatient' => (string) $this->idPatient],
            $this->idDoctor,
            'fake'
        );

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status'], 'rien à faire');
        self::assertSame('Zzpartie', (new PatientDao($this->pdo))->findById($this->idPatient)?->getLastName());
    }

    public function testEpisodeEndDatedAndTraced(): void
    {
        $result = $this->service->endStay($this->idEpisodeOfCare, '02/10/2026', '09:30', $this->idDoctor, 'fake');

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status'], json_encode($result['errors']));
        $episodeOfCare = (new EpisodeOfCareDao($this->pdo))->findById($this->idEpisodeOfCare);
        self::assertSame('2026-10-02 09:30', $episodeOfCare?->getDischargeDate()?->format('Y-m-d H:i'));
        self::assertSame(['dischargeDate'], $this->fields($this->traces()));

        $again = $this->service->endStay($this->idEpisodeOfCare, '', '', $this->idDoctor, 'fake');
        self::assertSame(RecordSectionService::STATUS_INVALID, $again['status'], 'séjour déjà terminé');
    }

    public function testEpisodeEndBeforeAdmissionRejected(): void
    {
        $result = $this->service->endStay($this->idEpisodeOfCare, '30/09/2026', '08:00', $this->idDoctor, 'fake');

        self::assertSame(RecordSectionService::STATUS_INVALID, $result['status']);
        self::assertArrayHasKey('dischargeDate', $result['errors']);
    }

    /** Retours du 2026-11-03 : un médecin de jour se désigne référent depuis la partie « Dossier ». */
    public function testReferentDesignatedFromSummaryPartAndTraced(): void
    {
        $result = $this->service->updatePart(
            $this->idEpisodeOfCare,
            RecordSectionService::PART_SUMMARY,
            ['idReferringPractitioner' => $this->idDoctor],
            ['idReferringPractitioner' => ''],
            $this->idDoctor,
            'fake'
        );

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status'], json_encode($result['errors']));
        $episodeOfCare = (new EpisodeOfCareDao($this->pdo))->findById($this->idEpisodeOfCare);
        self::assertSame($this->idDoctor, $episodeOfCare?->getIdReferringPractitioner());
        $traces = $this->traces();
        self::assertSame(['idReferringPractitioner'], $this->fields($traces));
        self::assertNull($traces[0]->getOldValue());
        self::assertSame($this->idDoctor, $traces[0]->getNewValue());
        self::assertStringContainsString('authentification factice', $traces[0]->getAuthor());
    }

    public function testReferentOutsideDoctorListIsRejectedAndNothingWritten(): void
    {
        // Praticien sans rôle de médecin (comme un interne) : présent en base, absent de la liste des médecins.
        $this->pdo->exec(
            "INSERT INTO Practitioner (idPractitioner, firstName, lastName) VALUES ('ZZINT1', 'Zzinterne', 'Zztest')"
        );

        $result = $this->service->updatePart(
            $this->idEpisodeOfCare,
            'admission',
            ['idReferringPractitioner' => 'ZZINT1'],
            ['idReferringPractitioner' => ''],
            $this->idDoctor,
            'fake'
        );

        self::assertSame(RecordSectionService::STATUS_INVALID, $result['status']);
        self::assertArrayHasKey('idReferringPractitioner', $result['errors']);
        self::assertNull(
            (new EpisodeOfCareDao($this->pdo))->findById($this->idEpisodeOfCare)?->getIdReferringPractitioner()
        );
        self::assertSame([], $this->traces());
    }

    public function testCurrentPartValuesInFormFormat(): void
    {
        $patient = (new PatientDao($this->pdo))->findById($this->idPatient);
        $episodeOfCare = (new EpisodeOfCareDao($this->pdo))->findById($this->idEpisodeOfCare);
        self::assertNotNull($patient);
        self::assertNotNull($episodeOfCare);

        $values = RecordSectionService::currentValues('summary', $patient, $episodeOfCare);

        self::assertSame('01/01/1970', $values['birthDate'], 'date en JJ/MM/AAAA');
        self::assertSame('ZZL1', $values['bedNumber']);
        self::assertSame('', $values['phone']);
    }

    /**
     * Retours des médecins du 2026-11-03 : le décès se déclare dans la partie « Administratif », validé par
     * un médecin, écrit dans Patient.deceased (BOOLEAN) et tracé ; l'édition en ligne le refuse.
     */
    public function testDeceasedDeclaredThroughAdminPartTracedAndRefusedInline(): void
    {
        self::assertArrayHasKey('deceased', RecordSectionService::partFields('admin'));
        $inline = (new RecordEditService(
            new PatientDao($this->pdo),
            new EpisodeOfCareDao($this->pdo),
            new AuditEventDao($this->pdo),
            new Transaction($this->pdo)
        ))->update('patient', $this->idPatient, 'deceased', '1', null, idEpisodeOfCare: $this->idEpisodeOfCare);
        self::assertSame(RecordEditService::STATUS_FORBIDDEN_FIELD, $inline['status'], 'jamais en ligne');

        $result = $this->service->updatePart(
            $this->idEpisodeOfCare,
            'admin',
            ['deceased' => '1'],
            ['deceased' => ''],
            $this->idDoctor,
            'fake'
        );

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status']);
        self::assertSame('1', (new PatientDao($this->pdo))->findById($this->idPatient)?->getDetail('deceased'));
        $traces = $this->traces();
        self::assertSame(['deceased', 'dischargeDate'], $this->fields($traces), 'le décès termine le séjour');
        $death = $this->trace('deceased');
        self::assertSame('1', $death->getNewValue());
        self::assertStringContainsString('authentification factice', $death->getAuthor());
        self::assertFalse(
            (new EpisodeOfCareDao($this->pdo))->findById($this->idEpisodeOfCare)?->isOngoing(),
            'séjour terminé'
        );
    }

    /**
     * Retours du 2026-11-04 : déclarer le décès met fin au séjour à l'heure du décès et annule les soins
     * prévus après ; ceux d'avant restent (à tracer s'ils ont été faits). Tout est tracé au nom du médecin.
     */
    public function testDeclaringDeathEndsTheStayAtTheTimeOfDeathAndCancelsLaterCares(): void
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
    public function testDeathWithInvalidTimeWritesNothing(): void
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
