<?php

use Aura\Core\View;

/**
 * Fil d'Ariane : l'application a trois niveaux (Patients → Dossier du séjour →
 * Agenda / Prescriptions). Le dernier élément est la page courante (aria-current), sans lien.
 * Inclus par pages/episode/record.php, pages/episode/patient-agenda.php, pages/careplan/list.php et
 * pages/careplan/form.php.
 *
 * @var list<array{label: string, href?: string}> $items niveaux, du plus général à la page courante
 */
?>
<nav class="breadcrumb" aria-label="Fil d'Ariane">
    <ol>
        <?php foreach ($items as $i => $item) : ?>
            <?php $last = $i === count($items) - 1; ?>
            <li<?= $last ? ' aria-current="page"' : '' ?>>
                <?php if (!$last && isset($item['href'])) : ?>
                    <a href="<?= View::e($item['href']) ?>"><?= View::e($item['label']) ?></a>
                <?php else : ?>
                    <?= View::e($item['label']) ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
