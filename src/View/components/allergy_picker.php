<?php

use Aura\Core\View;

/**
 * Choix des allergies connues à l'admission (retours du 2026-11-02) : une liste filtrable des
 * allergènes du référentiel ; chaque allergène coché reçoit une criticité (non évaluée, faible,
 * élevée). Envoi : allergies[] (identifiants cochés) et allergyCriticality[id] (criticité).
 * Sans JavaScript, tout reste utilisable : le filtre et le compteur sont des conforts
 * (public/js/allergy-picker.js), la criticité s'affiche via CSS dès que la case est cochée.
 * Inclus par components/field.php (type « allergy-picker ») dans pages/patient/admission.php.
 *
 * @var string $name nom du champ (allergies)
 * @var array<string, string> $options idSubstance => libellé
 * @var list<string> $checked identifiants cochés
 * @var array<string, string> $criticalities idSubstance => criticité choisie ([] si aucune)
 * @var list<string> $criticalityOptions valeurs autorisées (AllergyIntolerance::CRITICALITIES)
 * @var string $ariaError attributs aria-invalid / aria-describedby déjà échappés ('' sans erreur)
 */
?>
<div class="allergy-picker" role="group" aria-labelledby="<?= View::e($name) ?>-label" data-allergy-picker>
    <div class="allergy-picker__toolbar">
        <label for="<?= View::e($name) ?>-filtre" class="sr-only">Filtrer les allergènes</label>
        <input type="search" id="<?= View::e($name) ?>-filtre" class="allergy-picker__filter"
               placeholder="Filtrer : pénicilline, latex, iode…" autocomplete="off" data-allergy-filter>
    </div>
    <ul class="allergy-picker__list">
        <?php foreach ($options as $id => $label) : ?>
            <?php $isChecked = in_array((string) $id, $checked, true); ?>
            <li class="allergy-option" data-allergy-label="<?= View::e($label) ?>">
                <label class="allergy-option__check">
                    <input type="checkbox" name="<?= View::e($name) ?>[]" value="<?= View::e($id) ?>"
                        <?= $isChecked ? 'checked' : '' ?><?= $ariaError ?>>
                    <span><?= View::e($label) ?></span>
                </label>
                <select name="allergyCriticality[<?= View::e($id) ?>]" class="allergy-option__criticality"
                        aria-label="Criticité de l'allergie : <?= View::e($label) ?>">
                    <option value="">Criticité non évaluée</option>
                    <?php foreach ($criticalityOptions as $criticality) : ?>
                        <option value="<?= View::e($criticality) ?>"
                            <?= ($criticalities[$id] ?? '') === $criticality ? 'selected' : '' ?>>
                            <?= View::e($criticality) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="allergy-picker__empty" data-allergy-empty hidden>Aucun allergène ne correspond au filtre.</p>
</div>
