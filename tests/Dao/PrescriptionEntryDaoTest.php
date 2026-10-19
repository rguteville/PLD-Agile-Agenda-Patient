<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\PrescriptionEntryDao;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-20 : référentiels de la saisie et enregistrement d'une ordonnance avec ses lignes.
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
 */
final class PrescriptionEntryDaoTest extends TestCase
{
    private PDO $pdo;
    private PrescriptionEntryDao $dao;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->dao = new PrescriptionEntryDao($this->pdo);

        // Jeu de données propre au test (identifiants préfixés T_ ou CATZ pour ne rien croiser)
        $this->pdo->exec(
            "INSERT INTO Patient (idPatient, lastName, firstName, sex) VALUES ('T_P1', 'Testpresc', 'Ana', 'F')"
        );
        $this->pdo->exec(
            "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, episodeNumber, admissionDate, dischargeDate)
             VALUES ('T_S1', 'T_P1', 'T-000001', '2026-10-01 08:00:00', NULL),
                    ('T_S2', 'T_P1', 'T-000002', '2026-09-01 08:00:00', '2026-09-05 08:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO PractitionerRole (idPractitionerRole, description, canValidate) VALUES ('T_R1', 'Test', TRUE)"
        );
        $this->pdo->exec(
            "INSERT INTO Practitioner (idPractitioner, idPractitionerRole, firstName, lastName)
             VALUES ('T_PR1', 'T_R1', 'Zoé', 'Zztest')"
        );
        $this->pdo->exec(
            "INSERT INTO ActivityDefinition (idActivityDefinition, name, description) VALUES
             ('CATZ1', 'Soins test', 'CARE'),
             ('CATZ2', 'Médicaments test', 'MEDICATION'),
             ('T_A1', 'Soin test', 'Un soin')"
        );
        $this->pdo->exec("INSERT INTO Medication (idMedication, inn, form) VALUES ('T_M1', 'Aaa test', 'Comprimé')");
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testOngoingOrFinishedEpisode(): void
    {
        $this->assertSame(['id' => 'T_S1', 'ongoing' => true], $this->dao->findEpisode('T_S1'));
        $this->assertSame(['id' => 'T_S2', 'ongoing' => false], $this->dao->findEpisode('T_S2'));
        $this->assertNull($this->dao->findEpisode('T_UNKNOWN'));
    }

    public function testCategoriesAndCareTypesAreSeparatedInActivityDefinition(): void
    {
        $categories = $this->dao->findCategories();
        $this->assertContains(['id' => 'CATZ1', 'label' => 'Soins test', 'kind' => 'CARE'], $categories);
        $this->assertNotContains('T_A1', array_column($categories, 'id'), 'un type de soin n\'est pas une catégorie');
        $this->assertSame('MEDICATION', $this->dao->findCategory('CATZ2')['kind'] ?? null);

        $careTypes = array_column($this->dao->findCareTypes(), 'id');
        $this->assertContains('T_A1', $careTypes);
        $this->assertNotContains('CATZ1', $careTypes, 'une catégorie n\'est pas proposée comme soin');
        $this->assertNotContains(PrescriptionEntryDao::MEDICATION_ADMINISTRATION, $careTypes);
    }

    public function testMedicationsAndPrescribersReadFromTheDatabase(): void
    {
        $this->assertContains(
            ['id' => 'T_M1', 'inn' => 'Aaa test', 'form' => 'Comprimé'],
            $this->dao->findMedications()
        );
        $this->assertTrue($this->dao->medicationExists('T_M1'));
        $this->assertFalse($this->dao->medicationExists('T_UNKNOWN'));

        $prescriber = array_values(array_filter(
            $this->dao->findPrescribers(),
            fn(array $p): bool => $p['id'] === 'T_PR1'
        ))[0];
        $this->assertSame(
            ['id' => 'T_PR1', 'name' => 'Zoé Zztest', 'role' => 'Test', 'canValidate' => true],
            $prescriber
        );
    }

    public function testInsertCarePlanWritesThePlanAndItsLines(): void
    {
        $start = new DateTimeImmutable('2026-10-20 08:00:00');
        $carePlanId = $this->dao->insertCarePlan(
            [
                'episodeId' => 'T_S1', 'prescriberId' => 'T_PR1', 'mode' => 'URGENCE',
                'validatorId' => null, 'validatedAt' => null,
            ],
            [
                ['careTypeId' => 'T_A1', 'start' => $start, 'end' => null, 'frequencyHours' => 4,
                    'timeConstraint' => 'à jeun'],
            ],
            [
                ['careTypeId' => 'CATZ2', 'medicationId' => 'T_M1', 'dose' => '1 cp', 'route' => 'PO',
                    'start' => $start, 'end' => $start->modify('+2 days'), 'frequencyHours' => 8,
                    'timeConstraint' => 'Consignes IDE : au repas'],
            ]
        );

        $plan = $this->pdo->query(
            "SELECT idEpisodeOfCare, idPrescriber, mode, idValidator FROM CarePlan WHERE idCarePlan = '$carePlanId'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(
            ['idEpisodeOfCare' => 'T_S1', 'idPrescriber' => 'T_PR1', 'mode' => 'URGENCE', 'idValidator' => null],
            $plan
        );

        $careLines = $this->pdo->query(
            "SELECT idActivityDefinition, startDate, frequencyHours, timeConstraint
             FROM ServiceRequest WHERE idCarePlan = '$carePlanId'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $careLines);
        $this->assertSame('T_A1', $careLines[0]['idActivityDefinition']);
        $this->assertSame('2026-10-20 08:00:00', $careLines[0]['startDate']);
        $this->assertSame(4, (int) $careLines[0]['frequencyHours']);
        $this->assertSame('à jeun', $careLines[0]['timeConstraint']);

        $medicationLines = $this->pdo->query(
            "SELECT idActivityDefinition, idMedication, dose, route, endDate, frequencyHours, timeConstraint
             FROM MedicationRequest WHERE idCarePlan = '$carePlanId'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $medicationLines);
        $this->assertSame('CATZ2', $medicationLines[0]['idActivityDefinition']);
        $this->assertSame('T_M1', $medicationLines[0]['idMedication']);
        $this->assertSame('2026-10-22 08:00:00', $medicationLines[0]['endDate']);
        $this->assertSame(8, (int) $medicationLines[0]['frequencyHours']);
        $this->assertSame('Consignes IDE : au repas', $medicationLines[0]['timeConstraint']);
    }

    public function testPendingCarePlanIsListedThenValidatedOnce(): void
    {
        $start = new DateTimeImmutable('2026-10-20 08:00:00');
        $carePlanId = $this->dao->insertCarePlan(
            ['episodeId' => 'T_S1', 'prescriberId' => 'T_PR1', 'mode' => 'URGENCE',
                'validatorId' => null, 'validatedAt' => null],
            [['careTypeId' => 'T_A1', 'start' => $start, 'end' => null, 'frequencyHours' => null,
                'timeConstraint' => null]],
            [['careTypeId' => 'CATZ2', 'medicationId' => 'T_M1', 'dose' => '1 cp', 'route' => 'PO',
                'start' => $start, 'end' => null, 'frequencyHours' => null, 'timeConstraint' => null]]
        );

        $pending = $this->dao->findPendingCarePlans('T_S1');
        $this->assertCount(1, $pending);
        $this->assertSame($carePlanId, $pending[0]['id']);
        $this->assertSame('Zoé Zztest', $pending[0]['prescriber']);
        $this->assertSame(['[PO] Aaa test (Comprimé) 1 cp', 'Soin test'], $pending[0]['lines']);
        $this->assertFalse($this->dao->findCarePlan($carePlanId)['validated'] ?? true);

        $validatedAt = new DateTimeImmutable('2026-10-20 09:00:00');
        $this->assertTrue($this->dao->validateCarePlan($carePlanId, 'T_PR1', $validatedAt));
        $this->assertFalse($this->dao->validateCarePlan($carePlanId, 'T_PR1', $validatedAt), 'pas deux fois');

        $this->assertSame([], $this->dao->findPendingCarePlans('T_S1'));
        $this->assertTrue($this->dao->findCarePlan($carePlanId)['validated'] ?? false);
        $this->assertSame(
            ['idValidator' => 'T_PR1', 'validatedAt' => '2026-10-20 09:00:00'],
            $this->pdo->query("SELECT idValidator, validatedAt FROM CarePlan WHERE idCarePlan = '$carePlanId'")
                ->fetch(PDO::FETCH_ASSOC)
        );
        $this->assertNull($this->dao->findCarePlan('T_UNKNOWN'));
    }
}
