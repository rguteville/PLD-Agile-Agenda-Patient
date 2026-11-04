<?php

use Aura\Core\View;

/**
 * Carte de la prescription d'un soin : un « + » sur le soin, qui déplie tous
 * les éléments de la prescription (fréquence, période, dose, voie, consigne, prescripteur, validation,
 * note, arrêt). <details> natif : clavier et lecteurs d'écran sans JavaScript. Rien n'est rendu si la
 * prescription est vide. Le « + » se place à droite du titre du soin : inclus dans le
 * <span class="care-title"> de components/agenda_table.php, components/care_line.php et
 * pages/team/planning.php.
 *
 * @var array<string, mixed> $item ligne de AgendaService (label, prescription : liste de [label, value])
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
