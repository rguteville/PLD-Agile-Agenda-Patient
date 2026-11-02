<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PlanDefinitionDao;
use Aura\Domain\PlanDefinitionAction;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Protocoles (« groupes de prescriptions » de l'écran B3) : appliquer un protocole pré-remplit
 * l'ordonnance en cours avec ses lignes, que le prescripteur peut encore modifier avant
 * d'enregistrer. Rien n'est écrit en base ici : l'ordonnance passe ensuite par
 * CarePlanService::prescribe(), comme une saisie ligne par ligne.
 */
final class PlanDefinitionService
{
    public function __construct(private PlanDefinitionDao $planDefinitionDao)
    {
    }

    /**
     * Protocoles proposés dans la liste « 2. » quand « Groupes de prescriptions » est choisi.
     *
     * @return array<string, string> idPlanDefinition => « titre (pathologie) »
     */
    public function activePlans(): array
    {
        $plans = [];
        foreach ($this->planDefinitionDao->findActive() as $planDefinition) {
            $pathology = $planDefinition->getPathology();
            $plans[$planDefinition->getIdPlanDefinition()] = $pathology !== null && $pathology !== ''
                ? sprintf('%s (%s)', $planDefinition->getTitle(), $pathology)
                : $planDefinition->getTitle();
        }

        return $plans;
    }

    /**
     * Lignes d'un protocole appliqué à la date $appliedAt, au format de l'écran B3 : clés de
     * CarePlanService::LINE_FIELDS, valeurs textuelles comme les champs du formulaire.
     * Pour chaque ligne : startDate = $appliedAt + startOffsetHours ; endDate = startDate +
     * durationHours, vide si le protocole ne fixe pas de durée.
     *
     * @return list<array<string, string>>
     * @throws InvalidArgumentException protocole inconnu ou retiré (message affichable)
     */
    public function linesToPrefill(string $idPlanDefinition, DateTimeImmutable $appliedAt): array
    {
        $planDefinition = $this->planDefinitionDao->findById($idPlanDefinition);
        if ($planDefinition === null) {
            throw new InvalidArgumentException('Ce protocole n\'existe pas.');
        }
        if (!$planDefinition->isActive()) {
            throw new InvalidArgumentException('Ce protocole a été retiré : il ne peut plus être appliqué.');
        }

        return array_map(
            static fn(PlanDefinitionAction $action) => self::lineOf($action, $appliedAt),
            $planDefinition->getActions()
        );
    }

    /** @return array<string, string> */
    private static function lineOf(PlanDefinitionAction $action, DateTimeImmutable $appliedAt): array
    {
        $start = $appliedAt->modify(sprintf('+%d hours', $action->getStartOffsetHours()));
        $end = $action->getDurationHours() !== null
            ? $start->modify(sprintf('+%d hours', $action->getDurationHours()))
            : null;

        // RG3 : même choix que l'écran B3, en jours quand la fréquence tombe sur des jours entiers.
        $hours = $action->getFrequencyHours();
        [$frequencyType, $frequencyHours, $frequencyDays] = match (true) {
            $hours === null => ['ONCE', '', ''],
            $hours % 24 === 0 => ['DAYS', '', (string) intdiv($hours, 24)],
            default => ['SCHEDULED', (string) $hours, ''],
        };

        return [
            'kind' => $action->isMedication() ? CarePlanService::KIND_MEDICATION : CarePlanService::KIND_CARE,
            'idActivityDefinition' => $action->getIdActivityDefinition(),
            'idMedication' => (string) $action->getIdMedication(),
            'dose' => (string) $action->getDose(),
            'route' => (string) $action->getRoute(),
            'important' => (string) $action->getImportant(),
            'nurseInstructions' => (string) $action->getNurseInstructions(),
            'maxDose' => (string) $action->getMaxDose(),
            'dilutionVolume' => (string) $action->getDilutionVolume(),
            'solvent' => (string) $action->getSolvent(),
            'administrationDuration' => (string) $action->getAdministrationDuration(),
            'antibioticReason' => (string) $action->getAntibioticReason(),
            'germ' => (string) $action->getGerm(),
            'renalAdjustment' => (string) $action->getRenalAdjustment(),
            'adjustmentCriterion' => (string) $action->getAdjustmentCriterion(),
            'offGhsReason' => (string) $action->getOffGhsReason(),
            'frequencyType' => $frequencyType,
            'frequencyHours' => $frequencyHours,
            'frequencyDays' => $frequencyDays,
            'startDate' => $start->format(CarePlanService::INPUT_DATE_FORMAT),
            'endDate' => $end?->format(CarePlanService::INPUT_DATE_FORMAT) ?? '',
            'timeConstraint' => (string) $action->getTimeConstraint(),
        ];
    }
}
