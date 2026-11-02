<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/** Type de soin du référentiel (table ActivityDefinition, RG6) : identifiant, nom, description. */
class ActivityDefinition
{
    public function __construct(
        private string $idActivityDefinition,
        private ?string $name = null,
        private ?string $description = null,
    ) {
    }

    public function getIdActivityDefinition(): string
    {
        return $this->idActivityDefinition;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
