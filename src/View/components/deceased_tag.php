<?php

use Aura\Core\View;

/**
 * Mention du décès du patient (retours des médecins du 2026-11-03) : icône + texte, jamais la couleur
 * seule. Bandeau, dossier et liste des patients.
 *
 * Usage : View::render('components/deceased_tag', ['label' => 'Décédée'])
 *
 * @var string|null $label libellé accordé (PatientRecordService::deceasedLabel()), « Décédé(e) » par défaut
 */
?>
<span class="deceased-tag">
    <?= View::render('components/icon', ['name' => 'heart-off']) ?>
    <span><?= View::e($label ?? 'Décédé(e)') ?></span>
</span>
