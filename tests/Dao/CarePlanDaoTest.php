<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\AppointmentDao;
use Aura\Dao\CarePlanDao;
use Aura\Domain\Appointment;
use Aura\Domain\CarePlan;
use Aura\Domain\MedicationRequest;
use Aura\Domain\ServiceRequest;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-24.4 : enregistrer une prescription crée ses soins planifiés (Appointment), ceux que
 * l'agenda du séjour affiche. Données de database/test.sql : séjour 1, praticien PRA01,
 * types de soin TYP01 / TYP03, médicament MED01.
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
 */
final class CarePlanDaoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testEnregistrerUnePrescriptionCreeSesSoinsPlanifies(): void
    {
        $care = new ServiceRequest(null, 'TYP03', new DateTimeImmutable('2026-11-02 06:00'));
        $medication = new MedicationRequest(
            null,
            'TYP01',
            'MED01',
            '1 g',
            'IV',
            new DateTimeImmutable('2026-11-02 08:00'),
            new DateTimeImmutable('2026-11-03 08:00'),
            12
        );
        $dao = new CarePlanDao($this->pdo);

        $idCarePlan = $dao->insert(new CarePlan(null, 1, 'PRA01', 'MEDICAL', [$care, $medication]));

        $careLine = $dao->findServiceByCarePlan($idCarePlan)[0];
        $medicationLine = $dao->findMedicationByCarePlan($idCarePlan)[0];
        $appointments = new AppointmentDao($this->pdo);

        $careAppointments = $appointments->findByRequest((string) $careLine->getIdServiceRequest(), false);
        self::assertCount(1, $careAppointments, 'soin unique : un seul soin planifié');
        self::assertEquals(new DateTimeImmutable('2026-11-02 06:00'), $careAppointments[0]->getPlannedTime());
        self::assertSame(Appointment::STATUS_PLANNED, $careAppointments[0]->getStatus());
        self::assertNull($careAppointments[0]->getIdMedicationRequest());

        $medicationAppointments = $appointments->findByRequest(
            (string) $medicationLine->getIdMedicationRequest(),
            true
        );
        self::assertSame(
            ['2026-11-02 08:00', '2026-11-02 20:00', '2026-11-03 08:00'],
            array_map(
                static fn (Appointment $a): string => $a->getPlannedTime()->format('Y-m-d H:i'),
                $medicationAppointments
            ),
            'toutes les 12 h, date d\'arrêt incluse'
        );
        self::assertNull($medicationAppointments[0]->getIdServiceRequest());
    }
}
