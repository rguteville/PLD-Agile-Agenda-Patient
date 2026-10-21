<?php

use Aura\Core\View;

$type     = $field['type'] ?? 'text';
$name     = $field['name'];
$label    = $field['label'] ?? ucfirst($name);
$required = !empty($field['required']);
$value    = $field['value'] ?? '';
$error    = $field['error'] ?? '';
$errorId  = $name . '-error';
// Attributs d'accessibilité quand le champ est en erreur (lecteurs d'écran)
$ariaError = $error !== '' ? ' aria-invalid="true" aria-describedby="' . View::e($errorId) . '"' : '';
// Attributs HTML supplémentaires (min, step, maxlength…), échappés : ['step' => '0.1']
$attributes = '';
foreach ($field['attributes'] ?? [] as $attrName => $attrValue) {
    $attributes .= ' ' . View::e($attrName) . '="' . View::e($attrValue) . '"';
}
// Bouton d'action à côté d'un champ texte (AURA-15) : ['id' => 'ipp-rechercher', 'label' => 'Rechercher']
$button = $field['button'] ?? null;
// Identifiant d'une zone de message sous le champ, mise à jour par script (annoncée aux lecteurs d'écran)
$status = $field['status'] ?? null;
?>
<div class="form-row<?= $error !== '' ? ' has-error' : '' ?>">
    <label<?= $type !== 'radio' ? ' for="' . View::e($name) . '"' : '' ?>><?= View::e($label) ?></label>
    <span class="req"><?= $required ? '*' : '' ?></span>

    <?php if ($type === 'radio') : ?>
        <div class="radio-group">
            <?php foreach ($field['options'] as $optValue => $optLabel) : ?>
                <label>
                    <input type="radio" name="<?= View::e($name) ?>" value="<?= View::e($optValue) ?>"
                        <?= (string) $optValue === (string) $value ? 'checked' : '' ?><?= $ariaError ?>>
                    <?= View::e($optLabel) ?>
                </label>
            <?php endforeach; ?>
        </div>

    <?php elseif ($type === 'select') : ?>
        <select id="<?= View::e($name) ?>" name="<?= View::e($name) ?>"
            <?= $required ? 'required' : '' ?><?= $ariaError ?>>
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
