<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AppointmentDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\CarePlan;
use Aura\Domain\EpisodeOfCare;
use Aura\Domain\Patient;
use Aura\Domain\ServiceRequest;
use Aura\Service\AdmissionService;
use Aura\Service\EpisodeOfCareService;
use Aura\Service\PatientDeletionService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-12.3 : suppression logique d'un patient sur la base réelle. Données de database/test.sql :
 * patient 4 (Peter Parker, IPP 100000004) n'a qu'un séjour clos sans soin réalisé ; patient 1 a un
 * séjour en cours.
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
 */
final class PatientDeletionIntegrationTest extends TestCase
{
    private PDO $pdo;
    private PatientDao $patients;
    private PatientDeletionService $service;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->patients = new PatientDao($this->pdo);
        $this->service = new PatientDeletionService(
            $this->patients,
            new EpisodeOfCareDao($this->pdo),
            new AppointmentDao($this->pdo),
            new AuditEventDao($this->pdo),
            new Transaction($this->pdo)
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testDeletedPatientDisappearsFromListAndSearchesButStaysInDatabase(): void
    {
        self::assertNull($this->service->delete(4));

        $ids = array_map(static fn (Patient $p): ?int => $p->getIdPatient(), $this->patients->findAll());
        self::assertNotContains(4, $ids, 'liste des patients');
        self::assertContains(1, $ids);
        self::assertSame([], $this->patients->search('Parker'), 'recherche de la liste');
        self::assertSame([], $this->patients->searchByWords(['parker'], 10), 'recherche de l\'admission');
        self::assertNull($this->patients->findByIpp('100000004'), 'recherche par IPP');
        self::assertNull($this->patients->findById(4));

        // Suppression logique : la ligne et son IPP restent (réservé), la date est renseignée.
        self::assertTrue($this->patients->existsByIpp('100000004'));
        self::assertSame(4, $this->patients->ippOwnerId('100000004'));
        $removedAt = $this->pdo->query("SELECT removedAt FROM Patient WHERE idPatient = '4'")->fetchColumn();
        self::assertNotNull($removedAt);
        self::assertNotEmpty((new EpisodeOfCareDao($this->pdo))->findByPatient(4), 'séjours conservés');
    }

    public function testDeletionIsTraced(): void
    {
        $this->service->delete(4);

        $stmt = $this->pdo->query(
            "SELECT field, oldValue, newValue, author, detail FROM AuditEvent
             WHERE entity = 'Patient' AND entityId = '4' ORDER BY idAuditEvent DESC LIMIT 1"
        );
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($event);
        self::assertSame('removedAt', $event['field']);
        self::assertNull($event['oldValue']);
        self::assertSame(PatientDeletionService::AUDIT_VALUE, $event['newValue']);
        self::assertStringContainsString('PARKER Peter', $event['detail']);
    }

    public function testOngoingEpisodeRefused(): void
    {
        self::assertSame(PatientDeletionService::REFUSED_ONGOING_EPISODE, $this->service->delete(1));
        self::assertNotNull($this->patients->findById(1), 'rien n\'est écrit');
    }

    public function testCompletedCareInClosedEpisodeRefusedButPlannedCareAllowed(): void
    {
        $idPatient = $this->patientWithClosedEpisodeAndCare();
        $page = $this->service->prepare($idPatient);
        self::assertNotNull($page);
        self::assertNull($page['refusal'], 'soin seulement planifié : suppression possible');

        $this->pdo->prepare(
            "UPDATE Appointment SET status = 'REALISE', timeOfCompletion = '2026-10-02 06:05:00'
             WHERE idServiceRequest IN (SELECT idServiceRequest FROM ServiceRequest WHERE idCarePlan IN
                 (SELECT idCarePlan FROM CarePlan WHERE idEpisodeOfCare IN
                     (SELECT idEpisodeOfCare FROM EpisodeOfCare WHERE idPatient = :idPatient)))"
        )->execute(['idPatient' => (string) $idPatient]);

        self::assertSame(PatientDeletionService::REFUSED_COMPLETED_CARE, $this->service->delete($idPatient));
        self::assertNotNull($this->patients->findById($idPatient), 'rien n\'est écrit');
    }

    public function testSecondDeletionRejected(): void
    {
        $this->service->delete(4);

        $this->expectException(InvalidArgumentException::class);
        $this->service->delete(4);
    }

    public function testAdmissionWithIppOfDeletedPatientRefused(): void
    {
        $this->service->delete(4);
        $admission = new AdmissionService(
            null,
            $this->patients,
            new EpisodeOfCareService(new EpisodeOfCareDao($this->pdo)),
            new Transaction($this->pdo)
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AdmissionService::REMOVED_PATIENT_IPP_MESSAGE);
        $admission->admit(['lastName' => 'Zzsupprime', 'firstName' => 'Alice', 'ipp' => '100000004']);
    }

    /** Patient de test avec un séjour clos et un soin unique planifié (statut PLANIFIE). */
    private function patientWithClosedEpisodeAndCare(): int
    {
        $idPatient = $this->patients->insert(
            new Patient(null, null, 'Zzsuppressionsoin', 'Alice', new DateTimeImmutable('1960-01-01'), 'F')
        );
        $episode = (new EpisodeOfCareDao($this->pdo))->insert(new EpisodeOfCare(
            null,
            $idPatient,
            '',
            new DateTimeImmutable('2026-10-01 08:00'),
            new DateTimeImmutable('2026-10-05 08:00')
        ));
        (new CarePlanDao($this->pdo))->insert(new CarePlan(
            null,
            (int) $episode->getIdEpisodeOfCare(),
            'PRA01',
            'MEDICAL',
            [new ServiceRequest(null, 'TYP03', new DateTimeImmutable('2026-10-02 06:00'))]
        ));

        return $idPatient;
    }
}
