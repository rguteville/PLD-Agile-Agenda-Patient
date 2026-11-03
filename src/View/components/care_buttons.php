<?php

use Aura\Core\View;

/**
 * Boutons d'action sur un soin (AURA-26, AURA-28) : « Valider » et « Non effectué » pour un soin
 * prévu ou en retard, « Note » pour tout soin. Ils ouvrent components/validation_dialog (agenda.js).
 * Utilisé par l'agenda du patient, l'agenda du service et « Mon planning ».
 *
 * @var array<string, mixed> $item ligne de AgendaService (id, time, label, status)
 * @var int $idEpisodeOfCare séjour du soin
 * @var string $context préfixe du résumé affiché dans le dialogue (patient), facultatif
 */
$context = $context ?? '';
$summary = ($context !== '' ? $context . ' · ' : '') . $item['time'] . ' · ' . $item['label'];
$attributes = ' data-appointment="' . View::e($item['id']) . '"'
    . ' data-episode="' . View::e($idEpisodeOfCare) . '"'
    . ' data-label="' . View::e($summary) . '"';
?>
<span class="care-buttons">
    <?php if (in_array($item['status'], ['planned', 'late'], true)) : ?>
        <button type="button" class="button" data-care-action="done"<?= $attributes ?>>Valider</button>
        <button type="button" class="button button--secondary" data-care-action="not_done"<?= $attributes ?>>
            Non effectué
        </button>
    <?php endif; ?>
    <button type="button" class="button button--secondary" data-care-action="note"<?= $attributes ?>>Note</button>
</span>
