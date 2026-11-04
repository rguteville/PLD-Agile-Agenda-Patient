<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contraintes essentielles de database/schema.sql, vérifiées par la base elle-même : chaque instruction
 * invalide est refusée avec le bon code d'erreur ET par la bonne contrainte (un refus pour une autre
 * raison ne compte pas). Deux instructions valides vérifient que les contraintes ne refusent pas trop.
 * Cas choisis parmi ceux de database/test_contraintes.sql (Didac Espin, branche tests/AURA-16.2).
 *
 * Codes MariaDB (SQLSTATE 23000) : 1062 doublon, 1048 colonne NULL, 1452 parent absent, 1451 parent
 * encore référencé, 4025 CHECK. Transaction annulée en tearDown ; ignoré sans DB_HOST/DB_NAME.
 */
final class SchemaConstraintsTest extends TestCase
{
    private const DUPLICATE = 1062;
    private const NOT_NULL = 1048;
    private const NO_PARENT = 1452;
    private const STILL_REFERENCED = 1451;
    private const CHECK = 4025;

    /** Patient et séjour « ZZ » réutilisés par plusieurs cas. */
    private const ZZ_PATIENT = "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP1', NULL, 'Zzcontrainte')";
    private const ZZ_EPISODE = "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService,
        episodeNumber, admissionDate) VALUES ('ZZE1', 'ZZP1', 'SRV01', 'ZZ-000001', '2030-01-01 08:00:00')";

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

    /**
     * Instructions invalides : [préparation, instruction refusée, code MariaDB, contrainte attendue
     * dans le message (insensible à la casse : MariaDB sous macOS met les noms de tables en minuscules)].
     *
     * @return array<string, array{list<string>, string, int, string}>
     */
    public static function invalidStatements(): array
    {
        return [
            'Patient : IPP en double (Didac)' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP1', 555555, 'Zzmartin'), ('ZZP2',
                    555555, 'Zzdupont')",
                self::DUPLICATE,
                "for key 'ipp'",
            ],
            'Patient : IPP sous la borne basse (Didac)' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP3', 10000, 'Zzjullien')",
                self::CHECK,
                'patient.ipp',
            ],
            'Patient : nom obligatoire' => [
                [],
                "INSERT INTO Patient (idPatient, lastName) VALUES ('ZZP5', NULL)",
                self::NOT_NULL,
                "'lastName'",
            ],
            'Séjour : numéro de séjour en double (Didac)' => [
                [],
                "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService, episodeNumber,
                    admissionDate)
                 VALUES ('ZZE1', '1', 'SRV01', 'ZZ-100000', '2026-10-10 08:15:00'),
                        ('ZZE2', '1', 'SRV01', 'ZZ-100000', '2026-10-11 08:15:00')",
                self::DUPLICATE,
                'episodeNumber',
            ],
            'Séjour : patient inexistant (Didac)' => [
                [],
                "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService, episodeNumber,
                    admissionDate)
                 VALUES ('ZZE3', 'ZZ_INCONNU', 'SRV01', 'ZZ-100000', '2026-10-10 08:15:00')",
                self::NO_PARENT,
                'fk_patient_episode',
            ],
            'Allergie : même substance deux fois pour un patient (Didac)' => [
                [],
                "INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance)
                 VALUES ('ZZA1', '1', 'ALG03'), ('ZZA2', '1', 'ALG03')",
                self::DUPLICATE,
                'uq_substance_patient',
            ],
            'Ordonnance : ancien mode « MEDICALE » (Didac, renommé MEDICAL)' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode) VALUES ('ZZC1', '1',
                    'PRA01', 'MEDICALE')",
                self::CHECK,
                'chk_careplan_mode',
            ],
            'Ordonnance : valideur sans date de validation (RG5)' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idValidator, validatedAt)
                 VALUES ('ZZC4', '1', 'PRA03', 'MEDICAL', 'PRA01', NULL)",
                self::CHECK,
                'chk_careplan_validation',
            ],
            'Ligne de soin : fin avant le début' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, endDate)
                 VALUES ('ZZSR2', '1', 'TYP02', '2030-01-02 08:00:00', '2030-01-01 08:00:00')",
                self::CHECK,
                'chk_servicerequest_dates',
            ],
            'Ligne de soin : arrêtée sans auteur (RG10)' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, stoppedAt)
                 VALUES ('ZZSR3', '1', 'TYP02', '2030-01-01 08:00:00', '2030-01-01 09:00:00')",
                self::CHECK,
                'chk_servicerequest_arret',
            ],
            'Soin planifié : rattaché aux deux lignes à la fois' => [
                [],
                "INSERT INTO Appointment (idAppointment, idServiceRequest, idMedicationRequest, plannedTime)
                 VALUES ('ZZAP2', '1', '1', '2030-01-01 08:00:00')",
                self::CHECK,
                'chk_appointment_ligne',
            ],
            'Soin planifié : deux fois la même heure sur une ligne de médicament' => [
                [],
                "INSERT INTO Appointment (idAppointment, idMedicationRequest, plannedTime) VALUES ('ZZAP4', '1',
                    '2026-10-14 06:00:00')",
                self::DUPLICATE,
                'uq_appointment_medication_time',
            ],
            'Équipe : rotation inconnue' => [
                [],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2030-01-01',
                    'SOIR', 'Zzéquipe')",
                self::CHECK,
                'chk_careteam_shift',
            ],
            'Équipe : même nom parmi les équipes actives de la rotation' => [
                [],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2026-10-14',
                    'MATIN', 'Équipe A')",
                self::DUPLICATE,
                'uq_careteam_name',
            ],
            'Suppression : patient qui a un séjour' => [
                [self::ZZ_PATIENT, self::ZZ_EPISODE],
                "DELETE FROM Patient WHERE idPatient = 'ZZP1'",
                self::STILL_REFERENCED,
                'fk_patient_episode',
            ],
        ];
    }

    /** @param list<string> $setup */
    #[DataProvider('invalidStatements')]
    public function testInvalidStatementIsRejectedByTheRightConstraint(
        array $setup,
        string $sql,
        int $driverCode,
        string $constraint
    ): void {
        foreach ($setup as $statement) {
            $this->pdo->exec($statement);
        }

        try {
            $this->pdo->exec($sql);
            self::fail("L'instruction devait être refusée par la contrainte « $constraint ».");
        } catch (PDOException $e) {
            self::assertSame('23000', $e->errorInfo[0] ?? null, 'SQLSTATE : violation de contrainte d\'intégrité');
            self::assertSame($driverCode, $e->errorInfo[1] ?? null, 'code MariaDB : ' . $e->getMessage());
            self::assertStringContainsStringIgnoringCase(
                $constraint,
                $e->getMessage(),
                'refusée, mais pas par la contrainte attendue'
            );
        }
    }

    /**
     * Instructions valides de contrôle : [préparation, instruction, nombre de lignes touchées].
     *
     * @return array<string, array{list<string>, string, int}>
     */
    public static function validStatements(): array
    {
        return [
            'plusieurs patients sans IPP (admission en urgence, AURA-05)' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP1', NULL, 'Zzun'), ('ZZP2', NULL,
                    'Zzdeux')",
                2,
            ],
            'nom d\'équipe réutilisé après retrait de l\'ancienne (suppression logique)' => [
                ["UPDATE CareTeam SET removedAt = '2026-10-14 09:00:00' WHERE idCareTeam = 1"],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2026-10-14',
                    'MATIN', 'Équipe A')",
                1,
            ],
        ];
    }

    /** @param list<string> $setup */
    #[DataProvider('validStatements')]
    public function testValidStatementIsAccepted(array $setup, string $sql, int $affectedRows): void
    {
        foreach ($setup as $statement) {
            $this->pdo->exec($statement);
        }

        self::assertSame($affectedRows, $this->pdo->exec($sql));
    }
}
