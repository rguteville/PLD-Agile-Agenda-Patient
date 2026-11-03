<?php

use Aura\Core\View;

// Formulaire AJAX (form.js). Paramètres : id, action, title, submit, error, fields (champs visibles)
// et, pour un formulaire long, sections : liste de ['id', 'title', 'fields', 'filled', 'total'],
// chacune rendue en <details> repliée par défaut, avec le nombre de champs renseignés dans l'en-tête
// (mis à jour par form-sections.js). Une section est ouverte si l'un de ses champs est en erreur.
// lead : champs rendus sur toute la largeur avant le reste (ex. recherche d'un patient existant).
// Avec des sections, le formulaire est en deux colonnes sur grand écran : fields à gauche,
// sections à droite ; une seule colonne sous 1100 px.
$sections = $sections ?? [];
$lead = $lead ?? [];
?>

<form id="<?= View::e($id ?? 'form') ?>" action="<?= View::e($action) ?>" method="post" data-ajax
    <?= $sections !== [] ? 'class="form--columns"' : '' ?>>
    <?php if (!empty($title)) : ?>
        <h1 class="form-title"><?= View::e($title) ?></h1>
    <?php endif; ?>
    <?php if (!empty($error)) : ?>
        <p class="form-error" role="alert"><?= View::e($error) ?></p>
    <?php endif; ?>

    <?php if ($lead !== []) : ?>
        <?php /* Bloc de tête (recherche du patient) : sur grand écran, ses champs sont côte à côte. */ ?>
    <div class="form-lead">
        <?php foreach ($lead as $field) : ?>
            <?= View::render('components/field', ['field' => $field]) ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($sections !== []) : ?>
    <div class="form-columns">
    <div class="form-columns__main">
    <?php endif; ?>
    <?php foreach ($fields as $field) : ?>
        <?= View::render('components/field', ['field' => $field]) ?>
    <?php endforeach; ?>
    <?php if ($sections !== []) : ?>
    </div>
    <div class="form-columns__more">
    <?php endif; ?>

    <?php foreach ($sections as $section) : ?>
        <?php $failing = array_filter($section['fields'], static fn (array $f): bool => ($f['error'] ?? '') !== ''); ?>
        <details class="form-section" id="section-<?= View::e($section['id']) ?>"
                 data-form-section<?= $failing !== [] ? ' open' : '' ?>>
            <summary>
                <span class="form-section__title"><?= View::e($section['title']) ?></span>
                <span class="form-section__count" data-section-count
                      data-total="<?= View::e($section['total']) ?>">
                    <?= View::e($section['filled']) ?> sur <?= View::e($section['total']) ?>
                    renseigné<?= $section['filled'] > 1 ? 's' : '' ?>
                </span>
            </summary>
            <div class="form-section__body">
                <?php foreach ($section['fields'] as $field) : ?>
                    <?= View::render('components/field', ['field' => $field]) ?>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
    <?php if ($sections !== []) : ?>
    </div>
    </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit"><?= View::e($submit ?? 'Enregistrer') ?></button>
    </div>
</form>
