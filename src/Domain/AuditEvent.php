<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Une modification tracée d'un champ du dossier patient (table AuditEvent) : qui a changé quoi,
 * de quelle valeur à quelle valeur, et quand. Immuable : une ligne d'historique ne se modifie pas.
 */
final class AuditEvent
{
    public const ENTITY_PATIENT = 'Patient';
    public const ENTITY_EPISODE = 'EpisodeOfCare';

    /** Auteur enregistré tant qu'il n'y a pas d'identification du praticien (AURA-18). */
    public const AUTHOR_UNKNOWN = 'non identifié';

    public function __construct(
        private ?int $idAuditEvent,
        private string $entity,
        private int $entityId,
        private string $field,
        private ?string $oldValue,
        private ?string $newValue,
        private string $author,
        private ?DateTimeImmutable $recordedAt = null,
        private ?string $detail = null
    ) {
    }

    public function getIdAuditEvent(): ?int
    {
        return $this->idAuditEvent;
    }

    /** ENTITY_PATIENT ou ENTITY_EPISODE. */
    public function getEntity(): string
    {
        return $this->entity;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    /** Nom de colonne du champ modifié (clé de RecordFields ou colonne éditable de RecordEditService). */
    public function getField(): string
    {
        return $this->field;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    /** Précision libre, ex. le mode de recherche d'un rattachement (« recherche par nom »). */
    public function getDetail(): ?string
    {
        return $this->detail;
    }

    /** Fixé par la base à l'insertion ; null tant que l'événement n'est pas enregistré. */
    public function getRecordedAt(): ?DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
