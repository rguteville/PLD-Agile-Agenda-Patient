<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Ligne de soin d'une prescription (table ServiceRequest). Tout le comportement est dans
 * RequestLine ; seule la clé porte un nom propre à la table.
 */
class ServiceRequest extends RequestLine
{
    public function getIdServiceRequest(): ?int
    {
        return $this->getId();
    }
}
