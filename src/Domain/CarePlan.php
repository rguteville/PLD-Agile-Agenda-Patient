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

}