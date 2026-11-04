<?php

use Aura\Core\View;

/**
 * Badge « Ordonnance non validée » cliquable (retours du 2026-11-04) : il mène à l'écran « Nouvelles
 * prescriptions » du séjour, sur le bloc de validation de cette ordonnance (ancre #ordonnance-<id>, section
 * « En attente de validation par un médecin »), où un médecin la valide (RG5). Sans ordonnance ni séjour connus,
 * le badge reste un simple badge.
 * Utilisé par l'agenda du patient (agenda_table), l'agenda du service (care_line), « Mon planning » et la liste
 * des prescriptions.
 *
 * @var int|string|null $idEpisodeOfCare séjour de l'ordonnance
 * @var int|string|null $idCarePlan ordonnance à valider
 */
$idEpisodeOfCare = $idEpisodeOfCare ?? null;
$idCarePlan = $idCarePlan ?? null;
$href = $idEpisodeOfCare !== null && $idCarePlan !== null && $idCarePlan !== ''
    ? '/care-plans/new?idEpisodeOfCare=' . rawurlencode((string) $idEpisodeOfCare)
        . '#ordonnance-' . rawurlencode((string) $idCarePlan)
    : null;
?>
<?= View::render('components/status_badge', [
    'status' => 'to_validate',
    'label' => 'Ordonnance non validée',
    'href' => $href,
    'title' => $href !== null ? 'valider l\'ordonnance n° ' . $idCarePlan : null,
]);
