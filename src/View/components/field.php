<?php

use Aura\Core\View;

$type     = $field['type'] ?? 'text';
$name     = $field['name'];
$label    = $field['label'] ?? ucfirst($name);
$required = !empty($field['required']);
$value    = $field['value'] ?? '';
$error    = $field['error'] ?? '';
$errorId  = $name . '-error';
// Aide contextuelle courte sous le libellé (formulaires longs) : reliée au champ par aria-describedby.
$help     = $field['help'] ?? '';
$helpId   = $name . '-help';
// Attributs d'accessibilité : aria-describedby cumule l'aide et le message d'erreur (lecteurs d'écran).
$describedBy = array_filter([$help !== '' ? $helpId : null, $error !== '' ? $errorId : null]);
$ariaError = ($error !== '' ? ' aria-invalid="true"' : '')
    . ($describedBy !== [] ? ' aria-describedby="' . View::e(implode(' ', $describedBy)) . '"' : '');
// Attributs HTML supplémentaires (min, step, maxlength…), échappés : ['step' => '0.1']
$attributes = '';
foreach ($field['attributes'] ?? [] as $attrName => $attrValue) {
    $attributes .= ' ' . View::e($attrName) . '="' . View::e($attrValue) . '"';
}
// Bouton d'action à côté d'un champ texte (AURA-15) : ['id' => 'ipp-rechercher', 'label' => 'Rechercher']
$button = $field['button'] ?? null;
// Identifiant d'une zone de message sous le champ, mise à jour par script (annoncée aux lecteurs d'écran)
$status = $field['status'] ?? null;
// Liste déroulante : libellé du choix vide (« Non renseigné ») si le champ est facultatif
$empty = $field['empty'] ?? null;
// Liste à cases (type checkbox-group) : $value est la liste des valeurs cochées
$checked = is_array($value) ? array_map('strval', $value) : [];
$isGroup = $type === 'radio' || $type === 'checkbox-group';
?>
<div class="form-row form-row--<?= View::e($type) ?><?= $error !== '' ? ' has-error' : '' ?>">
    <?php if ($isGroup) : ?>
        <span class="form-label" id="<?= View::e($name) ?>-label"><?= View::e($label) ?></span>
    <?php else : ?>
        <label for="<?= View::e($name) ?>"><?= View::e($label) ?></label>
    <?php endif; ?>
    <span class="req"><?= $required ? '*' : '' ?></span>
    <?php if ($help !== '') : ?>
        <p class="field-help" id="<?= View::e($helpId) ?>"><?= View::e($help) ?></p>
    <?php endif; ?>

    <?php if ($type === 'radio') : ?>
        <div class="radio-group" role="radiogroup" aria-labelledby="<?= View::e($name) ?>-label">
            <?php foreach ($field['options'] as $optValue => $optLabel) : ?>
                <label>
                    <input type="radio" name="<?= View::e($name) ?>" value="<?= View::e($optValue) ?>"
                        <?= (string) $optValue === (string) $value ? 'checked' : '' ?><?= $ariaError ?>>
                    <?= View::e($optLabel) ?>
                </label>
            <?php endforeach; ?>
        </div>

    <?php elseif ($type === 'checkbox-group') : ?>
        <?php if (($field['options'] ?? []) === []) : ?>
            <p class="field-help field-help--warning">
                <?= View::e($field['emptyMessage'] ?? 'Aucune valeur disponible.') ?>
            </p>
        <?php else : ?>
            <div class="checkbox-group" role="group" aria-labelledby="<?= View::e($name) ?>-label">
                <?php foreach ($field['options'] as $optValue => $optLabel) : ?>
                    <label>
                        <input type="checkbox" name="<?= View::e($name) ?>[]" value="<?= View::e($optValue) ?>"
                            <?= in_array((string) $optValue, $checked, true) ? 'checked' : '' ?><?= $ariaError ?>>
                        <?= View::e($optLabel) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($type === 'select') : ?>
        <select id="<?= View::e($name) ?>" name="<?= View::e($name) ?>"
            <?= $required ? 'required' : '' ?><?= $attributes ?><?= $ariaError ?>>
            <?php if ($empty !== null) : ?>
                <option value=""<?= (string) $value === '' ? ' selected' : '' ?>><?= View::e($empty) ?></option>
            <?php endif; ?>
            <?php foreach ($field['options'] as $optValue => $optLabel) : ?>
                <option value="<?= View::e($optValue) ?>"
                    <?= (string) $optValue === (string) $value ? 'selected' : '' ?>>
                    <?= View::e($optLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>

    <?php elseif ($type === 'textarea') : ?>
        <textarea id="<?= View::e($name) ?>" name="<?= View::e($name) ?>"
            <?= $required ? 'required' : '' ?><?= $attributes ?><?= $ariaError ?>><?= View::e($value) ?></textarea>

    <?php else : ?>
        <?php if ($button !== null) : ?>
            <div class="field-group">
        <?php endif; ?>
        <input type="<?= View::e($type) ?>" id="<?= View::e($name) ?>" name="<?= View::e($name) ?>"
            value="<?= View::e($value) ?>" <?= $required ? 'required' : '' ?><?= $attributes ?><?= $ariaError ?>>
        <?php if ($button !== null) : ?>
                <button type="button" class="field-button" id="<?= View::e($button['id']) ?>">
                    <?= View::e($button['label']) ?>
                </button>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($error !== '') : ?>
        <p class="field-error" id="<?= View::e($errorId) ?>" role="alert"><?= View::e($error) ?></p>
    <?php endif; ?>
    <?php if ($status !== null) : ?>
        <div class="field-status" id="<?= View::e($status) ?>" aria-live="polite"></div>
    <?php endif; ?>
</div>
