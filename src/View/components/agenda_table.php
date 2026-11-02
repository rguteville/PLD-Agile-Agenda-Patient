<?php

use Aura\Core\View;

/**
 * Tableau d'une tranche de l'agenda (retards épinglés ou rotation) : heure, type, soin, responsable,
 * statut, notes, et l'action « Valider » quand la validation est activée.
 *
 * @var list<array<string, mixed>> $items lignes de AgendaService::presentDay()
 * @var string $caption titre lu par les lecteurs d'écran (visuellement masqué)
 * @var bool $validationEnabled affiche la colonne d'action (dialogue components/validation_dialog)
 */
$validationEnabled = $validationEnabled ?? false;
?>
<div class="table-scroll">
    <table class="agenda-table">
        <caption class="sr-only"><?= View::e($caption) ?></caption>
        <thead>
            <tr>
                <th scope="col" class="col-time">Heure</th>
                <th scope="col" class="col-kind">Type</th>
                <th scope="col">Soin / médicament</th>
                <th scope="col">Responsable</th>
                <th scope="col" class="col-status">Statut</th>
                <th scope="col">Notes</th>
                <?php if ($validationEnabled) : ?>
                    <th scope="col" class="col-action"><span class="sr-only">Action</span></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item) : ?>
                <tr class="is-<?= View::e($item['status']) ?>" data-appointment="<?= View::e($item['id']) ?>">
                    <td class="col-time">
                        <time datetime="<?= View::e($item['datetime']) ?>"><?= View::e($item['time']) ?></time>
                    </td>
                    <td class="col-kind"><?= View::e($item['kindLabel']) ?></td>
                    <td>
                        <span class="item-label"><?= View::e($item['label']) ?></span>
                        <?php if ($item['details'] !== null) : ?>
                            <span class="item-details"><?= View::e($item['details']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= View::e($item['responsible']) ?></td>
                    <td class="col-status">
                        <?= View::render('components/status_badge', ['status' => $item['status']]) ?>
                        <?php if ($item['completedAt'] !== null) : ?>
                            <?php /* Traçabilité : heure de réalisation. Le « qui » viendra avec AURA-18. */ ?>
                            <span class="trace">à <?= View::e($item['completedAt']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item['comment'] !== null) : ?>
                            <span class="note"><?= View::e($item['comment']) ?></span>
                        <?php else : ?>
                            <span class="sr-only">Aucune note</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($validationEnabled) : ?>
                        <td class="col-action">
                            <?php if (in_array($item['status'], ['planned', 'late'], true)) : ?>
                                <button type="button" class="button button--secondary" data-validate
                                        data-label="<?= View::e($item['time'] . ' · ' . $item['label']) ?>">
                                    Valider
                                </button>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
