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
     * d'une ligne de soin ou d'une ligne de médicament (ex. Appointment::STATUS_DONE : un soin
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


    /**
     * Soin planifié avec le séjour auquel il appartient (par sa ligne puis son ordonnance), pour
     * vérifier qu'une action vise bien un soin du séjour affiché ; null s'il n'existe pas.
     *
     * @return array{appointment: Appointment, idEpisodeOfCare: int, ongoing: bool,
     *               lineStoppedAt: ?DateTimeImmutable}|null
     */
    public function findWithEpisode(string $idAppointment): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.idAppointment, a.idServiceRequest, a.idMedicationRequest, a.plannedTime, a.status,
                    a.timeOfCompletion, a.timeRecorded, a.comment,
                    e.idEpisodeOfCare, e.dischargeDate, COALESCE(s.stoppedAt, m.stoppedAt) AS lineStoppedAt
             FROM Appointment a
             LEFT JOIN ServiceRequest s ON s.idServiceRequest = a.idServiceRequest
             LEFT JOIN MedicationRequest m ON m.idMedicationRequest = a.idMedicationRequest
             JOIN CarePlan c ON c.idCarePlan = COALESCE(s.idCarePlan, m.idCarePlan)
             JOIN EpisodeOfCare e ON e.idEpisodeOfCare = c.idEpisodeOfCare
             WHERE a.idAppointment = :id'
        );
        $stmt->execute(['id' => $idAppointment]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }

        return [
            'appointment' => $this->mapToEntity($r),
            'idEpisodeOfCare' => (int) $r['idEpisodeOfCare'],
            'ongoing' => $r['dischargeDate'] === null,
            'lineStoppedAt' => $r['lineStoppedAt'] !== null ? new DateTimeImmutable($r['lineStoppedAt']) : null,
        ];
    }

    /**
     * Clôt un soin encore prévu (REALISE ou NON_FAIT). Écriture conditionnelle : false si le soin
     * n'est plus PLANIFIE (validé entre-temps par quelqu'un d'autre), et rien n'est écrit.
     */
    public function close(
        string $idAppointment,
        string $status,
        ?DateTimeInterface $timeOfCompletion,
        DateTimeInterface $timeRecorded,
        ?string $comment
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE Appointment
             SET status = :status, timeOfCompletion = :completion, timeRecorded = :recorded,
                 comment = COALESCE(:comment, comment)
             WHERE idAppointment = :id AND status = :planned'
        );
        $stmt->execute([
            'status' => $status,
            'completion' => $timeOfCompletion?->format('Y-m-d H:i:s'),
            'recorded' => $timeRecorded->format('Y-m-d H:i:s'),
            'comment' => $comment,
            'id' => $idAppointment,
            'planned' => Appointment::STATUS_PLANNED,
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Remplace la note d'un soin (AURA-28), quel que soit son statut. */
    public function updateComment(string $idAppointment, ?string $comment): void
    {
        $stmt = $this->pdo->prepare('UPDATE Appointment SET comment = :comment WHERE idAppointment = :id');
        $stmt->execute(['comment' => $comment, 'id' => $idAppointment]);
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
