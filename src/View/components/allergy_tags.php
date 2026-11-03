<?php

use Aura\Core\View;

/**
 * Allergies d'un patient (RG2) en étiquettes, avec leur criticité ; « Aucune allergie connue » explicite.
 * null = allergies non lues (pas de source) : rien n'est affiché plutôt qu'une fausse absence.
 * Inclus seulement par le panneau du patient choisi de pages/service/consolidated-view.php (agenda du
 * service) : depuis le 2026-11-04, ni le tableau des lits ni la liste de travail n'affichent les allergies.
 *
 * @var list<array{label: string, level: string}>|null $allergies allergies du patient
 *      (AgendaService::getAgendaService()), level : high, low ou unknown ; null par défaut
 * @var bool $compact petites étiquettes ; false par défaut (aucun appelant ne le passe aujourd'hui)
 */
$allergies = $allergies ?? null;
if (!is_array($allergies)) {
    return;
}
$compact = $compact ?? false;
?>
<span class="service-patient__allergies allergy-tags<?= $compact ? ' allergy-tags--compact' : '' ?>">
    <?php if ($allergies === []) : ?>
        <span class="allergy-tag allergy-tag--none">Aucune allergie connue</span>
    <?php else : ?>
        <span class="sr-only">Allergies :</span>
        <?php foreach ($allergies as $allergy) : ?>
            <span class="allergy-tag allergy-tag--<?= View::e($allergy['level']) ?>"><?=
                View::e($allergy['label']) ?></span>
        <?php endforeach; ?>
    <?php endif; ?>
</span>
