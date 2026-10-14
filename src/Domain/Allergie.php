<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Allergie connue d'un patient, choisie dans le référentiel des allergènes (RG2, AURA-13).
 * Lecture seule pour l'instant (bandeau AURA-14) ; la saisie viendra avec AURA-13.2.
 */
class Allergie
{
    public function __construct(
        private int $idAllergie,
        private int $idPatient,
        private string $idAllergene,
        private string $substance,
        private ?string $criticite = null,
        private ?DateTimeImmutable $dateSaisie = null
    ) {
    }

    public function getIdAllergie(): int
    {
        return $this->idAllergie;
    }

    public function getIdPatient(): int
    {
        return $this->idPatient;
    }

    public function getIdAllergene(): string
    {
        return $this->idAllergene;
    }

    /** Libellé de l'allergène (colonne Allergene.substance). */
    public function getSubstance(): string
    {
        return $this->substance;
    }

    public function getCriticite(): ?string
    {
        return $this->criticite;
    }

    /**
     * Date d'enregistrement dans AURA, fixée par la base à l'insertion (null tant que l'allergie
     * n'est pas enregistrée). Pas la date d'apparition de l'allergie.
     */
    public function getDateSaisie(): ?DateTimeImmutable
    {
        return $this->dateSaisie;
    }
}
