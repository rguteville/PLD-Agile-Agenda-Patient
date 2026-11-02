<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Allergie connue d'un patient, choisie dans le référentiel des allergènes (RG2, AURA-13).
 * Liée au patient et non au séjour. L'id est null tant que l'allergie n'est pas enregistrée
 * (AllergieDao::insert() le calcule).
 */
class Allergie
{
    /**
     * Criticités acceptées (AURA-13.2) : liste fermée côté PHP, valeurs stockées telles
     * quelles (comme database/test.sql). La criticité est facultative (null = non évaluée).
     */
    public const CRITICALITIES = ['Faible', 'Élevée'];

    /**
     * Niveau d'affichage d'une criticité (couleur du bandeau et des listes) : 'high' pour « Élevée »,
     * 'low' pour « Faible », 'unknown' si non évaluée ou valeur inattendue (traitée avec prudence,
     * comme une allergie à surveiller). Le texte de la criticité est toujours affiché en plus.
     */
    public static function criticalityLevel(?string $criticality): string
    {
        return match ($criticality === null ? null : trim($criticality)) {
            'Élevée' => 'high',
            'Faible' => 'low',
            default => 'unknown',
        };
    }

    public function __construct(
        private ?int $idAllergie,
        private int $idPatient,
        private string $idAllergene,
        private string $substance,
        private ?string $criticite = null,
        private ?DateTimeImmutable $dateSaisie = null
    ) {
    }

    public function getIdAllergie(): ?int
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

    /** Libellé de l'allergène (Substance::getDescription()). */
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
