<?php

use Aura\Core\View;

/**
 * Badge « Ordonnance non validée » cliquable : il mène à l'écran « Nouvelles
 * prescriptions » du séjour, sur le bloc de validation de cette ordonnance (ancre #ordonnance-<id>, section
 * « En attente de validation par un médecin »), où un médecin la valide (RG5). Sans ordonnance ni séjour connus,
 * le badge reste un simple badge. Séjour clos : plus de lien (on n'ajoute ni ne valide rien sur un séjour terminé),
 * libellé « Non validée (séjour clos) ».
 * Utilisé par l'agenda du patient (agenda_table), l'agenda du service (care_line), « Mon planning » et la liste
 * des prescriptions.
 *
 * @var int|string|null $idEpisodeOfCare séjour de l'ordonnance
 * @var int|string|null $idCarePlan ordonnance à valider
 * @var bool $closed séjour clos, false par défaut
 */
$idEpisodeOfCare = $idEpisodeOfCare ?? null;
$idCarePlan = $idCarePlan ?? null;
$closed = $closed ?? false;
$href = !$closed && $idEpisodeOfCare !== null && $idCarePlan !== null && $idCarePlan !== ''
    ? '/care-plans/new?idEpisodeOfCare=' . rawurlencode((string) $idEpisodeOfCare)
        . '#ordonnance-' . rawurlencode((string) $idCarePlan)
    : null;
?>
<?= View::render('components/status_badge', [
    'status' => 'to_validate',
    'label' => $closed ? 'Non validée (séjour clos)' : 'Ordonnance non validée',
    'href' => $href,
    'title' => $href !== null ? 'valider l\'ordonnance n° ' . $idCarePlan : null,
]);
