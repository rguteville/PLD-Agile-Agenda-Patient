<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AppointmentDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\Transaction;
use Aura\Domain\Appointment;
use Aura\Domain\CarePlan;
use Aura\Domain\ServiceRequest;
use Aura\Service\AgendaGenerationService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-24.2 et 24.3 : la génération quotidienne de l'agenda est idempotente. Données de
 * database/test.sql au 14/10/2026 : séjours en cours 1, 2, 3, 6 et 8, lignes actives (dont le
 * paracétamol du séjour 1, qui a déjà des soins planifiés le 14 à 18 h et le 15 à 0 h), une ligne
 * arrêtée (midazolam, ligne de médicament 4) et des séjours clos.
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
 */
final class AgendaGenerationIntegrationTest extends TestCase
{
    private const NOW = '2026-10-14 09:00:00';

    private PDO $pdo;
    private AgendaGenerationService $service;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->service = new AgendaGenerationService(
            new CarePlanDao($this->pdo),
            new AppointmentDao($this->pdo),
            new Transaction($this->pdo)
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testSecondGenerationCreatesNothing(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $first = $this->service->generate($now);
        $second = $this->service->generate($now);

        self::assertGreaterThan(0, $first['created'], 'la première génération complète l\'agenda');
        self::assertSame(0, $second['created'], 'la seconde génération ne crée rien');
        self::assertSame(0, $second['episodes']);
        self::assertSame($first['examinedEpisodes'], $second['examinedEpisodes']);
        self::assertSame([], $this->duplicates(), 'aucun doublon ligne + heure prévue');
    }

    public function testNothingIsCreatedInThePastBeyondTheHorizonOrForAStoppedLine(): void
    {
        $before = array_keys($this->appointments());

        $this->service->generate(new DateTimeImmutable(self::NOW));

        $created = array_diff_key($this->appointments(), array_flip($before));
        self::assertNotEmpty($created);
        foreach ($created as $row) {
            self::assertGreaterThanOrEqual(self::NOW, $row['plannedTime'], 'RG9 : rien dans le passé');
            self::assertLessThanOrEqual('2026-10-21 23:59:59', $row['plannedTime'], 'horizon J + 7');
            self::assertSame(Appointment::STATUS_PLANNED, $row['status']);
            self::assertNull($row['timeOfCompletion']);
        }
        $lines = array_map(
            static fn (array $r): string => ($r['idServiceRequest'] !== null ? 'S' : 'M')
                . ($r['idServiceRequest'] ?? $r['idMedicationRequest']),
            $created
        );
        self::assertNotContains('M4', $lines, 'ligne arrêtée (midazolam) : aucun soin');
        self::assertNotContains('M7', $lines, 'séjour clos : aucun soin');
        self::assertNotContains('S1', $lines, 'soin unique déjà passé : pas recréé');
        self::assertContains('M2', $lines, 'noradrénaline sans date de fin : prolongée');
    }

    public function testGenerationExtendsAPrescriptionWithoutShiftingItsHours(): void
    {
        // Ligne quotidienne sans date de fin, enregistrée le 14 à 10 h : soins du 14 au 21 (horizon de 7 jours).
        $carePlans = new CarePlanDao($this->pdo);
        $idCarePlan = $carePlans->insert(new CarePlan(null, 1, 'PRA01', 'MEDICAL', [
            new ServiceRequest(null, 'TYP02', new DateTimeImmutable('2026-10-14 10:00'), frequencyHours: 24),
        ]));
        $idLine = (string) $carePlans->findServiceByCarePlan($idCarePlan)[0]->getId();
        self::assertCount(8, $this->plannedTimes('idServiceRequest', $idLine));

        // Génération du 16 à 9 h : horizon le 23 à minuit, deux soins de plus, aux mêmes heures (10 h).
        $this->service->generate(new DateTimeImmutable('2026-10-16 09:00'));
        $this->service->generate(new DateTimeImmutable('2026-10-16 09:00'));

        $times = $this->plannedTimes('idServiceRequest', $idLine);
        self::assertCount(10, $times);
        self::assertSame('2026-10-23 10:00:00', end($times));
        self::assertSame([], $this->duplicates());
    }

    /**
     * Soins par identifiant, toutes colonnes.
     *
     * @return array<string, array<string, ?string>>
     */
    private function appointments(): array
    {
        $rows = $this->pdo->query(
            'SELECT idAppointment, idServiceRequest, idMedicationRequest, plannedTime, status, timeOfCompletion,
                    timeRecorded, comment
             FROM Appointment'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_column($rows, null, 'idAppointment');
    }

    /** @return list<string> heures prévues d'une ligne, triées */
    private function plannedTimes(string $column, string $idLine): array
    {
        // Colonne fixée par le test (idServiceRequest ou idMedicationRequest).
        $stmt = $this->pdo->prepare("SELECT plannedTime FROM Appointment WHERE $column = :id ORDER BY plannedTime");
        $stmt->execute(['id' => $idLine]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<array<string, mixed>> couples ligne + heure présents plus d'une fois */
    private function duplicates(): array
    {
        return $this->pdo->query(
            'SELECT idServiceRequest, idMedicationRequest, plannedTime, COUNT(*) AS n
             FROM Appointment
             GROUP BY idServiceRequest, idMedicationRequest, plannedTime
             HAVING COUNT(*) > 1'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
