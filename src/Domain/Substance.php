<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Allergène du référentiel (ressource HL7 Substance, table Substance, AURA-13) : une allergie
 * structurée (AllergyIntolerance) se choisit uniquement parmi ces entrées. Une allergie absente
 * du référentiel va dans la note libre Patient.allergyNote. Lu par SubstanceDao.
 *
 * Accesseurs get*() : un par colonne, sans logique ni validation.
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

    /** Libellé affiché, colonne Substance.name (« Pénicilline », « Latex »…). */
    public function getDescription(): string
    {
        return $this->description;
    }
}
