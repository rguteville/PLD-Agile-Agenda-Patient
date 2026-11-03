<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Appointment;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;

/**
 * Accès à la table Appointment. Les colonnes suivent database/schema.sql (camelCase anglais,
 * cf. README « Convention de nommage ») ; les entités du domaine restent en français.
 */
class AppointmentDao
{
    private const COLUMNS = 'idAppointment, idServiceRequest, 
        idMedicationRequest, plannedTime, status, timeOfCompletion, timeRecorded, comment';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Soins planifiés d'une ligne, par ordre d'heure prévue. La colonne dépend de la nature de la
     * ligne : ServiceRequest et MedicationRequest ont chacun leurs identifiants, un soin « 1 » et un
     * médicament « 1 » coexistent.
     */
    public function findByRequest(string $idRequest, bool $isMedication): array
    {
        // Nom fixé ici, jamais saisi : le concaténer ne permet aucune injection.
        $column = $isMedication ? 'idMedicationRequest' : 'idServiceRequest';
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM Appointment WHERE $column = :id ORDER BY plannedTime"
        );
        $stmt->execute(['id' => $idRequest]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * AURA-12.3 : nombre de soins d'un statut donné dans tous les séjours d'un patient, qu'ils viennent
     * d'une ligne de soin ou d'une ligne de médicament (ex. Appointment::STATUS_COMPLETED : un soin
     * réalisé interdit la suppression du patient, RG10).
     */
    public function countByPatientAndStatus(int $idPatient, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM Appointment a
             LEFT JOIN ServiceRequest sr ON sr.idServiceRequest = a.idServiceRequest
             LEFT JOIN MedicationRequest mr ON mr.idMedicationRequest = a.idMedicationRequest
             JOIN CarePlan c ON c.idCarePlan = COALESCE(sr.idCarePlan, mr.idCarePlan)
             JOIN EpisodeOfCare e ON e.idEpisodeOfCare = c.idEpisodeOfCare
             WHERE e.idPatient = :idPatient AND a.status = :status'
        );
        $stmt->execute(['idPatient' => (string) $idPatient, 'status' => $status]);

        return (int) $stmt->fetchColumn();
    }


    private function mapToEntity(array $r): Appointment
    {
        return new Appointment(
            idAppointment: $r['idAppointment'],
            idServiceRequest: $r['idServiceRequest'] !== null ? (string) $r['idServiceRequest'] : null,
            idMedicationRequest: $r['idMedicationRequest'] !== null ? (string) $r['idMedicationRequest'] : null,
            plannedTime: $r['plannedTime'] !== null ? new DateTimeImmutable($r['plannedTime']) : null,
            status: $r['status'] !== null ? (string) $r['status'] : null,
            timeOfCompletion: $r['timeOfCompletion'] !== null ? new DateTimeImmutable($r['timeOfCompletion']) : null,
            timeRecorded: $r['timeRecorded'] !== null ? new DateTimeImmutable($r['timeRecorded']) : null,
            comment: $r['comment'] !== null ? (string) $r['comment'] : null,
        );
    }
}
