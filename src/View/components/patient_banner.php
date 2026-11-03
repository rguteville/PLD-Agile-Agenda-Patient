<?php

use Aura\Core\View;

/**
 * Bandeau identité + allergies (RG2). À inclure en tête de TOUTE page liée à un séjour
 * (dossier, prescriptions, agenda) : View::render('components/patient_banner', ['bandeau' => …]).
 * Il reste collé en haut de l'écran (position: sticky) et ne se replie jamais : les allergies
 * doivent rester visibles pendant toute la consultation du séjour.
 *
 * @var array<string, mixed> $bandeau vue-modèle de DossierPatientService::presenterBandeau()
 */
?>
<header class="patient-banner" role="region" aria-label="Identité du patient et allergies connues">
    <div class="identity">
        <span class="last-name"><?= View::e($banner['lastName']) ?></span>
        <span class="prenom"><?= View::e($banner['firstName']) ?></span>
        <?php if ($banner['sex'] !== null) : ?>
            <span class="sex">(<?= View::e($banner['sex']) ?>)</span>
        <?php endif; ?>
    </div>
    <dl>
        <div>
            <dt>Né(e) le</dt>
            <dd>
                <?= View::e($banner['birthDate'] ?? 'non renseignée') ?>
                <?php if ($banner['age'] !== null) : ?>
                    (<?= View::e($banner['age']) ?> ans)
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt>IPP</dt>
            <dd><?= View::e($banner['ipp'] ?? 'sans IPP') ?></dd>
        </div>
        <div>
            <dt>Séjour</dt>
            <dd>
                n° <?= View::e($banner['episodeNumber']) ?>,
                admission le <?= View::e($banner['admissionDate'] ?? '?') ?>
            </dd>
        </div>
        <div>
            <dt>Poids</dt>
            <dd><?= View::e($banner['weight'] ?? 'non renseigné') ?></dd>
        </div>
        <div>
            <dt>Taille</dt>
            <dd><?= View::e($banner['height'] ?? 'non renseignée') ?></dd>
        </div>
        <div>
            <dt>Groupe sanguin</dt>
            <dd><?= View::e($banner['bloodType'] ?? 'non renseigné') ?></dd>
        </div>
    </dl>
    <?php
    // Trois états distincts (identitovigilance) : allergies connues (rouge), « Aucune allergie connue »
    // (vert : vérifié, aucune) et « Allergies non renseignées » (orange : l'information manque).
    // Le service fournit allergiesStatus ; à défaut, l'absence de liste vaut « aucune connue ».
    $allergiesStatus = $banner['allergiesStatus'] ?? ($banner['allergies'] === [] ? 'none' : 'known');
    ?>
    <div class="allergies allergies--<?= View::e($allergiesStatus) ?>">
        <?php if ($allergiesStatus === 'unknown') : ?>
            <span class="unknown-allergies">
                <?= View::render('components/icon', ['name' => 'circle-alert']) ?>
                Allergies non renseignées
            </span>
        <?php elseif ($banner['allergies'] === []) : ?>
            <?php /* État distinct, pas un bandeau vide : « aucune connue » n'est pas « non renseigné ». */ ?>
            <span class="no-allergy">
                <?= View::render('components/icon', ['name' => 'circle-check']) ?>
                Aucune allergie connue
            </span>
        <?php else : ?>
            <span class="alert">
                <?= View::render('components/icon', ['name' => 'triangle-alert']) ?>
                Allergies :
            </span>
            <ul>
                <?php foreach ($banner['allergies'] as $allergy) : ?>
                    <?php /* Couleur selon la criticité : rouge plein = élevée, orange = faible,
                             rouge pâle = non évaluée (le texte de la criticité est toujours affiché). */ ?>
                    <li class="allergy-tag allergy-tag--<?= View::e($allergy['level'] ?? 'unknown') ?>">
                        <?= View::e($allergy['label']) ?>
                        (<?= View::e($allergy['criticality'] ?? 'criticité non évaluée') ?>)
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</header>
