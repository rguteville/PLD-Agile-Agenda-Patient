<?php

use Aura\Core\View;

/**
 * Menu figé à gauche des écrans d'un séjour (dossier, agenda) : un bouton principal en tête, toujours
 * au même endroit (« Agenda du jour » dans le dossier, « Dossier du séjour » dans l'agenda), puis les
 * parties du dossier. Dans le dossier, les sous-catégories ouvrent leur section (record-menu.js).
 * Inclus par pages/episode/record.php et pages/episode/patient-agenda.php.
 *
 * @var array{href: string, label: string, icon: string} $primary bouton en tête du menu
 * @var list<array{href: string, label: string, children?: list<array{href: string, label: string,
 *      openDetails?: bool}>}> $items parties ; un enfant avec openDetails reçoit data-open-details
 */
?>
<nav class="record-menu" aria-label="Parties du dossier">
    <a class="button record-menu__agenda" href="<?= View::e($primary['href']) ?>">
        <?= View::render('components/icon', ['name' => $primary['icon']]) ?>
        <?= View::e($primary['label']) ?>
    </a>
    <ul class="record-menu__list">
        <?php foreach ($items as $item) : ?>
            <li>
                <a href="<?= View::e($item['href']) ?>"><?= View::e($item['label']) ?></a>
                <?php if (($item['children'] ?? []) !== []) : ?>
                    <ul class="record-menu__sub">
                        <?php foreach ($item['children'] as $child) : ?>
                            <li><a href="<?= View::e($child['href']) ?>"<?= !empty($child['openDetails'])
                                ? ' data-open-details' : '' ?>><?= View::e($child['label']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
