<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

class CarePlan
{
    public function __construct(
        private string $idCarePlan,
        private string $idEpisodeOfCare,
        private string $idPrescriber,
        private string $mode,
        private ?DateTimeImmutable $recordedAt,
        private ?string $idValidator = null,
        private ?DateTimeImmutable $validatedAt = null,
    ) {
    }

    public function getIdCarePlan(): string
    {
        return $this->idCarePlan;
    }

    public function getIdEpisodeOfCare(): string
    {
        return $this->idEpisodeOfCare;
    }

    public function getIdPrescriber(): string
    {
        return $this->idPrescriber;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getRecordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getIdValidator(): ?string
    {
        return $this->idValidator;
    }

    public function getValidatedAt(): ?DateTimeImmutable
    {
        return $this->validatedAt;
    }
}
