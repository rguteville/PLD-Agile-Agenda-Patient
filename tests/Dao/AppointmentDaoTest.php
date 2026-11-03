<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\AppointmentDao;
use Aura\Domain\Appointment;
use DateTimeImmutable;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Soins planifiés (table Appointment) sur la base du CI : lecture par ligne, rattachement au séjour,
 * clôture conditionnelle, note et comptage par patient.
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

    // ---------------------------------------------------------------------------------------------
    // findByRequest
    // ---------------------------------------------------------------------------------------------

    public function testFindByRequestReturnsDemoMedicationCareInPlannedOrder(): void
    {
        $appointments = $this->dao->findByRequest('1', true);

        self::assertSame(['SOI01', 'SOI02', 'SOI03', 'SOI04'], self::ids($appointments));
        self::assertSame('1', $appointments[1]->getIdMedicationRequest());
        self::assertNull($appointments[1]->getIdServiceRequest());
        self::assertSame(Appointment::STATUS_DONE, $appointments[1]->getStatus());
        self::assertSame('2026-10-14 12:00', $appointments[1]->getPlannedTime()->format('Y-m-d H:i'));
        self::assertSame('2026-10-14 12:20', $appointments[1]->getTimeOfCompletion()->format('Y-m-d H:i'));
        self::assertSame('2026-10-14 12:25', $appointments[1]->getTimeRecorded()->format('Y-m-d H:i'));
        self::assertSame('Retard : patient au scanner', $appointments[1]->getComment());
        self::assertNull($appointments[2]->getTimeOfCompletion(), 'soin encore prévu : pas d\'heure de réalisation');
        self::assertNull($appointments[2]->getComment());
    }

    public function testFindByRequestOrdersByPlannedTimeNotByInsertionOrId(): void
    {
        // Identifiants et ordre d'insertion volontairement à rebours de l'heure prévue.
        $this->insertAppointment('ZZ_A', '1', null, '2030-01-01 12:00:00');
        $this->insertAppointment('ZZ_B', '1', null, '2030-01-01 08:00:00');
        $this->insertAppointment('ZZ_C', '1', null, '2030-01-01 10:00:00');

        self::assertSame(['ZZ_B', 'ZZ_C', 'ZZ_A'], self::ids($this->dao->findByRequest('1', false)));
    }

    public function testFindByRequestSeparatesCareLineAndMedicationLineWithTheSameId(): void
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

    public function testFindByRequestOnCareLine(): void
    {
        $appointments = $this->dao->findByRequest('4', false);

        self::assertSame(['SOI07', 'SOI08'], self::ids($appointments));
        self::assertSame(Appointment::STATUS_NOT_DONE, $appointments[0]->getStatus());
        self::assertSame('4', $appointments[0]->getIdServiceRequest());
        self::assertNull($appointments[0]->getIdMedicationRequest());
    }

    public function testFindByRequestOnUnknownLineReturnsEmptyArray(): void
    {
        self::assertSame([], $this->dao->findByRequest('ZZ_INCONNUE', false));
        self::assertSame([], $this->dao->findByRequest('ZZ_INCONNUE', true));
    }

    // ---------------------------------------------------------------------------------------------
    // findWithEpisode
    // ---------------------------------------------------------------------------------------------

    public function testFindWithEpisodeOnOngoingEpisodeAndActiveMedicationLine(): void
    {
        $found = $this->dao->findWithEpisode('SOI03');

        self::assertNotNull($found);
        self::assertSame('SOI03', $found['appointment']->getIdAppointment());
        self::assertSame(1, $found['idEpisodeOfCare'], 'ligne de médicament 1 → ordonnance 1 → séjour 1');
        self::assertTrue($found['ongoing'], 'séjour 1 sans date de sortie');
        self::assertNull($found['lineStoppedAt']);
    }

    public function testFindWithEpisodeFollowsACareLine(): void
    {
        $found = $this->dao->findWithEpisode('SOI07');

        self::assertNotNull($found);
        self::assertSame(3, $found['idEpisodeOfCare'], 'ligne de soin 4 → ordonnance 4 → séjour 3');
        self::assertTrue($found['ongoing']);
        self::assertNull($found['lineStoppedAt']);
    }

    public function testFindWithEpisodeOnClosedEpisodeAndStoppedLine(): void
    {
        // SOI11 : ligne de médicament 6 (arrêtée le 2026-09-26 15:00), ordonnance 6, séjour 5 clos.
        $found = $this->dao->findWithEpisode('SOI11');

        self::assertNotNull($found);
        self::assertSame(5, $found['idEpisodeOfCare']);
        self::assertFalse($found['ongoing'], 'séjour 5 clos le 2026-09-27');
        self::assertInstanceOf(DateTimeImmutable::class, $found['lineStoppedAt']);
        self::assertSame('2026-09-26 15:00:00', $found['lineStoppedAt']->format('Y-m-d H:i:s'));
    }

    public function testFindWithEpisodeOnStoppedLineOfOngoingEpisode(): void
    {
        // Ligne de médicament 4 (midazolam) arrêtée le 2026-10-13 08:00, séjour 3 toujours en cours.
        $this->insertAppointment('ZZ_MIDA', null, '4', '2030-01-01 08:00:00');

        $found = $this->dao->findWithEpisode('ZZ_MIDA');

        self::assertNotNull($found);
        self::assertSame(3, $found['idEpisodeOfCare']);
        self::assertTrue($found['ongoing']);
        self::assertSame('2026-10-13 08:00:00', $found['lineStoppedAt']->format('Y-m-d H:i:s'));
    }

    public function testFindWithEpisodeOnStoppedCareLine(): void
    {
        $this->pdo->exec(
            "UPDATE ServiceRequest SET idStoppedBy = 'PRA01', stoppedAt = '2030-02-03 04:05:06'
             WHERE idServiceRequest = '4'"
        );

        $found = $this->dao->findWithEpisode('SOI08');

        self::assertNotNull($found);
        self::assertSame('2030-02-03 04:05:06', $found['lineStoppedAt']->format('Y-m-d H:i:s'));
    }

    public function testFindWithEpisodeOnUnknownAppointmentReturnsNull(): void
    {
        self::assertNull($this->dao->findWithEpisode('ZZ_INCONNU'));
    }

    // ---------------------------------------------------------------------------------------------
    // close
    // ---------------------------------------------------------------------------------------------

    public function testClosingAPlannedCareWritesStatusTimesAndComment(): void
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

    public function testClosingAnAlreadyClosedCareReturnsFalseAndChangesNothing(): void
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

    public function testClosingADemoCareAlreadyDoneReturnsFalse(): void
    {
        self::assertFalse($this->dao->close(
            'SOI01',
            Appointment::STATUS_NOT_DONE,
            null,
            new DateTimeImmutable('2030-01-01 09:00:00'),
            'Ne doit pas passer'
        ));
        self::assertSame('REALISE', $this->row('SOI01')['status']);
        self::assertNull($this->row('SOI01')['comment']);
    }

    public function testClosingWithoutCommentKeepsTheExistingNote(): void
    {
        $this->insertAppointment('ZZ_NOTE', '1', null, '2030-01-01 08:00:00', comment: 'Note posée avant');

        self::assertTrue($this->dao->close(
            'ZZ_NOTE',
            Appointment::STATUS_NOT_DONE,
            null,
            new DateTimeImmutable('2030-01-01 08:30:00'),
            null
        ));

        $row = $this->row('ZZ_NOTE');
        self::assertSame('NON_FAIT', $row['status']);
        self::assertNull($row['timeOfCompletion'], 'soin non fait : pas d\'heure de réalisation');
        self::assertSame('Note posée avant', $row['comment'], 'COALESCE : la note existante est conservée');
    }

    public function testClosingAnUnknownCareReturnsFalse(): void
    {
        self::assertFalse($this->dao->close(
            'ZZ_INCONNU',
            Appointment::STATUS_DONE,
            new DateTimeImmutable('2030-01-01 08:00:00'),
            new DateTimeImmutable('2030-01-01 08:00:00'),
            null
        ));
    }

    // ---------------------------------------------------------------------------------------------
    // updateComment
    // ---------------------------------------------------------------------------------------------

    public function testUpdateCommentReplacesTheNoteWhateverTheStatus(): void
    {
        $this->dao->updateComment('SOI02', 'Nouvelle note');

        $row = $this->row('SOI02');
        self::assertSame('Nouvelle note', $row['comment']);
        self::assertSame('REALISE', $row['status'], 'la note ne touche pas au statut');
        self::assertSame('2026-10-14 12:20:00', $row['timeOfCompletion']);
    }

    public function testUpdateCommentWithNullClearsTheNote(): void
    {
        $this->dao->updateComment('SOI02', null);

        self::assertNull($this->row('SOI02')['comment']);
    }

    public function testUpdateCommentLongerThanTheColumnIsRefusedByTheDatabase(): void
    {
        // comment VARCHAR(100) en mode strict : la base refuse plutôt que de tronquer en silence.
        $this->expectException(PDOException::class);

        $this->dao->updateComment('SOI02', str_repeat('x', 101));
    }

    // ---------------------------------------------------------------------------------------------
    // countByPatientAndStatus
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, array{int, string, int}> */
    public static function demoCounts(): array
    {
        return [
            'patient 1 réalisés (médicament + soin)' => [1, 'REALISE', 3],
            'patient 1 prévus' => [1, 'PLANIFIE', 2],
            'patient 2 réalisés sur deux ordonnances' => [2, 'REALISE', 2],
            'patient 3 non faits' => [3, 'NON_FAIT', 1],
            'patient 3 réalisés' => [3, 'REALISE', 1],
            'patient 5 annulés (séjour clos)' => [5, 'ANNULE', 2],
            'patient 4 sans ordonnance' => [4, 'REALISE', 0],
            'patient inconnu' => [999999, 'REALISE', 0],
            'statut inconnu' => [1, 'ZZ_STATUT', 0],
        ];
    }

    #[DataProvider('demoCounts')]
    public function testCountByPatientAndStatusOnDemoData(int $idPatient, string $status, int $expected): void
    {
        self::assertSame($expected, $this->dao->countByPatientAndStatus($idPatient, $status));
    }

    public function testCountByPatientAndStatusCoversAllEpisodesOfThePatient(): void
    {
        // Ligne de médicament 7 : ordonnance 7 du séjour antérieur 7 du patient 1.
        $this->insertAppointment('ZZ_ANCIEN', null, '7', '2026-06-03 12:00:00', Appointment::STATUS_DONE);

        self::assertSame(4, $this->dao->countByPatientAndStatus(1, Appointment::STATUS_DONE));
        self::assertSame(0, $this->dao->countByPatientAndStatus(2, 'ZZ_STATUT'));
    }
}
