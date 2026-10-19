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

    public function getIdCarePlan(): string
    {
        return $this->idCarePlan;
    }

    public function getIdActivityDefinition(): string
    {
        return $this->idActivityDefinition;
    }

    public function getDose(): string
    {
        return $this->dose;
    }

    public function getRoute(): string
    {
        return $this->route;
    }

    public function getStartDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): ?DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getFrequencyHours(): ?int
    {
        return $this->frequencyHours;
    }

    public function getTimeConstraint(): ?string
    {
        return $this->timeConstraint;
    }

    public function getIdStoppedBy(): ?string
    {
        return $this->idStoppedBy;
    }

    public function getStoppedAt(): ?DateTimeImmutable
    {
        return $this->stoppedAt;
    }

}