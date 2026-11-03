<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
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
            $references
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
        self::assertSame(['deceased'], $this->fields($traces));
        self::assertSame('1', $traces[0]->getNewValue());
        self::assertStringContainsString('authentification factice', $traces[0]->getAuthor());
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
