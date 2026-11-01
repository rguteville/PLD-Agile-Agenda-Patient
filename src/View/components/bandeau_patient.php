<?php

use Aura\Core\View;

/**
 * Bandeau identité + allergies (RG2). À inclure en tête de TOUTE page liée à un séjour
 * (dossier, prescriptions, agenda) : View::render('components/bandeau_patient', ['bandeau' => …]).
 * Il reste collé en haut de l'écran (position: sticky) et ne se replie jamais : les allergies
 * doivent rester visibles pendant toute la consultation du séjour.
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
        <div>
            <dt>Poids</dt>
            <dd><?= View::e($bandeau['poids'] ?? 'non renseigné') ?></dd>
        </div>
        <div>
            <dt>Taille</dt>
            <dd><?= View::e($bandeau['taille'] ?? 'non renseignée') ?></dd>
        </div>
    </dl>
    <div class="allergies">
        <?php if ($bandeau['allergies'] === []) : ?>
            <?php /* État distinct, pas un bandeau vide : « aucune connue » n'est pas « non renseigné ». */ ?>
            <span class="aucune-allergie">
                <?= View::render('components/icon', ['name' => 'circle-check']) ?>
                Aucune allergie connue
            </span>
        <?php else : ?>
            <span class="alerte">
                <?= View::render('components/icon', ['name' => 'triangle-alert']) ?>
                Allergies :
            </span>
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
