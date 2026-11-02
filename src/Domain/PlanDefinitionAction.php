<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Ligne modèle d'un protocole (table PlanDefinitionAction) : les mêmes champs qu'une ligne de
 * soin ou de médicament (RequestLine), mais des durées à la place des dates. Appliqué à une date,
 * le protocole donne une ligne qui commence startOffsetHours heures après et dure durationHours
 * heures (null = sans date d'arrêt).
 */
class PlanDefinitionAction
{
    /** Valeurs de la colonne kind : un médicament (MedicationRequest) ou un soin (ServiceRequest). */
    public const KIND_MEDICATION = 'MEDICATION';
    public const KIND_CARE = 'CARE';

    public function __construct(
        private string $idPlanDefinitionAction,
        private string $idPlanDefinition,
        private string $kind,
        private string $idActivityDefinition,
        private int $startOffsetHours = 0,
        private ?int $durationHours = null,
        private ?int $frequencyHours = null,
        private ?string $timeConstraint = null,
        private ?string $idMedication = null,
        private ?string $dose = null,
        private ?string $route = null,
        private ?string $important = null,
        private ?string $nurseInstructions = null,
        private ?string $maxDose = null,
        private ?string $dilutionVolume = null,
        private ?string $solvent = null,
        private ?string $administrationDuration = null,
        private ?string $antibioticReason = null,
        private ?string $germ = null,
        private ?string $renalAdjustment = null,
        private ?string $adjustmentCriterion = null,
        private ?string $offGhsReason = null
    ) {
    }

    public function getIdPlanDefinitionAction(): string
    {
        return $this->idPlanDefinitionAction;
    }

    public function getIdPlanDefinition(): string
    {
        return $this->idPlanDefinition;
    }

    /** Une des constantes KIND_*. */
    public function getKind(): string
    {
        return $this->kind;
    }

    public function isMedication(): bool
    {
        return $this->kind === self::KIND_MEDICATION;
    }

    /** RG6 : type de soin de la ligne. */
    public function getIdActivityDefinition(): string
    {
        return $this->idActivityDefinition;
    }

    /** Heures entre l'application du protocole et le début de la ligne (0 = tout de suite). */
    public function getStartOffsetHours(): int
    {
        return $this->startOffsetHours;
    }

    /** Durée de la ligne en heures ; null = pas de date d'arrêt. */
    public function getDurationHours(): ?int
    {
        return $this->durationHours;
    }

    /** Intervalle entre deux soins, en heures ; null = soin unique (RG3). */
    public function getFrequencyHours(): ?int
    {
        return $this->frequencyHours;
    }

    public function getTimeConstraint(): ?string
    {
        return $this->timeConstraint;
    }

    /** Null pour un soin. */
    public function getIdMedication(): ?string
    {
        return $this->idMedication;
    }

    public function getDose(): ?string
    {
        return $this->dose;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function getImportant(): ?string
    {
        return $this->important;
    }

    public function getNurseInstructions(): ?string
    {
        return $this->nurseInstructions;
    }

    public function getMaxDose(): ?string
    {
        return $this->maxDose;
    }

    public function getDilutionVolume(): ?string
    {
        return $this->dilutionVolume;
    }

    public function getSolvent(): ?string
    {
        return $this->solvent;
    }

    public function getAdministrationDuration(): ?string
    {
        return $this->administrationDuration;
    }

    public function getAntibioticReason(): ?string
    {
        return $this->antibioticReason;
    }

    public function getGerm(): ?string
    {
        return $this->germ;
    }

    public function getRenalAdjustment(): ?string
    {
        return $this->renalAdjustment;
    }

    public function getAdjustmentCriterion(): ?string
    {
        return $this->adjustmentCriterion;
    }

    public function getOffGhsReason(): ?string
    {
        return $this->offGhsReason;
    }
}
