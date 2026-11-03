<?php

use Aura\Core\View;

/**
 * Une ligne de soin compacte (agenda du service, refonte du 2026-11-04) : heure, patient (facultatif),
 * soin et dose, statut, « + » de la prescription, actions. Utilisée par le panneau de détail d'un lit et
 * par la liste de travail de la rotation. Jamais la couleur seule : le statut a son badge (icône et texte).
 *
 * @var array<string, mixed> $item soin de AgendaService::presentBoard() (champs de presentItem() + patient)
 * @var bool $showPatient affiche « Lit · NOM Prénom » (liste de travail) ; non dans le panneau d'un lit
 * @var bool $showStatus badge du statut (non dans une section de la liste de travail qui le dit déjà)
 * @var bool $validationEnabled boutons Valider / Note
 * @var string $agendaUrl lien vers l'agenda du patient pour ce jour
 */
$showPatient = $showPatient ?? false;
$showStatus = $showStatus ?? true;
$validationEnabled = $validationEnabled ?? false;
$who = ($item['bed'] !== null ? 'Lit ' . $item['bed'] . ' · ' : '') . $item['patient'];
?>
<li class="care-line is-<?= View::e($item['status']) ?>" data-appointment-row
    data-status="<?= View::e($item['status']) ?>" data-care-label="<?= View::e($item['label']) ?>">
    <time class="care-line__time" datetime="<?= View::e($item['datetime']) ?>"><?= View::e($item['time']) ?></time>
    <?php if ($showPatient) : ?>
        <a class="care-line__patient" href="<?= View::e($agendaUrl) ?>"><?= View::e($who) ?></a>
    <?php endif; ?>
    <span class="care-line__care">
        <span class="item-label"><?= View::e($item['label']) ?></span>
        <?php if ($item['details'] !== null) : ?>
            <span class="item-details"><?= View::e($item['details']) ?></span>
        <?php endif; ?>
        <?php if (($item['performedBy'] ?? null) !== null) : ?>
            <span class="trace">par <?= View::e($item['performedBy']) ?><?= ($item['completedAt'] ?? null) !== null
                ? ' à ' . View::e($item['completedAt']) : '' ?></span>
        <?php endif; ?>
        <?php if (($item['comment'] ?? null) !== null) : ?>
            <span class="trace"><?= View::e($item['comment']) ?></span>
        <?php endif; ?>
    </span>
    <span class="care-line__status">
        <?php if ($showStatus) : ?>
            <?= View::render('components/status_badge', ['status' => $item['status']]) ?>
        <?php endif; ?>
        <?php if ($item['notValidated'] ?? false) : ?>
            <?= View::render('components/status_badge', [
                'status' => 'to_validate',
                'label' => 'Ordonnance non validée',
            ]) ?>
        <?php endif; ?>
    </span>
    <span class="care-line__actions">
        <?= View::render('components/prescription_details', ['item' => $item]) ?>
        <?php if ($validationEnabled) : ?>
            <?= View::render('components/care_buttons', [
                'item' => $item,
                'idEpisodeOfCare' => $item['idEpisodeOfCare'],
                'context' => $who,
            ]) ?>
        <?php endif; ?>
    </span>
</li>
