<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AppointmentDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\Patient;
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

    public function testDeletedPatientIsHiddenButKeptInTheDatabase(): void
    {
        self::assertNull($this->service->delete(4));

        $ids = array_map(static fn (Patient $p): ?int => $p->getIdPatient(), $this->patients->findAll());
        self::assertNotContains(4, $ids, 'liste des patients');
        self::assertContains(1, $ids);
        self::assertSame([], $this->patients->search('Parker'), 'recherche de la liste');
        self::assertSame([], $this->patients->searchByWords(['parker'], 10), 'recherche de l\'admission');
        self::assertNull($this->patients->findByIpp('100000004'), 'recherche par IPP');
        self::assertNull($this->patients->findById(4));
        self::assertSame([], $this->patients->findByIdentity('Parker', 'Peter', new DateTimeImmutable('2001-01-09')));

        // Suppression logique : la ligne et son IPP restent (réservé), la date est renseignée.
        self::assertTrue($this->patients->existsByIpp('100000004'));
        self::assertSame(4, $this->patients->ippOwnerId('100000004'));
        $removedAt = $this->pdo->query("SELECT removedAt FROM Patient WHERE idPatient = '4'")->fetchColumn();
        self::assertNotNull($removedAt);
        self::assertNotEmpty((new EpisodeOfCareDao($this->pdo))->findByPatient(4), 'séjours conservés');
    }

    public function testIppOfADeletedPatientCannotBeReused(): void
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
}
