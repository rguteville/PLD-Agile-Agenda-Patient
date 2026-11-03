<?php

use Aura\Core\View;

/**
 * Une ligne de soin compacte (agenda du service, refonte du 2026-11-04) : heure, patient et décès
 * (facultatif), soin avec le « + » de sa prescription à droite du titre, dose, trace et note, statut,
 * actions. Pas d'allergies ici depuis le 2026-11-04 : elles sont dans le panneau du patient choisi
 * (pages/service/consolidated-view.php). Jamais la couleur seule : le statut a son badge (icône et texte).
 * Inclus par pages/service/consolidated-view.php : panneau de détail d'un lit et liste de travail.
 *
 * @var array<string, mixed> $item soin de AgendaService::presentBoard() (champs de presentItem(), plus
 *      idEpisodeOfCare, bed, patient, deceased)
 * @var bool $showPatient affiche « Lit N, NOM Prénom » (liste de travail), false par défaut (panneau d'un lit)
 * @var bool $showStatus badge du statut, true par défaut (false dans une section de la liste de travail
 *      dont le titre le dit déjà)
 * @var bool $validationEnabled boutons Valider / Note (components/care_buttons), false par défaut
 * @var string $agendaUrl lien vers l'agenda du patient pour ce jour (lu seulement si $showPatient)
 */
$showPatient = $showPatient ?? false;
$showStatus = $showStatus ?? true;
$validationEnabled = $validationEnabled ?? false;
$who = ($item['bed'] !== null ? 'Lit ' . $item['bed'] . ', ' : '') . $item['patient'];
?>
<li class="care-line is-<?= View::e($item['status']) ?>" data-appointment-row
    data-status="<?= View::e($item['status']) ?>" data-care-label="<?= View::e($item['label']) ?>">
    <time class="care-line__time" datetime="<?= View::e($item['datetime']) ?>"><?= View::e($item['time']) ?></time>
    <?php if ($showPatient) : ?>
        <span class="care-line__patient">
            <a href="<?= View::e($agendaUrl) ?>"><?= View::e($who) ?></a>
            <?php if (($item['deceased'] ?? null) !== null) : ?>
                <?= View::render('components/deceased_tag', ['label' => $item['deceased']]) ?>
            <?php endif; ?>
        </span>
    <?php endif; ?>
    <span class="care-line__care">
        <?php /* Le « + » de la prescription à droite du titre (2026-11-04). */ ?>
        <span class="care-title">
            <span class="item-label"><?= View::e($item['label']) ?></span>
            <?= View::render('components/prescription_details', ['item' => $item]) ?>
        </span>
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
        <?php if ($validationEnabled) : ?>
            <?= View::render('components/care_buttons', [
                'item' => $item,
                'idEpisodeOfCare' => $item['idEpisodeOfCare'],
                'context' => $who,
            ]) ?>
        <?php endif; ?>
    </span>
</li>
