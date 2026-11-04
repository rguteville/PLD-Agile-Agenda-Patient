<?php

use Aura\Core\View;

/**
 * Formulaire POST marqué data-ajax (AURA-10.2), envoyé par public/js/form.js quand la page charge ce
 * script ; sinon POST classique. Inclus par pages/patient/admission.php et pages/patient/edit.php.
 * Pour un formulaire long, chaque section est rendue en <details> repliée par défaut, avec le nombre de
 * champs renseignés dans l'en-tête (mis à jour par form-sections.js) ; une section est ouverte si l'un de
 * ses champs est en erreur. Avec des sections, le formulaire est en deux colonnes sur grand écran :
 * fields à gauche, sections à droite ; une seule colonne sous 1100 px.
 *
 * @var string|null $id identifiant HTML du formulaire, « form » par défaut
 * @var string $action URL du POST
 * @var string|null $title titre h1, aucun par défaut
 * @var string|null $submit libellé du bouton d'envoi, « Enregistrer » par défaut
 * @var string|null $error erreur hors champ affichée à côté du bouton, aucune par défaut
 * @var list<array<string, mixed>> $fields champs visibles (components/field)
 * @var list<array{id: string, title: string, fields: list<array<string, mixed>>, filled: int, total: int}>
 *      $sections sections repliables, [] par défaut
 * @var list<array<string, mixed>> $lead champs rendus sur toute la largeur avant le reste (recherche d'un
 *      patient existant), [] par défaut
 * @var string|null $csrfToken jeton CSRF de la session (champ caché « csrf ») ; aucun champ si null
 */
$sections = $sections ?? [];
$lead = $lead ?? [];
?>

<form id="<?= View::e($id ?? 'form') ?>" action="<?= View::e($action) ?>" method="post" data-ajax
    <?= $sections !== [] ? 'class="form--columns"' : '' ?>>
    <?php if (($csrfToken ?? null) !== null) : ?>
        <input type="hidden" name="csrf" value="<?= View::e($csrfToken) ?>">
    <?php endif; ?>
    <?php if (!empty($title)) : ?>
        <h1 class="form-title"><?= View::e($title) ?></h1>
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
        <?php /* Erreur hors champ à côté du bouton (retours du 2026-11-03), comme form.js. */ ?>
        <?php if (!empty($error)) : ?>
            <p class="form-error" role="alert"><?= View::e($error) ?></p>
        <?php endif; ?>
    </div>
</form>
