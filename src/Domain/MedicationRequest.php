<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Ligne de prescription d'un médicament  : en plus du rythme,
 * le médicament, la dose et la voie, obligatoires, et des textes facultatifs de la figure 9 :
 * « Important -> », « Consignes pour IDE », « (Dose max) », « Volume de dilution », « Solvant »,
 * « Durée d'administration », motif et germe (si antibiotique), adaptation rénale et son critère,
 * « Motif (si hors GHS) ».
 */
class MedicationRequest extends RequestLine
{
    public function __construct(
        ?int $id,
        string $idActivityDefinition,
        private string $idMedication,
        private string $dose,
        private string $route,
        DateTimeImmutable $startDate,
        ?DateTimeImmutable $endDate = null,
        ?int $frequencyHours = null,
        ?string $timeConstraint = null,
        ?string $idStoppedBy = null,
        ?DateTimeImmutable $stoppedAt = null,
        ?int $idCarePlan = null,
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
        parent::__construct(
            $id,
            $idActivityDefinition,
            $startDate,
            $endDate,
            $frequencyHours,
            $timeConstraint,
            $idStoppedBy,
            $stoppedAt,
            $idCarePlan
        );
    }
    public function getIdMedicationRequest(): ?int
    {
        return $this->getId();
    }

    public function getIdMedication(): string
    {
        return $this->idMedication;
    }

    /** Texte libre : « 1 g », « 0,5 µg/kg/min ». */
    public function getDose(): string
    {
        return $this->dose;
    }

    /** Voie d'administration : IV, PO, SC… */
    public function getRoute(): string
    {
        return $this->route;
    }

    /** « Important -> » de l'écran B3 : null si rien n'a été saisi. */
    public function getImportant(): ?string
    {
        return $this->important;
    }

    /** « Consignes pour IDE » (infirmier) de l'écran B3 : null si rien n'a été saisi. */
    public function getNurseInstructions(): ?string
    {
        return $this->nurseInstructions;
    }

    /** « (Dose max) » de l'écran B3, texte libre (« 4 g/24 h ») : null si rien n'a été saisi. */
    public function getMaxDose(): ?string
    {
        return $this->maxDose;
    }

    /** « Volume de dilution » de l'écran B3 (« 100 mL ») : null si rien n'a été saisi. */
    public function getDilutionVolume(): ?string
    {
        return $this->dilutionVolume;
    }

    /** « Solvant » de l'écran B3 (« NaCl 0,9 % ») : null si rien n'a été saisi. */
    public function getSolvent(): ?string
    {
        return $this->solvent;
    }

    /** « Durée d'administration » (« 30 min ») : null si rien n'a été saisi. */
    public function getAdministrationDuration(): ?string
    {
        return $this->administrationDuration;
    }

    /** « Motif (si antibiotique) » : null si rien n'a été saisi. */
    public function getAntibioticReason(): ?string
    {
        return $this->antibioticReason;
    }

    /** « Germe (si antibiotique) » : null si rien n'a été saisi. */
    public function getGerm(): ?string
    {
        return $this->germ;
    }

    /** « Adaptation rénale » : null si rien n'a été saisi. */
    public function getRenalAdjustment(): ?string
    {
        return $this->renalAdjustment;
    }

    /** « Critère d'adaptation » : null si rien n'a été saisi. */
    public function getAdjustmentCriterion(): ?string
    {
        return $this->adjustmentCriterion;
    }

    /** « Motif (si hors GHS) » : null si rien n'a été saisi. */
    public function getOffGhsReason(): ?string
    {
        return $this->offGhsReason;
    }
}
