<?php

use Aura\Core\View;

/**
 * Boutons d'action sur un soin (AURA-26, AURA-28) : « Valider » pour un soin validable (canValidate de
 * AgendaService : en retard, ou prévu avant la fin de la rotation en cours, AgendaService::shiftEnd() ;
 * ligne arrêtée si la dose a été faite avant l'arrêt) et « Note » pour tout soin. Retours du 2026-11-04 :
 * plus de bouton « Non effectué », ce choix se fait dans le dialogue (Réalisé / Non effectué). « Note »
 * ouvre le même dialogue sans ce choix (note seule). Ils ouvrent components/validation_dialog
 * (public/js/agenda.js lit data-care-action, data-appointment, data-episode, data-label).
 * Inclus par components/agenda_table.php (agenda du patient), components/care_line.php (agenda du
 * service) et pages/team/planning.php (« Mon planning »).
 *
 * @var array<string, mixed> $item ligne de AgendaService (id, time, label, status, canValidate) ; sans
 *      canValidate, « Valider » est proposé pour un soin prévu ou en retard
 * @var int $idEpisodeOfCare séjour du soin
 * @var string $context préfixe du résumé affiché dans le dialogue (lit et patient), '' par défaut
 * @var bool $withNote bouton « Note » affiché, true par défaut (aucun appelant ne le passe à false)
 */
$context = $context ?? '';
$withNote = $withNote ?? true;
$summary = ($context !== '' ? $context . ' : ' : '') . $item['label'] . ' à ' . $item['time'];
$attributes = ' data-appointment="' . View::e($item['id']) . '"'
    . ' data-episode="' . View::e($idEpisodeOfCare) . '"'
    . ' data-label="' . View::e($summary) . '"';
?>
<span class="care-buttons">
    <?php if ($item['canValidate'] ?? in_array($item['status'], ['planned', 'late'], true)) : ?>
        <button type="button" class="button" data-care-action="done"<?= $attributes ?>>Valider</button>
    <?php endif; ?>
    <?php if ($withNote) : ?>
        <button type="button" class="button button--secondary" data-care-action="note"<?= $attributes ?>>Note</button>
    <?php endif; ?>
</span>
