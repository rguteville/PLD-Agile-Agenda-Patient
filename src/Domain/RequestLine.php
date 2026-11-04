<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Ligne d'une prescription (CarePlan) : un soin (ServiceRequest) ou un médicament
 * (MedicationRequest), avec son propre rythme (RG3). Unique si frequencyHours est null (un seul
 * soin à startDate), récurrente sinon (un soin toutes les frequencyHours heures, jusqu'à endDate).
 *
 * Abstraite : une ligne est toujours l'un ou l'autre. Les propriétés portent le nom des colonnes
 * des tables ServiceRequest / MedicationRequest ; $id est leur clé (idServiceRequest ou
 * idMedicationRequest). Une ligne n'est jamais supprimée : elle est arrêtée (stoppedAt, AURA-22).
 * Immuable, sauf setIdActivityDefinition() (voir sa note).
 */
abstract class RequestLine
{
    /**
     * AURA-24.4 : une ligne récurrente sans date d'arrêt est planifiée jusqu'à ce nombre de jours
     * après son début (hypothèse de travail, à confirmer avec le service : non fixé par le cahier des charges).
     */
    public const PLANNING_HORIZON_DAYS = 7;

    public function __construct(
        private ?int $id,
        private string $idActivityDefinition,
        private DateTimeImmutable $startDate,
        private ?DateTimeImmutable $endDate = null,
        private ?int $frequencyHours = null,
        private ?string $timeConstraint = null,
        private ?string $idStoppedBy = null,
        private ?DateTimeImmutable $stoppedAt = null,
        private ?int $idCarePlan = null
    ) {
    }

    /** Null tant que la ligne n'est pas enregistrée. */
    public function getId(): ?int
    {
        return $this->id;
    }
    public function getIdCarePlan(): ?int
    {
        return $this->idCarePlan;
    }

    /** Type de soin (RG6) : chaque soin planifié issu de cette ligne aura ce type. */
    public function getIdActivityDefinition(): string
    {
        return $this->idActivityDefinition;
    }

    public function getStartDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    /** Null = pas de date de fin (RG3). */
    public function getEndDate(): ?DateTimeImmutable
    {
        return $this->endDate;
    }

    /** Intervalle entre deux soins, en heures (48 = tous les 2 jours) ; null = soin unique. */
    public function getFrequencyHours(): ?int
    {
        return $this->frequencyHours;
    }

    /** Contrainte horaire en texte libre : « à jeun », « 8 h et 20 h »… */
    public function getTimeConstraint(): ?string
    {
        return $this->timeConstraint;
    }

    /** Praticien qui a arrêté la ligne (RG10) ; null si elle n'est pas arrêtée. */
    public function getIdStoppedBy(): ?string
    {
        return $this->idStoppedBy;
    }

    /** Heure de l'arrêt (AURA-22, heure de PHP) ; null si la ligne n'est pas arrêtée. */
    public function getStoppedAt(): ?DateTimeImmutable
    {
        return $this->stoppedAt;
    }

    /**
     * AURA-24.4 : heures des soins planifiés (Appointment) issus de cette ligne, celles que
     * l'agenda du séjour affiche. RG3 : unique = startDate seul ; récurrente = startDate puis
     * toutes les frequencyHours heures, jusqu'à endDate inclus ou, sans date d'arrêt, jusqu'à
     * PLANNING_HORIZON_DAYS jours après le début.
     *
     * @return DateTimeImmutable[]
     */
    public function plannedTimes(): array
    {
        // Relecture adversariale du 2026-11-04 : même avec une date de fin, seuls les PLANNING_HORIZON_DAYS
        // premiers jours sont générés à l'enregistrement ; la génération quotidienne (AgendaGenerationService)
        // complète ensuite, jour après jour. Une ligne de plusieurs mois ne crée donc pas des milliers de soins.
        $horizon = $this->startDate->modify(sprintf('+%d days', self::PLANNING_HORIZON_DAYS));
        $until = $this->frequencyHours === null
            ? $this->startDate
            : ($this->endDate !== null && $this->endDate < $horizon ? $this->endDate : $horizon);

        return $this->plannedTimesBetween($this->startDate, $until);
    }

    /**
     * AURA-24.2 : heures prévues de la ligne comprises entre $from et $until (bornes incluses), sans
     * dépasser endDate. C'est le seul algorithme d'occurrences d'AURA : plannedTimes() (enregistrement
     * de la prescription) et la génération quotidienne de l'agenda (AgendaGenerationService) l'utilisent
     * tous les deux, si bien qu'une même ligne donne toujours les mêmes heures (aucun décalage, donc
     * aucun doublon). Les heures sont toujours comptées depuis startDate, jamais depuis $from.
     *
     * @return DateTimeImmutable[]
     */
    public function plannedTimesBetween(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        if ($this->frequencyHours === null) {
            return $this->startDate >= $from && $this->startDate <= $until ? [$this->startDate] : [];
        }

        $last = $this->endDate !== null && $this->endDate < $until ? $this->endDate : $until;
        $step = sprintf('+%d hours', $this->frequencyHours);
        $times = [];
        for ($time = $this->startDate; $time <= $last; $time = $time->modify($step)) {
            if ($time >= $from) {
                $times[] = $time;
            }
        }

        return $times;
    }

    /** RG3 : récurrente si elle a une fréquence, unique sinon. */
    public function isRecurring(): bool
    {
        return $this->frequencyHours !== null;
    }

    /** AURA-22 : arrêtée si une date d'arrêt est enregistrée. */
    public function isStopped(): bool
    {
        return $this->stoppedAt !== null;
    }

    /**
     * Seule exception à l'immuabilité. AgendaService::getAgenda() remplace l'identifiant par un
     * LIBELLÉ à afficher (nom du type de soin, ou DCI du médicament pour une MedicationRequest),
     * sur des lignes lues pour l'agenda et jamais réécrites. Après cet appel, la valeur n'est plus
     * un idActivityDefinition : ne pas passer la ligne à CarePlanDao.
     */
    public function setIdActivityDefinition(string $idActivityDefinition): void
    {
        $this->idActivityDefinition = $idActivityDefinition;
    }
}
