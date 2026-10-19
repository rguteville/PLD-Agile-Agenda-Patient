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

}