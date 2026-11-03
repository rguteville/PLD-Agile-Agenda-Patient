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
$champs = [];
foreach ($fields as $key => $spec) {
    $type = $spec['type'];
    $champ = [
        'name' => $key,
        'id' => $part . '-' . $key,
        'label' => $spec['label'],
        'type' => in_array($type, ['tel', 'email'], true) ? $type : ($type === 'number' ? 'text' : $type),
        'value' => $values[$key] ?? '',
        'error' => '',
        'required' => !empty($spec['required']),
    ];
    if (isset($spec['placeholder'])) {
        $champ['placeholder'] = $spec['placeholder'];
    }
    if (isset($spec['help'])) {
        $champ['help'] = $spec['help'];
    }
    if ($type === 'select') {
        $champ['options'] = $spec['options'] ?? [];
        $champ['empty'] = 'Non renseigné';
    } elseif ($type === 'radio') {
        $champ['options'] = $spec['options'] ?? [];
    } elseif ($type === 'textarea') {
        $champ['attributes'] = ['rows' => '3'] + (isset($spec['max']) ? ['maxlength' => (string) $spec['max']] : []);
    } elseif ($type === 'number') {
        $champ['attributes'] = ['inputmode' => 'decimal'];
    } elseif (isset($spec['max'])) {
        $champ['attributes'] = ['maxlength' => (string) $spec['max']];
    }
    $champs[] = $champ;
}
?>
<form class="part-form" data-part="<?= View::e($part) ?>" data-title="<?= View::e($title) ?>"
      data-old="<?= View::e(json_encode($values, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)) ?>" novalidate hidden>
    <p class="form-error" role="alert" data-part-error hidden></p>
    <?php foreach ($champs as $champ) : ?>
        <?= View::render('components/field', ['field' => $champ]) ?>
    <?php endforeach; ?>
    <div class="part-form__actions">
        <button type="submit" class="button">Enregistrer</button>
        <button type="button" class="button button--secondary" data-part-cancel>Annuler</button>
    </div>
</form>
