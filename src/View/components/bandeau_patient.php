<?php

use Aura\Core\View;

/**
 * Bandeau identité + allergies (RG2). À inclure en tête de TOUTE page liée à un séjour
 * (dossier, prescriptions, agenda) : View::render('components/bandeau_patient', ['bandeau' => …]).
 *
 * @var array<string, mixed> $bandeau vue-modèle de DossierPatientService::presenterBandeau()
 */
?>
<header class="bandeau-patient" role="region" aria-label="Identité du patient et allergies connues">
    <div class="identite">
        <span class="nom"><?= View::e($bandeau['nom']) ?></span>
        <span class="prenom"><?= View::e($bandeau['prenom']) ?></span>
        <?php if ($bandeau['sexe'] !== null) : ?>
            <span class="sexe">(<?= View::e($bandeau['sexe']) ?>)</span>
        <?php endif; ?>
    </div>
    <dl>
        <div>
            <dt>Né(e) le</dt>
            <dd>
                <?= View::e($bandeau['dateNaissance'] ?? 'non renseignée') ?>
                <?php if ($bandeau['age'] !== null) : ?>
                    (<?= View::e($bandeau['age']) ?> ans)
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt>IPP</dt>
            <dd><?= View::e($bandeau['ipp'] ?? 'sans IPP') ?></dd>
        </div>
        <div>
            <dt>Séjour</dt>
            <dd>
                n° <?= View::e($bandeau['numeroSejour']) ?>,
                admission le <?= View::e($bandeau['dateAdmission'] ?? '?') ?>
            </dd>
        </div>
    </dl>
    <div class="allergies">
        <?php if ($bandeau['allergies'] === []) : ?>
            <span class="aucune-allergie">Aucune allergie connue</span>
        <?php else : ?>
            <span class="alerte">Allergies :</span>
            <ul>
                <?php foreach ($bandeau['allergies'] as $allergie) : ?>
                    <li>
                        <?= View::e($allergie['libelle']) ?>
                        <?php if ($allergie['criticite'] !== null) : ?>
                            (<?= View::e($allergie['criticite']) ?>)
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</header>
