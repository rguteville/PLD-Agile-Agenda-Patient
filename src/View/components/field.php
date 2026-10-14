<?php

use Aura\Core\View;

$type     = $field['type'] ?? 'text';
$name     = $field['name'];
$label    = $field['label'] ?? ucfirst($name);
$required = !empty($field['required']);
$value    = $field['value'] ?? '';
?>
<div class="form-row">
    <label<?= $type !== 'radio' ? ' for="' . View::e($name) . '"' : '' ?>><?= View::e($label) ?></label>
    <span class="req"><?= $required ? '*' : '' ?></span>

    <?php if ($type === 'radio') : ?>
        <div class="radio-group">
            <?php foreach ($field['options'] as $optValue => $optLabel) : ?>
                <label>
                    <input type="radio" name="<?= View::e($name) ?>" value="<?= View::e($optValue) ?>"
                        <?= (string) $optValue === (string) $value ? 'checked' : '' ?>>
                    <?= View::e($optLabel) ?>
                </label>
            <?php endforeach; ?>
        </div>

    <?php elseif ($type === 'select') : ?>
        <select id="<?= View::e($name) ?>" name="<?= View::e($name) ?>" <?= $required ? 'required' : '' ?>>
            <?php foreach ($field['options'] as $optValue => $optLabel) : ?>
                <option value="<?= View::e($optValue) ?>" 
                    <?= (string) $optValue === (string) $value ? 'selected' : '' ?>>
                    <?= View::e($optLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>

    <?php else : ?>
        <input type="<?= View::e($type) ?>" id="<?= View::e($name) ?>" name="<?= View::e($name) ?>"
            value="<?= View::e($value) ?>" <?= $required ? 'required' : '' ?>>
    <?php endif; ?>
</div>
