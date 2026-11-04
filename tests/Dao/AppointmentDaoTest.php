<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\AppointmentDao;
use Aura\Domain\Appointment;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Soins planifiés (table Appointment) sur la base du CI : lecture par ligne, rattachement au séjour,
 * clôture conditionnelle (un soin ne se valide qu'une fois).
 *
 * S'appuie sur database/test.sql (soins SOI01 à SOI14, lignes de soin 1 à 4, lignes de médicament
 * 1 à 8) ; les écritures portent sur des soins « ZZ… » insérés ici. Tout se passe dans une
 * transaction annulée en tearDown : la base n'est pas modifiée. Ignoré sans DB_HOST/DB_NAME.
 */
final class AppointmentDaoTest extends TestCase
{
    private PDO $pdo;
    private AppointmentDao $dao;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->dao = new AppointmentDao($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @param list<Appointment> $appointments */
    private static function ids(array $appointments): array
    {
        return array_map(static fn (Appointment $a): string => $a->getIdAppointment(), $appointments);
    }

    private function insertAppointment(
        string $id,
        ?string $idServiceRequest,
        ?string $idMedicationRequest,
        string $plannedTime,
        string $status = Appointment::STATUS_PLANNED,
        ?string $comment = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO Appointment
                (idAppointment, idServiceRequest, idMedicationRequest, plannedTime, status, comment)
             VALUES (:id, :sr, :mr, :planned, :status, :comment)'
        );
        $stmt->execute([
            'id' => $id,
            'sr' => $idServiceRequest,
            'mr' => $idMedicationRequest,
            'planned' => $plannedTime,
            'status' => $status,
            'comment' => $comment,
        ]);
    }

    /** Ligne brute, pour vérifier ce qui est réellement écrit en base. */
    private function row(string $idAppointment): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, timeOfCompletion, timeRecorded, comment FROM Appointment WHERE idAppointment = :id'
        );
        $stmt->execute(['id' => $idAppointment]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testCareLineAndMedicationLineWithTheSameIdAreKeptApart(): void
    {
        // La ligne de soin « 1 » n'a aucun soin dans les données de démo ; la ligne de médicament
        // « 1 » en a quatre (SOI01 à SOI04). Les identifiants coïncident, les lignes non.
        $this->insertAppointment('ZZ_SOIN', '1', null, '2030-01-01 08:00:00');

        self::assertSame(['ZZ_SOIN'], self::ids($this->dao->findByRequest('1', false)), 'soin : seulement ZZ_SOIN');
        self::assertSame(
            ['SOI01', 'SOI02', 'SOI03', 'SOI04'],
            self::ids($this->dao->findByRequest('1', true)),
            'médicament : le soin de la ligne de soin homonyme ne doit pas apparaître'
        );
    }

    public function testCareOfAStoppedLineKnowsItsStayAndTheStopTime(): void
    {
        // Ligne de médicament 4 (midazolam) arrêtée le 2026-10-13 08:00, séjour 3 toujours en cours.
        $this->insertAppointment('ZZ_MIDA', null, '4', '2030-01-01 08:00:00');

        $found = $this->dao->findWithEpisode('ZZ_MIDA');

        self::assertNotNull($found);
        self::assertSame(3, $found['idEpisodeOfCare']);
        self::assertTrue($found['ongoing']);
        self::assertSame('2026-10-13 08:00:00', $found['lineStoppedAt']->format('Y-m-d H:i:s'));
    }

    public function testClosingAPlannedCareWritesItsStatusTimesAndNote(): void
    {
        $this->insertAppointment('ZZ_CLOSE', '1', null, '2030-01-01 08:00:00');

        $closed = $this->dao->close(
            'ZZ_CLOSE',
            Appointment::STATUS_DONE,
            new DateTimeImmutable('2030-01-01 08:10:00'),
            new DateTimeImmutable('2030-01-01 08:15:00'),
            'Fait au lit'
        );

        self::assertTrue($closed);
        self::assertSame(
            [
                'status' => 'REALISE',
                'timeOfCompletion' => '2030-01-01 08:10:00',
                'timeRecorded' => '2030-01-01 08:15:00',
                'comment' => 'Fait au lit',
            ],
            $this->row('ZZ_CLOSE')
        );
    }

    public function testCareCannotBeClosedTwice(): void
    {
        $this->insertAppointment('ZZ_TWICE', '1', null, '2030-01-01 08:00:00');
        self::assertTrue($this->dao->close(
            'ZZ_TWICE',
            Appointment::STATUS_DONE,
            new DateTimeImmutable('2030-01-01 08:10:00'),
            new DateTimeImmutable('2030-01-01 08:15:00'),
            'Premier passage'
        ));

        // Deuxième validation concurrente : elle doit être refusée, sans rien réécrire.
        $second = $this->dao->close(
            'ZZ_TWICE',
            Appointment::STATUS_NOT_DONE,
            null,
            new DateTimeImmutable('2030-01-01 09:00:00'),
            'Second passage'
        );

        self::assertFalse($second, 'le soin n\'est plus PLANIFIE');
        self::assertSame(
            [
                'status' => 'REALISE',
                'timeOfCompletion' => '2030-01-01 08:10:00',
                'timeRecorded' => '2030-01-01 08:15:00',
                'comment' => 'Premier passage',
            ],
            $this->row('ZZ_TWICE'),
            'le premier enregistrement reste intact'
        );
    }
}
