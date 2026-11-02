<?php

use Aura\Core\View;

/**
 * Badge de statut : icône + texte + couleur, jamais la couleur seule (personnel daltonien,
 * écrans de mauvaise qualité). Les clés sont celles renvoyées par les services (AgendaService,
 * CarePlanService) ; pour ajouter un statut, voir docs/ui.md.
 *
 * Usage : View::render('components/status_badge', ['status' => 'late'])
 *         View::render('components/status_badge', ['status' => 'validated', 'label' => 'Validée par …'])
 *
 * @var string $status clé de statut (planned, late, done, to_validate, validated, stopped, cancelled, not_done)
 * @var string|null $label texte affiché à la place du libellé par défaut
 */
$statuses = [
    'planned' => ['label' => 'Prévu', 'icon' => 'clock'],
    'late' => ['label' => 'En retard', 'icon' => 'clock-alert'],
    'done' => ['label' => 'Effectué', 'icon' => 'circle-check'],
    'to_validate' => ['label' => 'À valider par un médecin', 'icon' => 'pen-line'],
    'validated' => ['label' => 'Validée', 'icon' => 'shield-check'],
    'stopped' => ['label' => 'Arrêtée', 'icon' => 'ban'],
    'cancelled' => ['label' => 'Annulé', 'icon' => 'circle-x'],
    'not_done' => ['label' => 'Non fait', 'icon' => 'octagon-x'],
];

// Statut inconnu (nouvelle valeur en base) : affiché tel quel, en neutre, pour ne rien cacher.
$known = isset($statuses[$status]);
$key = $known ? $status : 'unknown';
$text = $label ?? ($known ? $statuses[$status]['label'] : $status);
$icon = $known ? $statuses[$status]['icon'] : 'circle-alert';
?>
<span class="badge badge--<?= View::e($key) ?>" data-status="<?= View::e($status) ?>">
    <?= View::render('components/icon', ['name' => $icon]) ?>
    <span><?= View::e($text) ?></span>
</span>
