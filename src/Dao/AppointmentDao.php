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

    public function findByRequest(string $idRequest): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' 
                                    FROM Appointment WHERE idMedicationRequest = :idMed OR idServiceRequest = :idSer');
        $stmt->execute(['idMed' => $idRequest, 'idSer' => $idRequest]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
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
