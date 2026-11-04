<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\PlanDefinition;
use Aura\Domain\PlanDefinitionAction;
use PDO;

/**
 * Accès aux protocoles (« groupes de prescriptions » de l'écran B3) : PlanDefinition = le
 * protocole, PlanDefinitionAction = ses lignes modèles, Pathology = la pathologie visée.
 * Classes et colonnes portent les mêmes noms (ressources HL7) : rien n'est traduit ici.
 * Lecture seule : les protocoles sont chargés par database/test.sql, aucun écran ne les saisit.
 * Un protocole retiré a active = FALSE (suppression logique propre à cette table, pas de removedAt).
 */
class PlanDefinitionDao
{
    private const ACTION_COLUMNS = 'idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition,
        idMedication, dose, route, important, nurseInstructions, maxDose, dilutionVolume, solvent,
        administrationDuration, antibioticReason, germ, renalAdjustment, adjustmentCriterion, offGhsReason,
        startOffsetHours, durationHours, frequencyHours, timeConstraint';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Protocoles proposés à la prescription (active = TRUE), avec le libellé de leur pathologie,
     * par pathologie puis par titre. Leurs lignes ne sont pas lues : voir findById().
     *
     * @return PlanDefinition[]
     */
    public function findActive(): array
    {
        $rows = $this->pdo->query(
            'SELECT d.idPlanDefinition, d.title, d.idPathology, d.active, p.label
             FROM PlanDefinition d
             LEFT JOIN Pathology p ON p.idPathology = d.idPathology
             WHERE d.active = TRUE
             ORDER BY p.label, d.title'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->mapToPlanDefinition($r, []), $rows);
    }

    /**
     * Un protocole avec ses lignes, par décalage de début puis par identifiant (tri texte du
     * VARCHAR). Un protocole retiré est lu lui aussi : c'est à l'appelant de refuser de l'appliquer.
     *
     * @return ?PlanDefinition null si l'identifiant n'existe pas
     */
    public function findById(string $idPlanDefinition): ?PlanDefinition
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.idPlanDefinition, d.title, d.idPathology, d.active, p.label
             FROM PlanDefinition d
             LEFT JOIN Pathology p ON p.idPathology = d.idPathology
             WHERE d.idPlanDefinition = :idPlanDefinition'
        );
        $stmt->execute(['idPlanDefinition' => $idPlanDefinition]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }

        $actions = $this->pdo->prepare(
            'SELECT ' . self::ACTION_COLUMNS . ' FROM PlanDefinitionAction
             WHERE idPlanDefinition = :idPlanDefinition
             ORDER BY startOffsetHours, idPlanDefinitionAction'
        );
        $actions->execute(['idPlanDefinition' => $idPlanDefinition]);

        return $this->mapToPlanDefinition(
            $r,
            array_map(fn(array $a) => $this->mapToAction($a), $actions->fetchAll(PDO::FETCH_ASSOC))
        );
    }

    /**
     * Ligne de PlanDefinition jointe à Pathology (label = libellé de la pathologie) vers l'entité.
     *
     * @param array<string, mixed> $r
     * @param PlanDefinitionAction[] $actions [] quand les lignes ne sont pas lues (findActive())
     */
    private function mapToPlanDefinition(array $r, array $actions): PlanDefinition
    {
        return new PlanDefinition(
            idPlanDefinition: (string) $r['idPlanDefinition'],
            title: (string) $r['title'],
            idPathology: $r['idPathology'],
            pathology: $r['label'],
            active: (bool) $r['active'],
            actions: $actions
        );
    }

    /**
     * Ligne de PlanDefinitionAction (colonnes de ACTION_COLUMNS) vers l'entité. Les SMALLINT
     * arrivent en chaîne depuis PDO : ils sont convertis en int, NULL gardé.
     *
     * @param array<string, ?string> $r
     */
    private function mapToAction(array $r): PlanDefinitionAction
    {
        return new PlanDefinitionAction(
            idPlanDefinitionAction: (string) $r['idPlanDefinitionAction'],
            idPlanDefinition: (string) $r['idPlanDefinition'],
            kind: (string) $r['kind'],
            idActivityDefinition: (string) $r['idActivityDefinition'],
            startOffsetHours: (int) $r['startOffsetHours'],
            durationHours: self::intOrNull($r['durationHours']),
            frequencyHours: self::intOrNull($r['frequencyHours']),
            timeConstraint: $r['timeConstraint'],
            idMedication: $r['idMedication'],
            dose: $r['dose'],
            route: $r['route'],
            important: $r['important'],
            nurseInstructions: $r['nurseInstructions'],
            maxDose: $r['maxDose'],
            dilutionVolume: $r['dilutionVolume'],
            solvent: $r['solvent'],
            administrationDuration: $r['administrationDuration'],
            antibioticReason: $r['antibioticReason'],
            germ: $r['germ'],
            renalAdjustment: $r['renalAdjustment'],
            adjustmentCriterion: $r['adjustmentCriterion'],
            offGhsReason: $r['offGhsReason']
        );
    }

    /** Colonne numérique facultative : null si NULL en base, entier sinon. */
    private static function intOrNull(mixed $value): ?int
    {
        return $value !== null ? (int) $value : null;
    }
}
