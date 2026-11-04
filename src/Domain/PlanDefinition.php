<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Protocole (ressource HL7 PlanDefinition, table PlanDefinition), la catégorie « Protocoles » de
 * l'écran B3 : un modèle d'ordonnance pour une pathologie, dont les lignes (PlanDefinitionAction)
 * pré-remplissent une nouvelle prescription. Un protocole retiré n'est plus proposé (active = false) mais reste en
 * base pour les ordonnances qui en sont issues.
 *
 * Accesseurs get*() : un par colonne, sans logique ni validation.
 */
class PlanDefinition
{
    /**
     * @param PlanDefinitionAction[] $actions
     */
    public function __construct(
        private string $idPlanDefinition,
        private string $title,
        private ?string $idPathology = null,
        private ?string $pathology = null,
        private bool $active = true,
        private array $actions = []
    ) {
    }

    public function getIdPlanDefinition(): string
    {
        return $this->idPlanDefinition;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getIdPathology(): ?string
    {
        return $this->idPathology;
    }

    /** Libellé de la pathologie (Pathology.label), null si le protocole n'en a pas. */
    public function getPathology(): ?string
    {
        return $this->pathology;
    }

    /** Protocole encore proposé à la prescription (un protocole retiré reste en base). */
    public function isActive(): bool
    {
        return $this->active;
    }

    /** @return PlanDefinitionAction[] vide si le protocole a été lu sans ses lignes */
    public function getActions(): array
    {
        return $this->actions;
    }
}
