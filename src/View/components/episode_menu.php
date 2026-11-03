<?php

use Aura\Core\View;

/**
 * Menu figé à gauche des écrans d'un séjour (dossier, agenda) : un bouton principal en tête, toujours
 * au même endroit (« Agenda du jour » dans le dossier, « Dossier du séjour » dans l'agenda), puis les
 * parties du dossier. Dans le dossier, les sous-catégories ouvrent leur section (record-menu.js).
 *
 * @var array{href: string, label: string, icon: string} $primary bouton en tête du menu
 * @var list<array{href: string, label: string, openDetails?: bool, children?: list<array<string, mixed>>}> $items
 */
?>
<nav class="dossier-menu" aria-label="Parties du dossier">
    <a class="button dossier-menu__agenda" href="<?= View::e($primary['href']) ?>">
        <?= View::render('components/icon', ['name' => $primary['icon']]) ?>
        <?= View::e($primary['label']) ?>
    </a>
    <ul class="dossier-menu__list">
        <?php foreach ($items as $item) : ?>
            <li>
                <a href="<?= View::e($item['href']) ?>"><?= View::e($item['label']) ?></a>
                <?php if (($item['children'] ?? []) !== []) : ?>
                    <ul class="dossier-menu__sub">
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
