<?php

use Aura\Core\View;

/**
 * Tableau d'une tranche de l'agenda (retards épinglés ou rotation) : heure, type, soin, responsable,
 * statut, notes, et l'action « Valider » quand la validation est activée.
 *
 * @var list<array<string, mixed>> $items lignes de AgendaService::presentDay()
 * @var string $caption titre lu par les lecteurs d'écran (visuellement masqué)
 * @var bool $validationEnabled affiche la colonne d'actions (dialogue components/validation_dialog)
 * @var int|null $idEpisodeOfCare séjour des soins (envoyé avec l'action)
 */
$validationEnabled = $validationEnabled ?? false;
$idEpisodeOfCare = $idEpisodeOfCare ?? null;
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
                    <th scope="col" class="col-action"><span class="sr-only">Actions</span></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item) : ?>
                <tr class="is-<?= View::e($item['status']) ?>" data-appointment-row
                    data-status="<?= View::e($item['status']) ?>" data-care-label="<?= View::e($item['label']) ?>">
                    <td class="col-time">
                        <time datetime="<?= View::e($item['datetime']) ?>"><?= View::e($item['time']) ?></time>
                    </td>
                    <td class="col-kind"><?= View::e($item['kindLabel']) ?></td>
                    <td>
                        <?php /* Le « + » de la prescription à droite du titre (2026-11-04). */ ?>
                        <span class="care-title">
                            <span class="item-label"><?= View::e($item['label']) ?></span>
                            <?= View::render('components/prescription_details', ['item' => $item]) ?>
                        </span>
                        <?php if ($item['details'] !== null) : ?>
                            <span class="item-details"><?= View::e($item['details']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= View::e($item['responsible']) ?></td>
                    <td class="col-status">
                        <?= View::render('components/status_badge', ['status' => $item['status']]) ?>
                        <?php if ($item['notValidated'] ?? false) : ?>
                            <?= View::render('components/status_badge', [
                                'status' => 'to_validate',
                                'label' => 'Ordonnance non validée',
                            ]) ?>
                        <?php endif; ?>
                        <?php if ($item['completedAt'] !== null) : ?>
                            <span class="trace">à <?= View::e($item['completedAt']) ?></span>
                        <?php endif; ?>
                        <?php if (($item['performedBy'] ?? null) !== null) : ?>
                            <?php /* Traçabilité (RG10) : qui a validé, lu dans l'historique. */ ?>
                            <span class="trace">par <?= View::e($item['performedBy']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item['comment'] !== null) : ?>
                            <span class="note"><?= View::e($item['comment']) ?></span>
                            <?php if (($item['noteBy'] ?? null) !== null) : ?>
                                <span class="trace">par <?= View::e($item['noteBy']) ?></span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="sr-only">Aucune note</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($validationEnabled) : ?>
                        <td class="col-action">
                            <?= View::render('components/care_buttons', [
                                'item' => $item,
                                'idEpisodeOfCare' => $idEpisodeOfCare,
                            ]) ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
