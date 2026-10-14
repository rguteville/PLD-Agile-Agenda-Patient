<?php

use Aura\Core\View;

/**
 * @var array<string, mixed> $bandeau
 * @var array<string, mixed> $sejour
 */
?>
<?= View::render('components/bandeau_patient', ['bandeau' => $bandeau]) ?>

<section class="dossier">
    <h1 class="form-title">Dossier du séjour n° <?= View::e($bandeau['numeroSejour']) ?></h1>
    <dl>
        <dt>Statut</dt>
        <dd><?= $sejour['enCours'] ? 'En cours' : 'Clos le ' . View::e($sejour['dateSortie']) ?></dd>
        <dt>Admission</dt>
        <dd><?= View::e($bandeau['dateAdmission'] ?? 'non renseignée') ?></dd>
        <dt>Service</dt>
        <dd><?= View::e($sejour['service'] ?? 'non renseigné') ?></dd>
        <dt>Lit</dt>
        <dd><?= View::e($sejour['lit'] ?? 'non attribué') ?></dd>
        <dt>Box</dt>
        <dd><?= View::e($sejour['box'] ?? 'non attribué') ?></dd>
        <dt>Téléphone</dt>
        <dd><?= View::e($sejour['telephone'] ?? 'non renseigné') ?></dd>
        <dt>E-mail</dt>
        <dd><?= View::e($sejour['email'] ?? 'non renseigné') ?></dd>
    </dl>
    <p class="a-venir">Allergies (AURA-13), prescriptions (AURA-20) et agenda (AURA-25) viendront s'afficher ici.</p>
</section>
