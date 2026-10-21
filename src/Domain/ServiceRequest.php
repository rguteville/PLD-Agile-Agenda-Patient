<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

class ServiceRequest
{
    public function __construct(
        private string $idServiceRequest,
        private string $idCarePlan,
        private string $idActivityDefinition,
        private DateTimeImmutable $startDate,
        private ?DateTimeImmutable $endDate = null,
        private ?int $frequencyHours = null,
        private ?string $timeConstraint = null,
        private ?string $idStoppedBy = null,
        private ?DateTimeImmutable $stoppedAt = null,
    ) {
    }

    public function getIdServiceRequest(): string
    {
        return $this->idServiceRequest;
    }

    public function getIdCarePlan(): string
    {
        return $this->idCarePlan;
    }

    public function getIdActivityDefinition(): string
    {
        return $this->idActivityDefinition;
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
