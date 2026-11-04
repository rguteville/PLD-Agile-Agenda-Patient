<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Soin planifié (ressource HL7 Appointment, table Appointment) : occurrence datée d'une ligne de
 * prescription (soin ou médicament, exactement l'un des deux), avec son statut et, le cas échéant,
 * l'heure de réalisation. Statuts stockés en français : PLANIFIE, REALISE, NON_FAIT, et ANNULE
 * (ligne arrêtée ou décès, écrit par CarePlanDao et AppointmentDao, sans constante ici).
 *
 * Accesseurs get*() : un par colonne, sans logique ni validation.
 */
class Appointment
{
    /** Statut d'un soin planifié pas encore réalisé (valeur de database/test.sql). */
    public const STATUS_PLANNED = 'PLANIFIE';
    /** Soin réalisé (AURA-26) et soin non effectué, avec son motif en note. */
    public const STATUS_DONE = 'REALISE';
    public const STATUS_NOT_DONE = 'NON_FAIT';

    /**
     * @param string $idAppointment VARCHAR : numérique pour un soin créé par AURA, « SOI01 » dans la démo
     * @param ?DateTimeImmutable $timeOfCompletion heure de réalisation saisie (null si pas réalisé)
     * @param ?DateTimeImmutable $timeRecorded heure à laquelle la validation ou l'annulation a été saisie
     * @param ?string $comment note du soin (VARCHAR(100)) : motif d'un soin non fait ou annulé
     */
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
