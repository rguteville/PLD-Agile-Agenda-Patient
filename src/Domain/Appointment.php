<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

class Appointment
{
    public function __construct(
        private string $idAppointment,
        private ?string $idServiceRequest  = null,
        private ?string $idMedicationRequest  = null,
        private ?DateTimeImmutable $plannedTime = null,
        private ?string $status  = null,
        private ?DateTimeImmutable $timeOfCompletion = null,
        private ?DateTimeImmutable $timeRecorded = null,
        private ?string $comment = null,
    ) {
    }

    public function getIdAppointment(): string
    {   
        return $this->idAppointment;
    }

    public function getIdServiceRequest(): ?string
    {
        return $this->idServiceRequest;
    }

    public function getIdMedicationRequest(): ?string
    {
        return $this->idMedicationRequest;
    }

    public function getPlannedTime(): ?DateTimeImmutable
    {
        return $this->plannedTime;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getTimeOfCompletion(): ?DateTimeImmutable
    {
        return $this->timeOfCompletion;
    }

    public function getTimeRecorded(): ?DateTimeImmutable
    {
        return $this->timeRecorded;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }





}