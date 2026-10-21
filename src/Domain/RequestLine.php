<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Ligne d'une prescription (CarePlan) : un soin (ServiceRequest) ou un médicament
 * (MedicationRequest), avec son propre rythme (RG3). Unique si frequencyHours est null (un seul
 * soin à startDate), récurrente sinon (un soin toutes les frequencyHours heures, jusqu'à endDate).
 *
 * Abstraite : une ligne est toujours l'un ou l'autre. Les propriétés portent le nom des colonnes
 * des tables ServiceRequest / MedicationRequest ; $id est leur clé (idServiceRequest ou
 * idMedicationRequest).
 */
abstract class RequestLine
{
    public function __construct(
        private ?int $id,
        private string $idActivityDefinition,
        private DateTimeImmutable $startDate,
        private ?DateTimeImmutable $endDate = null,
        private ?int $frequencyHours = null,
        private ?string $timeConstraint = null,
        private ?string $idStoppedBy = null,
        private ?DateTimeImmutable $stoppedAt = null,
        private ?int $idCarePlan = null
    ) {
    }

    /** Null tant que la ligne n'est pas enregistrée. */
    public function getId(): ?int
    {
        return $this->id;
    }
      public function getIdCarePlan(): ?int
    {
        return $this->idCarePlan;
    }

    /** Type de soin (RG6) : chaque soin planifié issu de cette ligne aura ce type. */
    public function getIdActivityDefinition(): string
    {
        return $this->idActivityDefinition;
    }

    public function getStartDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    /** Null = pas de date de fin (RG3). */
    public function getEndDate(): ?DateTimeImmutable
    {
        return $this->endDate;
    }

    /** Intervalle entre deux soins, en heures (48 = tous les 2 jours) ; null = soin unique. */
    public function getFrequencyHours(): ?int
    {
        return $this->frequencyHours;
    }

    /** Contrainte horaire en texte libre : « à jeun », « 8 h et 20 h »… */
    public function getTimeConstraint(): ?string
    {
        return $this->timeConstraint;
    }

    /** Praticien qui a arrêté la ligne (RG10) ; null si elle n'est pas arrêtée. */
    public function getIdStoppedBy(): ?string
    {
        return $this->idStoppedBy;
    }

    public function getStoppedAt(): ?DateTimeImmutable
    {
        return $this->stoppedAt;
    }

    /** RG3 : récurrente si elle a une fréquence, unique sinon. */
    public function isRecurring(): bool
    {
        return $this->frequencyHours !== null;
    }

    /** AURA-22 : arrêtée si une date d'arrêt est enregistrée. */
    public function isStopped(): bool
    {
        return $this->stoppedAt !== null;
    }
}
