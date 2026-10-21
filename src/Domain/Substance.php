<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Allergène du référentiel (table Substance, AURA-13) : une allergie se choisit uniquement
 * parmi ces entrées, jamais en texte libre. Lu par SubstanceDao.
 */
class Substance
{
    public function __construct(
        private string $idSubstance,
        private string $description
    ) {
    }

    public function getIdSubstance(): string
    {
        return $this->idSubstance;
    }

    /** Libellé affiché (« Pénicilline », « Latex »…). */
    public function getDescription(): string
    {
        return $this->description;
    }
}
