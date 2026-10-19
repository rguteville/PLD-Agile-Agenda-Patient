<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\CarePlan;
use Aura\Domain\MedicationRequest;
use Aura\Domain\ServiceRequest;

use DateTimeImmutable;
use PDO;


class CarePlanDao
{
    private const COLUMNS = 'idCarePlan, idEpisodeOfCare, idPrescriber, mode, recordedAt, idValidator, validatedAt';
    private const SERVICE_COLUMNS = 'idServiceRequest, idCarePlan, idActivityDefinition, startDate, endDate, frequencyHours, timeConstraint, idStoppedBy, stoppedAt';
    private const MEDICATION_COLUMNS = 'idMedicationRequest, idCarePlan, idActivityDefinition, dose, route, startDate, endDate, frequencyHours, timeConstraint, idStoppedBy, stoppedAt';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return CarePlan[] */
    public function findByEpisodeOfCare(string $idEpisodeOfCare): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM CarePlan WHERE idEpisodeOfCare = :id');
        $stmt->execute(['id' => $idEpisodeOfCare]);

        return array_map(fn(array $r) => $this->mapToCarePlan($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findMedicationByCarePlan(string $idCarePlan): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::MEDICATION_COLUMNS . ' FROM MedicationRequest WHERE idCarePlan = :id');
        $stmt->execute(['id' => $idCarePlan]);

        return array_map(fn(array $r) => $this->mapToMedicationRequest($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

        public function findServiceByCarePlan(string $idCarePlan): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::SERVICE_COLUMNS . ' FROM ServiceRequest WHERE idCarePlan = :id');
        $stmt->execute(['id' => $idCarePlan]);

        return array_map(fn(array $r) => $this->mapToServiceRequest($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapToCarePlan(array $r): CarePlan
    {
        return new CarePlan(
            idCarePlan: $r['idCarePlan'],
            idEpisodeOfCare: $r['idEpisodeOfCare'],
            idPrescriber: $r['idPrescriber'],
            mode: $r['mode'],
            recordedAt: new DateTimeImmutable($r['recordedAt']),
            idValidator: $r['idValidator'] !== null ? $r['idValidator'] : null,
            validatedAt: $r['validatedAt'] !== null ? new DateTimeImmutable($r['validatedAt']) : null,
        );
    }

    private function mapToMedicationRequest(array $r): MedicationRequest
    {
        return new MedicationRequest(
            idMedicationRequest: $r['idMedicationRequest'],
            idCarePlan: $r['idCarePlan'],
            idActivityDefinition: $r['idActivityDefinition'],
            dose: $r['dose'],
            route: $r['route'],
            startDate: new DateTimeImmutable($r['startDate']),
            endDate: $r['endDate'] !== null ? new DateTimeImmutable($r['endDate']) : null,
            frequencyHours: $r['frequencyHours'] !== null ? $r['frequencyHours'] : null,
            timeConstraint: $r['timeConstraint'] !== null ? $r['timeConstraint'] : null,
            idStoppedBy: $r['idStoppedBy'] !== null ? $r['idStoppedBy'] : null,
            stoppedAt: $r['stoppedAt'] !== null ? new DateTimeImmutable($r['stoppedAt']) : null,
        );
    }

    private function mapToServiceRequest(array $r): ServiceRequest
    {
        return new ServiceRequest(
            idServiceRequest: $r['idServiceRequest'],
            idCarePlan: $r['idCarePlan'],
            idActivityDefinition: $r['idActivityDefinition'],
            startDate: new DateTimeImmutable($r['startDate']),
            endDate: $r['endDate'] !== null ? new DateTimeImmutable($r['endDate']) : null,
            frequencyHours: $r['frequencyHours'] !== null ? $r['frequencyHours'] : null,
            timeConstraint: $r['timeConstraint'] !== null ? $r['timeConstraint'] : null,
            idStoppedBy: $r['idStoppedBy'] !== null ? $r['idStoppedBy'] : null,
            stoppedAt: $r['stoppedAt'] !== null ? new DateTimeImmutable($r['stoppedAt']) : null,
        );
    }


}