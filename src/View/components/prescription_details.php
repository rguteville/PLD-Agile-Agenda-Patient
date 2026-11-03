<?php

use Aura\Core\View;

/**
 * Carte de la prescription d'un soin (retours du 2026-11-03) : un « + » sur le soin, qui déplie tous
 * les éléments de la prescription (fréquence, période, dose, voie, consigne, prescripteur, validation,
 * note, arrêt). <details> natif : clavier et lecteurs d'écran sans JavaScript.
 *
 * @var array<string, mixed> $item ligne de AgendaService (label, prescription)
 */
$rows = $item['prescription'] ?? [];
if ($rows === []) {
    return;
}
?>
<details class="prescription-details">
    <summary>
        <span aria-hidden="true">+</span>
        <span class="sr-only">Prescription de <?= View::e($item['label']) ?></span>
    </summary>
    <dl class="prescription-details__card">
        <?php foreach ($rows as $row) : ?>
            <dt><?= View::e($row['label']) ?></dt>
            <dd><?= View::e($row['value']) ?></dd>
        <?php endforeach; ?>
    </dl>
</details>
