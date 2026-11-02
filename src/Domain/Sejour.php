<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeInterface;

class Sejour
{
    public function __construct(
        private ?int $idSejour,
        private int $idPatient,
        private string $numSejour,
        private DateTimeInterface $dateAdmission,
        private ?DateTimeInterface $dateSortie = null,
        private ?string $numLit = null,
        private ?string $numBox = null,
        private ?string $idService = null,
        private ?float $poids = null,
        private ?float $taille = null,
        private ?string $precautions = null,
        private array $details = []
    ) {
    }

    public function getIdSejour(): ?int
    {
        return $this->idSejour;
    }

    public function getIdPatient(): int
    {
        return $this->idPatient;
    }

    public function getNumSejour(): string
    {
        return $this->numSejour;
    }

    public function getDateAdmission(): DateTimeInterface
    {
        return $this->dateAdmission;
    }

    public function getDateSortie(): ?DateTimeInterface
    {
        return $this->dateSortie;
    }

    public function getNumLit(): ?string
    {
        return $this->numLit;
    }

    public function getNumBox(): ?string
    {
        return $this->numBox;
    }

    public function getIdService(): ?string
    {
        return $this->idService;
    }

    /** Poids à l'admission, en kg (null = non renseigné). */
    public function getPoids(): ?float
    {
        return $this->poids;
    }

    /** Taille à l'admission, en cm (null = non renseignée). */
    public function getTaille(): ?float
    {
        return $this->taille;
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

    public function estEnCours(): bool
    {
        return $this->dateSortie === null;
    }
}
