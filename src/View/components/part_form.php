<?php

use Aura\Core\View;

/**
 * Formulaire de modification d'une partie entière du dossier (retours du 2026-11-02), masqué jusqu'au
 * clic sur le crayon du titre (public/js/part-edit.js). « Enregistrer » ouvre la fenêtre de validation
 * par un médecin ; le serveur valide et trace (RecordSectionService). Les valeurs d'origine (data-old)
 * servent au contrôle de concurrence.
 *
 * @var string $part identifiant de la partie (summary, history…)
 * @var string $title libellé de la partie
 * @var array<string, array<string, mixed>> $fields RecordSectionService::partFields()
 * @var array<string, string> $values valeurs actuelles (RecordSectionService::currentValues())
 */
$formFields = [];
foreach ($fields as $key => $spec) {
    $type = $spec['type'];
    $formField = [
        'name' => $key,
        'id' => $part . '-' . $key,
        'label' => $spec['label'],
        'type' => in_array($type, ['tel', 'email'], true) ? $type : ($type === 'number' ? 'text' : $type),
        'value' => $values[$key] ?? '',
        'error' => '',
        'required' => !empty($spec['required']),
    ];
    if (isset($spec['placeholder'])) {
        $formField['placeholder'] = $spec['placeholder'];
    }
    if (isset($spec['help'])) {
        $formField['help'] = $spec['help'];
    }
    if ($type === 'select') {
        $formField['options'] = $spec['options'] ?? [];
        $formField['empty'] = 'Non renseigné';
    } elseif ($type === 'radio') {
        $formField['options'] = $spec['options'] ?? [];
    } elseif ($type === 'textarea') {
        $formField['attributes'] = ['rows' => '3']
            + (isset($spec['max']) ? ['maxlength' => (string) $spec['max']] : []);
    } elseif ($type === 'number') {
        $formField['attributes'] = ['inputmode' => 'decimal'];
    } elseif (isset($spec['max'])) {
        $formField['attributes'] = ['maxlength' => (string) $spec['max']];
    }
    $formFields[] = $formField;
}
?>
<form class="part-form" data-part="<?= View::e($part) ?>" data-title="<?= View::e($title) ?>"
      data-old="<?= View::e(json_encode($values, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)) ?>" novalidate hidden>
    <p class="form-error" role="alert" data-part-error hidden></p>
    <?php foreach ($formFields as $formField) : ?>
        <?= View::render('components/field', ['field' => $formField]) ?>
    <?php endforeach; ?>
    <div class="part-form__actions">
        <button type="submit" class="button">Enregistrer</button>
        <button type="button" class="button button--secondary" data-part-cancel>Annuler</button>
    </div>
</form>
