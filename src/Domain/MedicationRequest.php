<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

class MedicationRequest
{
    public function __construct(
        private string $idMedicationRequest ,
        private string $idCarePlan,
        private string $idActivityDefinition,
        private string $dose,
        private string $route,
        private DateTimeImmutable $startDate,
        private ?DateTimeImmutable $endDate = null,
        private ?int $frequencyHours = null,
        private ?string $timeConstraint = null,
        private ?string $idStoppedBy = null,
        private ?DateTimeImmutable $stoppedAt = null,
    ) {
    }

    public function getIdMedicationRequest(): string
    {   
        return $this->idMedicationRequest;
    }

}