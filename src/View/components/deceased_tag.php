<?php

use Aura\Core\View;

/**
 * Mention du décès du patient (retours des médecins du 2026-11-03) : icône + texte, jamais la couleur
 * seule. Bandeau, dossier et liste des patients.
 *
 * Usage : View::render('components/deceased_tag', ['label' => 'Décédée'])
 *
 * @var string|null $label libellé accordé (PatientRecordService::deceasedLabel()), « Décédé(e) » par défaut
 * @var bool $iconOnly cœur barré seul, à côté du nom (liste des patients, 2026-11-04) : le libellé devient le
 *                     texte alternatif de l'icône et son infobulle, il reste annoncé aux lecteurs d'écran
 */
$label ??= 'Décédé(e)';
?>
<?php if ($iconOnly ?? false) : ?>
<span class="deceased-tag deceased-tag--icon" title="<?= View::e($label) ?>">
    <?= View::render('components/icon', ['name' => 'heart-off', 'label' => $label]) ?>
</span>
<?php else : ?>
<span class="deceased-tag">
    <?= View::render('components/icon', ['name' => 'heart-off']) ?>
    <span><?= View::e($label) ?></span>
</span>
<?php endif; ?>
