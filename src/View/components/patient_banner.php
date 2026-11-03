<?php

use Aura\Core\View;

/**
 * Bandeau identité + allergies (RG2). À inclure en tête de TOUTE page liée à un séjour
 * (dossier, prescriptions, agenda) : View::render('components/patient_banner', ['banner' => …]).
 * Il reste collé en haut de l'écran (position: sticky) et ne se replie jamais : les allergies
 * doivent rester visibles pendant toute la consultation du séjour. Sa hauteur est exposée en variable
 * CSS par public/js/layout.js. Inclus par pages/episode/record.php, pages/episode/patient-agenda.php,
 * pages/careplan/list.php et pages/careplan/form.php.
 *
 * @var array<string, mixed> $banner vue-modèle de PatientRecordService::presentBanner() (lastName,
 *      firstName, sex, deceased, deceasedLabel, birthDate, age, ipp, episodeNumber, admissionDate, weight,
 *      height, bloodType, referent, allergies [label, criticality, level], allergiesStatus)
 */
?>
<header class="patient-banner" role="region" aria-label="Identité du patient et allergies connues">
    <div class="identity">
        <span class="last-name"><?= View::e($banner['lastName']) ?></span>
        <span class="first-name"><?= View::e($banner['firstName']) ?></span>
        <?php if ($banner['sex'] !== null) : ?>
            <span class="sex">(<?= View::e($banner['sex']) ?>)</span>
        <?php endif; ?>
        <?php /* Décès (Patient.deceased) : icône + texte, en tête de toute page liée au séjour. */ ?>
        <?php if (!empty($banner['deceased'])) : ?>
            <?= View::render('components/deceased_tag', ['label' => $banner['deceasedLabel'] ?? null]) ?>
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
        <?php /* Médecin référent du séjour (responsable de soin). Absent = « à désigner » (admission de
                 garde) : mis en évidence par l'icône, le texte et le cadre, pas par la couleur seule. */ ?>
        <div class="banner-referent">
            <dt>Référent</dt>
            <dd>
                <?php if (($banner['referent'] ?? null) !== null) : ?>
                    <?= View::e($banner['referent']) ?>
                <?php else : ?>
                    <span class="referent-missing">
                        <?= View::render('components/icon', ['name' => 'circle-alert']) ?>
                        à désigner
                    </span>
                <?php endif; ?>
            </dd>
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
