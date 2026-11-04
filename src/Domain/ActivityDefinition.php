<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Type de soin du référentiel (ressource HL7 ActivityDefinition, table ActivityDefinition, RG6) :
 * identifiant, nom, description. Lu par ActivityDefinitionDao.
 */
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
