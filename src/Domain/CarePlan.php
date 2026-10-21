<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Prescription (« ordonnance ») d'un séjour (Epic 4, table CarePlan) : qui prescrit, dans quel
 * mode, la validation, et ses lignes (ServiceRequest, MedicationRequest). Les prescriptions sont
 * la seule source de l'agenda.
 *
 * Le statut n'est jamais stocké : il se déduit des dates de validation et d'arrêt (RG5).
 */
class CarePlan
{
    /** Modes de prescription de l'écran B3 (valeurs de la colonne CarePlan.mode). */
    public const MODES = ['MEDICAL', 'PARAMEDICAL', 'EMERGENCY'];

    public const STATUS_NOT_VALIDATED = 'NOT_VALIDATED';
    public const STATUS_VALIDATED = 'VALIDATED';
    public const STATUS_STOPPED = 'STOPPED';

    /**
     * @param RequestLine[] $lines
     */
    public function __construct(
        private ?int $idCarePlan,
        private int $idEpisodeOfCare,
        private string $idPrescriber,
        private string $mode,
        private array $lines = [],
        private ?DateTimeImmutable $recordedAt = null,
        private ?string $idValidator = null,
        private ?DateTimeImmutable $validatedAt = null
    ) {
    }

    /** Null tant que la prescription n'est pas enregistrée. */
    public function getIdCarePlan(): ?int
    {
        return $this->idCarePlan;
    }

    /** RG4 : séjour auquel la prescription est rattachée. */
    public function getIdEpisodeOfCare(): int
    {
        return $this->idEpisodeOfCare;
    }

    /** RG4 : praticien qui a prescrit. */
    public function getIdPrescriber(): string
    {
        return $this->idPrescriber;
    }

    /** Une des valeurs de self::MODES. */
    public function getMode(): string
    {
        return $this->mode;
    }

    /** @return RequestLine[] */
    public function getLines(): array
    {
        return $this->lines;
    }

    /** RG10 : horodatage de la saisie, fixé par la base (null tant que ce n'est pas enregistré). */
    public function getRecordedAt(): ?DateTimeImmutable
    {
        return $this->recordedAt;
    }

    /** RG5 / RG10 : médecin qui a validé ; null si la prescription n'est pas validée. */
    public function getIdValidator(): ?string
    {
        return $this->idValidator;
    }

    public function getValidatedAt(): ?DateTimeImmutable
    {
        return $this->validatedAt;
    }

    /** RG5 : validée si une date de validation est enregistrée. */
    public function isValidated(): bool
    {
        return $this->validatedAt !== null;
    }

    /**
     * Statut d'une ligne de cette prescription, tel qu'il s'affiche : une ligne arrêtée l'est
     * quoi qu'il arrive ; sinon elle suit la validation de la prescription (RG5).
     */
    public function statusOf(RequestLine $line): string
    {
        if ($line->isStopped()) {
            return self::STATUS_STOPPED;
        }

        return $this->isValidated() ? self::STATUS_VALIDATED : self::STATUS_NOT_VALIDATED;
    }
}
