<?php

use Aura\Core\View;
use Aura\Service\RecordSectionService;

/**
 * Dossier du séjour (AURA-14) et gestion des allergies connues du patient (AURA-13.2).
 *
 * @var array<string, mixed> $banner
 * @var array<string, mixed> $episodeOfCare
 * @var int $idEpisodeOfCare
 * @var list<array<string, mixed>> $prescriptions lignes validées, vue-modèle de CarePlanService::presentLine()
 * @var array<string, string> $doctors médecins pouvant arrêter une ligne : idPractitioner => « nom (rôle) »
 * @var list<array{idAllergy: ?int, label: string, criticality: ?string, recordedAt: ?string}> $allergies
 * @var array<string, string> $substances allergènes du référentiel non encore enregistrés, id => libellé
 * @var list<string> $criticalities
 * @var ?string $allergyError message d'un ajout ou d'un retrait refusé, null sinon
 * @var list<array<string, mixed>> $sections dossier complet, vue-modèle de PatientRecordService::presentSections()
 * @var bool $editable édition champ par champ active (inline-edit.js, endpoint /sejour/champ/modifier)
 * @var ?string $csrfToken jeton de session envoyé par le script dans X-CSRF-Token
 * @var list<array<string, mixed>> $history dernières modifications, vue-modèle de RecordEditService::history()
 * @var array<string, string> $referringDoctors médecins pouvant être référents : idPractitioner => « Dr Prénom Nom »
 * @var list<array<string, mixed>> $episodeHistory séjours du patient, vue-modèle de EpisodeHistoryService::present()
 * @var ?array<string, mixed> $currentEpisode séjour en cours du patient quand le séjour affiché est un autre
 *      (EpisodeHistoryService::presentCurrent()), null sinon
 */
$allergyError = $allergyError ?? null;
$sections = $sections ?? [];
$editable = $editable ?? false;
$csrfToken = $csrfToken ?? null;
$history = $history ?? [];
$partValues = $partValues ?? [];
$services = $services ?? [];
$referringDoctors = $referringDoctors ?? [];
$episodeHistory = $episodeHistory ?? [];
$currentEpisode = $currentEpisode ?? null;
// Crayon « Modifier » d'une partie entière (retours du 2026-11-02), à côté du titre de la partie.
$partEditButton = static fn (string $part, string $title): string =>
    '<button type="button" class="part-edit" data-part-edit="' . View::e($part) . '" aria-label="Modifier la partie : '
    . View::e($title) . '" title="Modifier toute la partie">'
    . View::render('components/icon', ['name' => 'pen-line']) . '<span>Modifier</span></button>';
// Contenu HTML (échappé) d'une ligne du dossier complet : valeur, ou état explicite si absente.
$lineValue = static function (array $row): string {
    if ($row['value'] !== null) {
        $html = View::e($row['value']);
    } else {
        $empty = $row['computed'] ? 'Non calculable' : ($row['emptyLabel'] ?? 'Non renseigné');
        $html = '<span class="not-recorded">' . View::e($empty) . '</span>';
    }
    if ($row['computed'] && $row['help'] !== null) {
        $html .= ' <small>' . View::e($row['help']) . '</small>';
    }

    return $html;
};
?>
<?= View::render('components/patient_banner', ['banner' => $banner]) ?>
<?= View::render('components/breadcrumb', ['items' => [
    ['label' => 'Patients', 'href' => '/patients'],
    ['label' => 'Dossier du séjour n° ' . $banner['episodeNumber']],
]]) ?>
<?php /* Retours des médecins du 2026-11-03 : dossier d'un séjour antérieur alors que le patient est
         hospitalisé, le séjour en cours est signalé et lié en tête de page. */ ?>
<?php if ($currentEpisode !== null) : ?>
    <aside class="current-episode-notice" aria-labelledby="current-episode-title">
        <?= View::render('components/icon', ['name' => 'info']) ?>
        <div>
            <p class="current-episode-notice__title" id="current-episode-title">
                Vous consultez un séjour antérieur : ce patient a un séjour en cours.
            </p>
            <p>
                Séjour n° <?= View::e($currentEpisode['episodeNumber']) ?>,
                lit <?= View::e($currentEpisode['bed'] ?? 'non attribué') ?>,
                <?= View::e($currentEpisode['service'] ?? 'service non renseigné') ?>,
                depuis le <?= View::e($currentEpisode['since']) ?>.
            </p>
        </div>
        <a class="button" href="/sejour?id=<?= View::e($currentEpisode['idEpisodeOfCare']) ?>">
            Ouvrir le séjour en cours
        </a>
    </aside>
<?php endif; ?>

<div class="record-layout">
<?php /* Menu figé à gauche (retours du 2026-11-02) : agenda en tête, puis les parties du dossier ; les
         sous-catégories du dossier complet ouvrent leur section (public/js/record-menu.js). */ ?>
<?php
$subParts = array_map(
    static fn (array $section): array => [
        'href' => '#dossier-' . $section['id'],
        'label' => $section['title'],
        'openDetails' => true,
    ],
    $sections
);
if ($editable) {
    $subParts[] = [
        'href' => '#dossier-historique',
        'label' => 'Historique des modifications',
        'openDetails' => true,
    ];
}
?>
<?= View::render('components/episode_menu', [
    'primary' => [
        'href' => '/agenda-patient?id=' . $idEpisodeOfCare,
        'label' => 'Agenda du jour',
        'icon' => 'calendar',
    ],
    'items' => [
        ['href' => '#dossier-resume', 'label' => 'Dossier'],
        ['href' => '#titre-allergies', 'label' => 'Allergies'],
        ['href' => '#titre-dossier-complet', 'label' => 'Dossier complet', 'children' => $subParts],
        ['href' => '#titre-sejours', 'label' => 'Séjours du patient'],
        ['href' => '#titre-prescriptions', 'label' => 'Prescriptions en cours'],
    ],
]) ?>

<section class="record">
    <div class="page-header" id="dossier-resume">
        <h1 class="form-title">Dossier du séjour n° <?= View::e($banner['episodeNumber']) ?></h1>
        <?php if ($editable) : ?>
            <p class="page-actions">
                <?= $partEditButton(RecordSectionService::PART_SUMMARY, 'Dossier') ?>
                <?php if ($episodeOfCare['ongoing']) : ?>
                    <?php /* Fin du séjour : validée par un médecin dans la même fenêtre que les modifications. */ ?>
                    <button type="button" class="button button--danger" data-end-stay>
                        Mettre fin au séjour
                    </button>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <dl class="record-summary" data-part-view="<?= RecordSectionService::PART_SUMMARY ?>">
        <dt>Patient</dt>
        <dd>
            <?= View::e(mb_strtoupper($banner['lastName']) . ' ' . $banner['firstName']) ?>
            <?php if ($banner['sex'] !== null) : ?>
                (<?= View::e($banner['sex']) ?>)
            <?php endif; ?>
            <?php if (!empty($banner['deceased'])) : ?>
                <?= View::render('components/deceased_tag', ['label' => $banner['deceasedLabel'] ?? null]) ?>
            <?php endif; ?>
        </dd>
        <dt>Né(e) le</dt>
        <dd><?= View::e($banner['birthDate'] ?? 'non renseignée') ?></dd>
        <dt>IPP</dt>
        <dd><?= View::e($banner['ipp'] ?? 'sans IPP') ?></dd>
        <dt>Statut</dt>
        <dd>
            <?php if ($episodeOfCare['ongoing']) : ?>
                <?= View::render('components/status_badge', ['status' => 'planned', 'label' => 'En cours']) ?>
            <?php else : ?>
                <?= View::render('components/status_badge', ['status' => 'stopped', 'label' => 'Clos']) ?>
                le <?= View::e($episodeOfCare['dischargeDate']) ?>
            <?php endif; ?>
        </dd>
        <dt>Admission</dt>
        <dd><?= View::e($banner['admissionDate'] ?? 'non renseignée') ?></dd>
        <dt>Service</dt>
        <dd><?= View::e($episodeOfCare['service'] ?? 'non renseigné') ?></dd>
        <dt>Médecin référent</dt>
        <dd>
            <?php if (($episodeOfCare['referent'] ?? null) !== null) : ?>
                <?= View::e($episodeOfCare['referent']) ?>
            <?php else : ?>
                <?php /* Mis en évidence par l'icône, le texte et le cadre, pas par la couleur seule. */ ?>
                <span class="referent-missing">
                    <?= View::render('components/icon', ['name' => 'circle-alert']) ?>
                    Référent à désigner
                </span>
            <?php endif; ?>
        </dd>
        <dt>Motif d'admission</dt>
        <dd><?= isset($episodeOfCare['admissionReason'])
            ? nl2br(View::e($episodeOfCare['admissionReason']))
            : 'non renseigné' ?></dd>
        <dt>Lit</dt>
        <dd><?= View::e($episodeOfCare['bed'] ?? 'non attribué') ?></dd>
        <dt>Box</dt>
        <dd><?= View::e($episodeOfCare['box'] ?? 'non attribué') ?></dd>
        <dt>Précautions</dt>
        <?php /* « non renseignées », pas « aucune » : rien de saisi ne veut pas dire aucun risque. */ ?>
        <dd><?= isset($episodeOfCare['precautions'])
            ? nl2br(View::e($episodeOfCare['precautions']))
            : 'non renseignées' ?></dd>
        <dt>Téléphone</dt>
        <dd><?= View::e($episodeOfCare['phone'] ?? 'non renseigné') ?></dd>
        <dt>E-mail</dt>
        <dd><?= View::e($episodeOfCare['email'] ?? 'non renseigné') ?></dd>
    </dl>
    <?php if ($editable) : ?>
        <?= View::render('components/part_form', [
            'part' => RecordSectionService::PART_SUMMARY,
            'title' => 'Dossier',
            'fields' => RecordSectionService::partFields(
                RecordSectionService::PART_SUMMARY,
                $services,
                $referringDoctors
            ),
            'values' => $partValues[RecordSectionService::PART_SUMMARY] ?? [],
        ]) ?>
    <?php endif; ?>

<section class="record-allergies" aria-labelledby="titre-allergies">
    <h2 id="titre-allergies">Allergies connues</h2>

    <?php if ($allergyError !== null) : ?>
        <p class="form-error" role="alert"><?= View::e($allergyError) ?></p>
    <?php endif; ?>

    <?php if ($allergies === []) : ?>
        <p class="no-allergy">
            <?= View::render('components/icon', ['name' => 'circle-check']) ?>
            Aucune allergie connue
        </p>
    <?php else : ?>
        <ul class="allergy-list">
            <?php foreach ($allergies as $allergy) : ?>
                <li>
                    <span class="label allergy-tag allergy-tag--<?= View::e($allergy['level'] ?? 'unknown') ?>"
                        ><?= View::e($allergy['label']) ?></span>
                    <?php
                    $details = $allergy['criticality'] !== null
                        ? 'criticité ' . $allergy['criticality']
                        : 'criticité non évaluée';
                    if ($allergy['recordedAt'] !== null) {
                        $details .= ', enregistrée le ' . $allergy['recordedAt'];
                    }
                    ?>
                    <span class="details"><?= View::e($details) ?></span>
                    <?php /* Retrait en POST, jamais en lien GET : un GET peut être suivi par un robot,
                             préchargé par le navigateur ou relancé depuis l'historique. */ ?>
                    <form method="post" action="/sejour/allergies/retirer" class="remove-allergy"
                          data-confirm="<?= View::e(sprintf(
                              'Retirer l\'allergie « %s » du dossier de ce patient ? Elle disparaîtra du bandeau.',
                              $allergy['label']
                          )) ?>">
                        <input type="hidden" name="idEpisodeOfCare" value="<?= View::e($idEpisodeOfCare) ?>">
                        <input type="hidden" name="idAllergy" value="<?= View::e($allergy['idAllergy']) ?>">
                        <button type="submit" aria-label="Retirer l'allergie <?= View::e($allergy['label']) ?>">
                            Retirer
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($substances === []) : ?>
        <p class="reference-exhausted">Tous les allergènes du référentiel sont déjà enregistrés pour ce patient.</p>
    <?php else : ?>
        <form method="post" action="/sejour/allergies/ajouter" class="add-allergy">
            <input type="hidden" name="idEpisodeOfCare" value="<?= View::e($idEpisodeOfCare) ?>">
            <div class="inline-field">
                <label for="idSubstance">Allergène</label>
                <select id="idSubstance" name="idSubstance" required>
                    <option value="">Choisir dans la liste</option>
                    <?php foreach ($substances as $id => $description) : ?>
                        <option value="<?= View::e($id) ?>"><?= View::e($description) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="inline-field">
                <label for="criticality">Criticité</label>
                <select id="criticality" name="criticality">
                    <option value="">Non évaluée</option>
                    <?php foreach ($criticalities as $criticality) : ?>
                        <option value="<?= View::e($criticality) ?>"><?= View::e($criticality) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Ajouter</button>
        </form>
    <?php endif; ?>
</section>

    <?php /* Dossier complet : mêmes sections que le formulaire d'admission. Une section vide reste
             visible (repliée) avec « Non renseigné » : l'information est absente, pas cachée. */ ?>
    <h2 class="record-subtitle" id="titre-dossier-complet">Dossier complet</h2>
    <?php if ($editable) : ?>
        <p class="record-help">Pour modifier une valeur, cliquez sur le crayon qui apparaît au survol
            (ou Entrée au clavier), corrigez-la puis cliquez sur « Confirmer ». Échap annule.</p>
    <?php endif; ?>
    <div class="record-sections" aria-labelledby="titre-dossier-complet"<?= $editable
        ? ' data-inline-edit data-endpoint="/sejour/champ/modifier" data-csrf="' . View::e($csrfToken) . '"'
            . ' data-id-episode-of-care="' . View::e($idEpisodeOfCare) . '"'
        : '' ?>>
    <?php /* Messages d'enregistrement et d'erreur annoncés aux lecteurs d'écran (aria-live). */ ?>
    <p class="inline-edit__status" id="inline-edit-status" aria-live="polite" hidden></p>
    <?php foreach ($sections as $section) : ?>
        <details class="record-section"
                 id="dossier-<?= View::e($section['id']) ?>"<?= $section['filled'] > 0 ? ' open' : '' ?>>
            <summary>
                <span class="record-section__title"><?= View::e($section['title']) ?></span>
                <?= $editable ? $partEditButton($section['id'], $section['title']) : '' ?>
                <span class="record-section__count">
                    <?= View::e($section['filled']) ?> sur <?= View::e($section['total']) ?>
                    renseigné<?= $section['filled'] > 1 ? 's' : '' ?>
                </span>
            </summary>
            <dl data-part-view="<?= View::e($section['id']) ?>">
                <?php foreach ($section['rows'] as $row) : ?>
                    <dt><?= View::e($row['label']) ?></dt>
                    <?php /* Décès : modifiable par « Modifier » sur la partie seulement (validé par un médecin). */ ?>
                    <?php if ($editable && !$row['computed'] && empty($row['validatedByDoctor'])) : ?>
                        <?php /* Bouton focalisable : Entrée ou clic passe en saisie (inline-edit.js). */ ?>
                        <dd class="inline-edit"
                            data-entity="<?= View::e($row['entity']) ?>"
                            data-id="<?= View::e($row['entity'] === 'patient'
                                     ? $episodeOfCare['idPatient']
                                     : $idEpisodeOfCare) ?>"
                            data-field="<?= View::e($row['field']) ?>"
                            data-type="<?= View::e($row['type']) ?>"
                            data-label="<?= View::e($row['label']) ?>"
                            data-raw="<?= View::e($row['raw'] ?? '') ?>"
                            data-placeholder="<?= View::e($row['placeholder'] ?? '') ?>"<?= $row['options'] !== null
                                ? ' data-options="'
                                    . View::e(json_encode($row['options'], JSON_UNESCAPED_UNICODE)) . '"'
                                : '' ?>>
                            <?php /* Crayon visible au survol ou au focus seulement. */ ?>
                            <button type="button" class="inline-edit__value"
                                    aria-label="Modifier : <?= View::e($row['label']) ?>"
                                    ><span class="inline-edit__text"><?= $lineValue($row) ?></span><?=
                                    View::render('components/icon', [
                                        'name' => 'pen-line',
                                        'class' => 'inline-edit__pencil',
                                    ]) ?></button>
                        </dd>
                    <?php else : ?>
                        <?php /* Valeur collée à la balise : pre-line conserve les retours à la ligne. */ ?>
                        <dd data-entity="<?= View::e($row['entity']) ?>"
                            data-field="<?= View::e($row['field']) ?>"><?= $lineValue($row) ?></dd>
                    <?php endif; ?>
                <?php endforeach; ?>
            </dl>
            <?php if ($editable) : ?>
                <?= View::render('components/part_form', [
                    'part' => $section['id'],
                    'title' => $section['title'],
                    'fields' => RecordSectionService::partFields($section['id'], $services, $referringDoctors),
                    'values' => $partValues[$section['id']] ?? [],
                ]) ?>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>

    <?php if ($editable) : ?>
        <?php /* Traçabilité : qui a modifié quoi, de quelle valeur à quelle valeur (table AuditEvent). */ ?>
        <details class="record-section record-history" id="dossier-historique">
            <summary>
                <span>Historique des modifications</span>
                <span class="record-section__count">
                    <?= count($history) ?> dernière<?= count($history) > 1 ? 's' : '' ?>
                </span>
            </summary>
            <?php if ($history === []) : ?>
                <p class="record-history__empty">Aucune modification enregistrée depuis le dossier.</p>
            <?php else : ?>
                <div class="table-scroll">
                <table class="record-history__table" id="dossier-historique-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Auteur</th>
                            <th scope="col">Champ</th>
                            <th scope="col">Ancienne valeur</th>
                            <th scope="col">Nouvelle valeur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $event) : ?>
                            <tr>
                                <td><?= View::e($event['recordedAt']) ?></td>
                                <td><?= View::e($event['author']) ?></td>
                                <td>
                                    <?= View::e($event['label']) ?>
                                    <?php if (($event['detail'] ?? null) !== null) : ?>
                                        <br><small><?= View::e($event['detail']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= $event['oldValue'] !== null
                                    ? View::e($event['oldValue'])
                                    : '<span class="not-recorded">vide</span>' ?></td>
                                <td><?= $event['newValue'] !== null
                                    ? View::e($event['newValue'])
                                    : '<span class="not-recorded">vide</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </details>
    <?php endif; ?>
    </div>

    <?php /* Historique des séjours (retours des médecins du 2026-11-03) : chacun mène à son dossier. */ ?>
    <section class="record-episodes" aria-labelledby="titre-sejours">
        <h2 class="record-subtitle" id="titre-sejours">Séjours du patient</h2>
        <?php if ($episodeHistory === []) : ?>
            <p class="not-recorded">Aucun séjour enregistré.</p>
        <?php else : ?>
            <?= View::render('components/episode_history', [
                'episodes' => $episodeHistory,
                'label' => 'Séjours du patient, du plus récent au plus ancien',
            ]) ?>
        <?php endif; ?>
    </section>

</section>

<?php /* Prescriptions en cours dans une colonne à droite du dossier (retours du 2026-11-02) ; sous le
         dossier sur un écran plus étroit. RG5 : seules les lignes validées par un médecin et non arrêtées. */ ?>
<aside class="record-aside" aria-labelledby="titre-prescriptions">
    <div class="record-aside__header">
        <h2 class="record-subtitle" id="titre-prescriptions">Prescriptions en cours</h2>
        <p class="record-aside__actions">
            <?php if ($episodeOfCare['ongoing']) : ?>
                <?php /* AURA-20 : vers l'écran B3 « Nouvelles prescriptions » (séjour en cours seulement). */ ?>
                <a class="button"
                   href="/care-plans/new?idEpisodeOfCare=<?= View::e($idEpisodeOfCare) ?>">Nouvelle prescription</a>
            <?php endif; ?>
            <a class="button button--secondary"
               href="/care-plans?idEpisodeOfCare=<?= View::e($idEpisodeOfCare) ?>">Toutes les prescriptions</a>
        </p>
    </div>
    <?php if ($prescriptions === []) : ?>
        <?= View::render('components/empty_state', [
            'title' => 'Aucune prescription validée.',
            'text' => 'Les ordonnances en attente de validation médicale sont sur l\'écran Prescription.',
        ]) ?>
    <?php else : ?>
        <?php
        $notes = [
            'important' => 'Important : ',
            'nurseInstructions' => 'Consignes IDE : ',
            'maxDose' => 'Dose max : ',
            'dilutionVolume' => 'Volume de dilution : ',
            'solvent' => 'Solvant : ',
            'administrationDuration' => 'Durée d\'administration : ',
            'antibioticReason' => 'Motif antibiotique : ',
            'germ' => 'Germe : ',
            'renalAdjustment' => 'Adaptation rénale : ',
            'adjustmentCriterion' => 'Critère d\'adaptation : ',
            'offGhsReason' => 'Motif hors GHS : ',
            'timeConstraint' => '',
        ];
        ?>
        <ul class="prescription-cards record-prescriptions">
            <?php foreach ($prescriptions as $line) : ?>
                <li class="prescription-card">
                    <p class="prescription-card__label"><?= View::e($line['label']) ?></p>
                    <?php foreach ($notes as $note => $prefix) : ?>
                        <?php if ($line[$note] !== null) : ?>
                            <p class="prescription-card__note"><?= View::e($prefix . $line[$note]) ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <dl class="prescription-card__meta">
                        <dt>Fréquence</dt>
                        <dd><?= View::e($line['frequency']) ?></dd>
                        <dt>Période</dt>
                        <dd><?= View::e($line['period']) ?></dd>
                        <dt>Validée</dt>
                        <dd><?= View::e($line['validation']) ?></dd>
                    </dl>
                    <?php if ($episodeOfCare['ongoing']) : ?>
                        <?php /* AURA-22 : un médecin arrête la ligne ; elle quitte alors cette liste. */ ?>
                        <form class="stop-form" method="post" data-ajax
                              action="/care-plans/stop?idEpisodeOfCare=<?= View::e($idEpisodeOfCare) ?>">
                            <input type="hidden" name="idLine" value="<?= View::e($line['idLine']) ?>">
                            <input type="hidden" name="isMedication"
                                   value="<?= $line['isMedication'] ? '1' : '0' ?>">
                            <select name="idStoppedBy" aria-label="Médecin qui arrête : <?= View::e($line['label']) ?>">
                                <option value="">— médecin —</option>
                                <?php foreach ($doctors as $value => $label) : ?>
                                    <option value="<?= View::e($value) ?>"><?= View::e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Arrêter</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</aside>

</div>

<?php if ($editable) : ?>
    <?php /* Fenêtre de validation par un médecin : AUTHENTIFICATION FACTICE (le mot de passe n'est pas
             vérifié) en attendant l'identification des praticiens (AURA-18). Elle sert aux modifications
             d'une partie et à la fin du séjour (public/js/part-edit.js). */ ?>
    <dialog id="auth-dialog" class="auth-dialog" aria-labelledby="auth-dialog-title">
        <form method="dialog" class="auth-dialog__form" novalidate>
            <h2 id="auth-dialog-title" class="auth-dialog__title">Validation par un médecin</h2>
            <p class="auth-dialog__summary" data-auth-summary></p>
            <div class="auth-dialog__discharge" data-auth-discharge hidden>
                <?= View::render('components/field', ['field' => [
                    'name' => 'dischargeDate', 'id' => 'auth-discharge-date', 'label' => 'Date de sortie',
                    'type' => 'date', 'value' => '', 'error' => '',
                ]]) ?>
                <?= View::render('components/field', ['field' => [
                    'name' => 'dischargeTime', 'id' => 'auth-discharge-time', 'label' => 'Heure de sortie',
                    'type' => 'time', 'value' => '', 'error' => '',
                ]]) ?>
            </div>
            <?= View::render('components/field', ['field' => [
                'name' => 'validator', 'id' => 'auth-validator', 'label' => 'Médecin qui valide', 'type' => 'select',
                'options' => $doctors, 'empty' => 'Choisir le médecin', 'value' => '', 'error' => '',
            ]]) ?>
            <?= View::render('components/field', ['field' => [
                'name' => 'password', 'id' => 'auth-password', 'label' => 'Mot de passe', 'type' => 'password',
                'value' => '', 'error' => '', 'attributes' => ['autocomplete' => 'off'],
            ]]) ?>
            <p class="auth-dialog__notice">
                <?= View::render('components/icon', ['name' => 'info']) ?>
                Authentification de démonstration : le mot de passe n'est pas vérifié. Elle sera remplacée par
                l'identification des praticiens.
            </p>
            <p class="form-error" role="alert" data-auth-error hidden></p>
            <div class="auth-dialog__actions">
                <button type="submit" class="button" value="confirm">Valider</button>
                <button type="button" class="button button--secondary" data-auth-cancel>Annuler</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>
