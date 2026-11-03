<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeInterface;

class EpisodeOfCare
{
    public function __construct(
        private ?int $idEpisodeOfCare,
        private int $idPatient,
        private string $episodeNumber,
        private DateTimeInterface $admissionDate,
        private ?DateTimeInterface $dischargeDate = null,
        private ?string $bedNumber = null,
        private ?string $boxNumber = null,
        private ?string $idService = null,
        private ?float $weight = null,
        private ?float $height = null,
        private ?string $precautions = null,
        private array $details = []
    ) {
    }

    public function getIdEpisodeOfCare(): ?int
    {
        return $this->idEpisodeOfCare;
    }

    public function getIdPatient(): int
    {
        return $this->idPatient;
    }

    public function getEpisodeNumber(): string
    {
        return $this->episodeNumber;
    }

    public function getAdmissionDate(): DateTimeInterface
    {
        return $this->admissionDate;
    }

    public function getDischargeDate(): ?DateTimeInterface
    {
        return $this->dischargeDate;
    }

    public function getBedNumber(): ?string
    {
        return $this->bedNumber;
    }

    public function getBoxNumber(): ?string
    {
        return $this->boxNumber;
    }

    public function getIdService(): ?string
    {
        return $this->idService;
    }

    /** Poids à l'admission, en kg (null = non renseigné). */
    public function getWeight(): ?float
    {
        return $this->weight;
    }

    /** Taille à l'admission, en cm (null = non renseignée). */
    public function getHeight(): ?float
    {
        return $this->height;
    }

    /** Précautions à prendre (isolement, risque de chute…), texte libre. */
    public function getPrecautions(): ?string
    {
        return $this->precautions;
    }

    /**
     * Champs complémentaires de l'admission (RecordFields::EPISODE) : valeur texte ou null si
     * non renseigné. Les clés absentes sont traitées comme non renseignées.
     *
     * @return array<string, ?string>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** Valeur d'un champ complémentaire, null si non renseigné ou inconnu. */
    public function getDetail(string $field): ?string
    {
        return $this->details[$field] ?? null;
    }

    public function isOngoing(): bool
    {
        return $this->dischargeDate === null;
    }
}
