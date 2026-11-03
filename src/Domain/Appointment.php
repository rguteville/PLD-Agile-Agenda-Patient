<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Soin planifié (table Appointment) : occurrence datée d'une ligne de prescription (soin ou
 * médicament, exactement l'un des deux), avec son statut et, le cas échéant, l'heure de réalisation.
 */
class Appointment
{
    /** Statut d'un soin planifié pas encore réalisé (valeur de database/test.sql). */
    public const STATUS_PLANNED = 'PLANIFIE';
    /** Soin réalisé (AURA-26) et soin non effectué, avec son motif en note. */
    public const STATUS_DONE = 'REALISE';
    public const STATUS_NOT_DONE = 'NON_FAIT';

    public function __construct(
        private string $idAppointment,
        private ?string $idServiceRequest = null,
        private ?string $idMedicationRequest = null,
        private ?DateTimeImmutable $plannedTime = null,
        private ?string $status = null,
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
