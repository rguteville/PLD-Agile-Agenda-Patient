<?php

use Aura\Core\View;
use Aura\Service\CarePlanService;

/**
 * AURA-20 / AURA-21 / AURA-23 : fenêtre « B3. Nouvelles prescriptions » (figures 7, 8 et 9 du
 * sujet). Toutes les listes viennent de CarePlanService::form() ; public/js/care-plan.js anime
 * les listes en cascade (Catégorie → Médicament / Soin / Protocole → Forme), le panier et l'alerte
 * allergie ; public/js/form.js envoie les formulaires (POST /care-plans/create et /care-plans/validate).
 * Rendue par CarePlanController::newForm() (GET /care-plans/new?idEpisodeOfCare=…, séjour en cours
 * seulement) et réaffichée en 422 après un refus sans JavaScript. Scripts : form.js, care-plan.js.
 *
 * @var array<string, mixed> $banner bandeau patient (RG2)
 * @var int $idEpisodeOfCare
 * @var int|null $savedCarePlanId ordonnance qui vient d'être enregistrée
 * @var int|null $validatedCarePlanId ordonnance qui vient d'être validée
 * @var array<string, string> $modes code => libellé
 * @var array<string, string> $prescribers idPractitioner => « nom (rôle) »
 * @var array<string, string> $validators médecins (habilités à valider) : idPractitioner => « nom (rôle) »
 * @var array<string, array{label: string, kind: string}> $categories figure 8 (kind : GROUP, MEDICATION, CARE)
 * @var array<string, string> $careTypes idActivityDefinition => nom du soin
 * @var array<string, array<string, string>> $medications DCI => [idMedication => forme]
 * @var array<string, string> $planDefinitions protocoles actifs : idPlanDefinition => « titre (pathologie) »
 * @var array<string, string> $frequencyTypes ONCE / SCHEDULED / DAYS => libellé
 * @var array<string, string> $routes code => libellé
 * @var list<array{idCarePlan: int, mode: string, recordedAt: ?string, prescriber: string, note: ?string,
 *      lines: list<string>, allergyAlerts: list<string>}> $pendingCarePlans ordonnances du séjour en
 *      attente de validation (RG5)
 * @var string $formError ordonnance refusée sans JavaScript (sinon form.js affiche l'erreur), '' sinon
 * @var list<string> $activeMedications idMedication déjà prescrits et non arrêtés dans ce séjour ([] par défaut)
 * @var array<string, string> $medicationAllergens idMedication => allergène du patient (alerte allergie),
 *      [] par défaut
 * @var int $noteMaxLength longueur maximale de la note (CarePlanService::NOTE_MAX_LENGTH par défaut)
 * @var string $csrfToken jeton CSRF de la session (formulaires d'enregistrement et de validation)
 */

// Données des listes en cascade, lues par public/js/care-plan.js. JSON_FORCE_OBJECT : toujours des
// objets { id: libellé }, même si les identifiants étaient 0, 1, 2…
$lists = [
    'careTypes' => $careTypes,
    'medications' => $medications,
    // Médicaments déjà prescrits et non arrêtés dans ce séjour : avertissement, pas interdiction.
    'activeMedications' => array_fill_keys($activeMedications ?? [], true),
    'planDefinitions' => $planDefinitions,
    // Médicaments dont le principe actif est un allergène du patient : alerte à l'écran. Le serveur
    // refuse de toute façon l'ordonnance sans confirmation (CarePlanService::prescribe()).
    'medicationAllergens' => $medicationAllergens ?? [],
];
$csrfField = '<input type="hidden" name="csrf" value="' . View::e($csrfToken ?? '') . '">';
// Le séjour est passé dans l'URL des deux formulaires, comme pour les pages GET.
$episodeQuery = '?idEpisodeOfCare=' . $idEpisodeOfCare;

// Paramètres d'une ligne de médicament, dans l'ordre de la figure 9 :
// [id du champ, libellé, obligatoire, type]. Les champs n'ont pas d'attribut name :
// « OK » les recopie dans des champs cachés lines[n][…] (voir care-plan.js).
$medicationParams = [
    ['med-inn', 'DCI', true, 'readonly'],
    ['med-form', 'Formulation', true, 'readonly'],
    ['med-route', "Voie d'administration", true, 'route'],
    ['med-dose', 'Dose', true, 'dose'],
    ['med-maxDose', '(Dose max)', false, 'dose'],
    ['med-dilutionVolume', 'Volume de dilution', false, 'dose'],
    ['med-solvent', 'Solvant', false, 'text'],
    ['med-important', 'Important ->', false, 'text'],
    ['med-nurseInstructions', 'Consignes pour IDE', false, 'text'],
    ['med-start', 'Du', true, 'date'],
    // Sans heure, la date de fin vaut jusqu'à 23 h 59 ce jour-là (care-plan.js).
    ['med-end', 'Au (date de fin)', false, 'date'],
    ['med-administrationDuration', "Durée d'administration", false, 'dose'],
    ['med-antibioticReason', 'Motif (si antibiotique)', false, 'text'],
    ['med-germ', 'Germe (si antibiotique)', false, 'text'],
    ['med-renalAdjustment', 'Adaptation rénale', false, 'text'],
    ['med-adjustmentCriterion', "Critère d'adaptation", false, 'text'],
    ['med-offGhsReason', 'Motif (si hors GHS)', false, 'text'],
];
?>
<?= View::render('components/patient_banner', ['banner' => $banner]) ?>
<?= View::render('components/breadcrumb', ['items' => [
    ['label' => 'Patients', 'href' => '/patients'],
    ['label' => 'Dossier du séjour n° ' . $banner['episodeNumber'], 'href' => '/sejour?id=' . $idEpisodeOfCare],
    ['label' => 'Nouvelle prescription'],
]]) ?>

<section class="b3">
    <div class="page-header">
        <h1 class="form-title">Nouvelles prescriptions</h1>
        <p class="page-actions">
            <a class="button button--secondary"
               href="/sejour?id=<?= View::e($idEpisodeOfCare) ?>">Dossier du séjour</a>
        </p>
    </div>

    <?php if ($savedCarePlanId !== null) : ?>
        <p class="b3-success" role="status">Ordonnance n° <?= View::e($savedCarePlanId) ?> enregistrée.</p>
    <?php endif; ?>
    <?php if ($validatedCarePlanId !== null) : ?>
        <p class="b3-success" role="status">Ordonnance n° <?= View::e($validatedCarePlanId) ?> validée.</p>
    <?php endif; ?>

    <div class="b3-body">
        <?php /* Colonne de gauche : Mode, Prescripteur, listes en cascade, puis les paramètres */ ?>
        <form id="care-plan-form" class="b3-entry" action="/care-plans/create<?= View::e($episodeQuery) ?>"
              method="post" data-ajax
              data-lists="<?= View::e(json_encode($lists, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)) ?>">
            <?= $csrfField ?>
            <?php if ($formError !== '') : ?>
                <p class="form-error" role="alert"><?= View::e($formError) ?></p>
            <?php endif; ?>

            <div class="b3-row">
                <label for="mode">Mode</label>
                <select id="mode" name="mode">
                    <?php foreach ($modes as $value => $label) : ?>
                        <option value="<?= View::e($value) ?>"><?= View::e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="b3-row">
                <label for="idPrescriber">Prescripteur</label>
                <select id="idPrescriber" name="idPrescriber">
                    <option value="">Choisir un prescripteur</option>
                    <?php foreach ($prescribers as $value => $label) : ?>
                        <option value="<?= View::e($value) ?>"
                                data-doctor="<?= isset($validators[$value]) ? '1' : '0' ?>">
                            <?= View::e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php /* RG5 : l'ordonnance d'un médecin est validée d'office, donc signée par son mot de passe
                     (authentification FACTICE, non vérifié). Ignoré pour un prescripteur non médecin. */ ?>
            <div class="b3-row">
                <label for="prescriberPassword">Mot de passe (médecin)</label>
                <input type="password" id="prescriberPassword" name="prescriberPassword" autocomplete="off">
            </div>

            <?php /* Listes en cascade (retours du 2026-11-03 : « 1. 2. 3. » remplacés par leur rôle).
                     Le libellé de la deuxième suit la catégorie choisie (care-plan.js). */ ?>
            <div class="b3-row">
                <label for="category">Catégorie</label>
                <select id="category">
                    <option value="">Choisir</option>
                    <?php foreach ($categories as $id => $category) : ?>
                        <option value="<?= View::e($id) ?>" data-kind="<?= View::e($category['kind']) ?>">
                            <?= View::e($category['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="b3-row">
                <label for="item" id="item-label">Prescription</label>
                <select id="item" disabled></select>
            </div>

            <div class="b3-row">
                <label for="dosage-form">Forme galénique</label>
                <select id="dosage-form" disabled></select>
            </div>

            <p id="b3-message" class="b3-message" role="alert" hidden></p>
            <?php /* Alerte allergie (RG2) : médicament dont le principe actif est un allergène du patient. */ ?>
            <p id="b3-allergy" class="b3-allergy" role="alert" hidden></p>
            <?php /* Avertissement non bloquant : médicament déjà prescrit (dans le séjour ou l'ordonnance). */ ?>
            <p id="b3-notice" class="b3-notice" role="status" hidden></p>
            <p id="b3-warning" class="b3-warning" hidden>
                &#9998; Prescripteur non médecin : l'ordonnance devra être validée par un médecin.
            </p>

            <?php /* Paramètres d'un médicament (figure 9) */ ?>
            <div id="b3-medication-params" class="b3-params" hidden>
                <div class="b3-params-title">&#8863; <span class="b3-params-category"></span></div>
                <div class="b3-grid">
                    <label for="med-frequency">Fréquence</label>
                    <span class="b3-required">*</span>
                    <div class="b3-value">
                        <select id="med-frequency">
                            <option value=""></option>
                            <?php foreach ($frequencyTypes as $value => $label) : ?>
                                <option value="<?= View::e($value) ?>"><?= View::e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span id="med-hours-block" hidden>
                            <input type="number" id="med-hours" min="1"
                                   max="<?= CarePlanService::FREQUENCY_MAX_HOURS ?>" step="1" class="b3-short"> h
                        </span>
                        <span id="med-days-block" hidden>
                            <input type="number" id="med-days" min="1"
                                   max="<?= CarePlanService::FREQUENCY_MAX_DAYS ?>" step="1" class="b3-short"> j
                        </span>
                    </div>

                    <?php foreach ($medicationParams as [$id, $label, $required, $type]) : ?>
                        <?php /* Dates (2026-11-04) : jour et heure séparés (#…-date, #…-time) ; « Une fois » n'a qu'une
                                 ligne « Le » (care-plan.js masque la ligne de fin, data-row). */ ?>
                        <label for="<?= View::e($type === 'date' ? $id . '-date' : $id) ?>"
                               id="<?= View::e($id) ?>-label" data-row="<?= View::e($id) ?>"
                               class="<?= $type === 'readonly' ? 'b3-greyed' : '' ?>"><?= View::e($label) ?></label>
                        <span class="b3-required" data-row="<?= View::e($id) ?>"><?= $required ? '*' : '' ?></span>
                        <div class="b3-value" data-row="<?= View::e($id) ?>">
                            <?php if ($type === 'route') : ?>
                                <select id="<?= View::e($id) ?>">
                                    <option value=""></option>
                                    <?php foreach ($routes as $value => $routeLabel) : ?>
                                        <option value="<?= View::e($value) ?>"><?= View::e($routeLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($type === 'date') : ?>
                                <span class="b3-datetime">
                                    <input type="date" id="<?= View::e($id) ?>-date">
                                    <input type="time" id="<?= View::e($id) ?>-time"
                                           aria-label="Heure (<?= View::e($label) ?>)">
                                </span>
                            <?php elseif ($type === 'readonly') : ?>
                                <input type="text" id="<?= View::e($id) ?>" readonly tabindex="-1">
                            <?php else : ?>
                                <?php /* Colonnes : type « dose » VARCHAR(50) ; textes VARCHAR(200). */ ?>
                                <input type="text" id="<?= View::e($id) ?>" maxlength="<?= $type === 'dose'
                                    ? CarePlanService::DOSE_MAX_LENGTH : CarePlanService::TEXT_MAX_LENGTH ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="b3-buttons">
                    <button type="button" id="med-ok" class="b3-primary">OK</button>
                    <button type="button" class="b3-cancel">Annuler</button>
                </div>
            </div>

            <?php /* Paramètres d'un soin */ ?>
            <div id="b3-care-params" class="b3-params" hidden>
                <div class="b3-params-title">&#8863; <span class="b3-params-category"></span></div>
                <div class="b3-grid">
                    <label for="care-name" class="b3-greyed">Soin</label>
                    <span class="b3-required">*</span>
                    <div class="b3-value"><input type="text" id="care-name" readonly tabindex="-1"></div>

                    <label for="care-frequency">Fréquence</label>
                    <span class="b3-required">*</span>
                    <div class="b3-value">
                        <select id="care-frequency">
                            <?php foreach ($frequencyTypes as $value => $label) : ?>
                                <option value="<?= View::e($value) ?>"><?= View::e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span id="care-hours-block" hidden>
                            <input type="number" id="care-hours" min="1"
                                   max="<?= CarePlanService::FREQUENCY_MAX_HOURS ?>" step="1" class="b3-short"> h
                        </span>
                        <span id="care-days-block" hidden>
                            <input type="number" id="care-days" min="1"
                                   max="<?= CarePlanService::FREQUENCY_MAX_DAYS ?>" step="1" class="b3-short"> j
                        </span>
                    </div>

                    <label for="care-start-date" id="care-start-label">Du</label>
                    <span class="b3-required">*</span>
                    <div class="b3-value">
                        <span class="b3-datetime">
                            <input type="date" id="care-start-date">
                            <input type="time" id="care-start-time" aria-label="Heure (début)">
                        </span>
                    </div>

                    <label for="care-end-date" data-row="care-end">Au (date de fin)</label>
                    <span class="b3-required" data-row="care-end"></span>
                    <div class="b3-value" data-row="care-end">
                        <span class="b3-datetime">
                            <input type="date" id="care-end-date">
                            <input type="time" id="care-end-time" aria-label="Heure (fin)">
                        </span>
                    </div>

                    <label for="care-timeConstraint">Contrainte horaire</label>
                    <span class="b3-required"></span>
                    <div class="b3-value">
                        <input type="text" id="care-timeConstraint" maxlength="<?= CarePlanService::TEXT_MAX_LENGTH ?>"
                               placeholder="à jeun, 8 h et 20 h…">
                    </div>
                </div>
                <div class="b3-buttons">
                    <button type="button" id="care-ok" class="b3-primary">OK</button>
                    <button type="button" class="b3-cancel">Annuler</button>
                </div>
            </div>

            <?php /* Groupe de prescriptions (protocole) : « OK » ajoute toutes ses lignes à l'ordonnance */ ?>
            <div id="b3-group-params" class="b3-params" hidden>
                <div class="b3-params-title">&#8863; <span class="b3-params-category"></span></div>
                <div class="b3-grid">
                    <label for="group-name" class="b3-greyed">Protocole</label>
                    <span class="b3-required">*</span>
                    <div class="b3-value"><input type="text" id="group-name" readonly tabindex="-1"></div>

                    <?php /* Retours du 2026-11-04 : un protocole n'a qu'un début (chaque ligne a sa durée) ;
                             « Du » sans « Au » prêtait à confusion. */ ?>
                    <label for="group-start">Début du protocole</label>
                    <span class="b3-required">*</span>
                    <div class="b3-value"><input type="datetime-local" id="group-start"></div>
                </div>
                <p class="b3-group-hint">
                    Les dates de chaque ligne sont calculées à partir de ce début ; chaque ligne peut
                    ensuite être retirée de l'ordonnance avant l'enregistrement.
                </p>
                <div class="b3-buttons">
                    <button type="button" id="group-ok">OK</button>
                    <button type="button" class="b3-cancel">Annuler</button>
                </div>
            </div>

            <?php /* Lignes ajoutées par « OK », envoyées avec le formulaire : lines[n][…] */ ?>
            <div id="b3-lines" hidden></div>
        </form>

        <?php /* Colonne de droite : l'ordonnance en cours de saisie */ ?>
        <div class="b3-summary">
            <h2 class="b3-summary-title">Prescriptions non sauvegardées</h2>
            <table class="b3-cart">
                <thead>
                    <tr><th>Catégorie</th><th>Prescription</th><th></th></tr>
                </thead>
                <tbody id="b3-cart"></tbody>
            </table>

            <?php /* Note libre de l'ordonnance (retours du 2026-11-03) : envoyée avec le formulaire
                     (attribut form), affichée dans la liste des prescriptions et dans le dossier. */ ?>
            <div class="b3-note">
                <label for="care-plan-note">Note de l'ordonnance <span class="b3-optional">(facultative)</span></label>
                <textarea id="care-plan-note" name="note" form="care-plan-form" rows="3"
                          maxlength="<?= View::e($noteMaxLength ?? CarePlanService::NOTE_MAX_LENGTH) ?>"
                          aria-describedby="care-plan-note-hint"
                          placeholder="contexte, objectif, réévaluation prévue…"></textarea>
                <p class="b3-hint" id="care-plan-note-hint">
                    <?= View::e($noteMaxLength ?? CarePlanService::NOTE_MAX_LENGTH) ?> caractères au plus.
                </p>
            </div>

            <?php /* Confirmation explicite d'une prescription malgré l'allergie : visible dès qu'une ligne
                     de l'ordonnance contient un allergène du patient (care-plan.js). Le serveur refuse
                     l'ordonnance sans elle et trace la confirmation (CarePlanService::prescribe()). */ ?>
            <div id="b3-allergy-confirm" class="b3-allergy-confirm" hidden>
                <p class="b3-allergy-confirm__title">
                    <?= View::render('components/icon', ['name' => 'triangle-alert']) ?>
                    Allergie du patient : l'ordonnance contient
                </p>
                <ul id="b3-allergy-list"></ul>
                <label class="b3-allergy-confirm__check">
                    <input type="checkbox" id="allergyOverride"
                           name="<?= View::e(CarePlanService::ALLERGY_OVERRIDE) ?>" value="" form="care-plan-form">
                    Je prescris malgré l'allergie
                </label>
            </div>

            <div class="b3-buttons">
                <button type="submit" form="care-plan-form" class="b3-primary">Enregistrer l'ordonnance</button>
            </div>

            <?php /* RG5 : ordonnances saisies par un non-médecin, à valider par un médecin (AURA-23) */ ?>
            <h2 class="b3-summary-title">En attente de validation par un médecin</h2>
            <?php if ($pendingCarePlans === []) : ?>
                <p class="b3-empty">Aucune prescription en attente.</p>
            <?php endif; ?>
            <?php foreach ($pendingCarePlans as $carePlan) : ?>
                <?php /* Cible du badge « Ordonnance non validée » des agendas (#ordonnance-<id>). */ ?>
                <div class="b3-pending" id="ordonnance-<?= View::e($carePlan['idCarePlan']) ?>">
                    <p class="b3-pending-header">
                        <?= View::render('components/status_badge', ['status' => 'to_validate']) ?>
                        Ordonnance n° <?= View::e($carePlan['idCarePlan']) ?>
                        du <?= View::e($carePlan['recordedAt']) ?>, <?= View::e($carePlan['mode'])
                        ?>, par <?= View::e($carePlan['prescriber']) ?>
                    </p>
                    <ul>
                        <?php foreach ($carePlan['lines'] as $line) : ?>
                            <li><?= View::e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (($carePlan['note'] ?? null) !== null) : ?>
                        <p class="b3-pending-note">Note : <?= View::e($carePlan['note']) ?></p>
                    <?php endif; ?>
                    <?php foreach ($carePlan['allergyAlerts'] ?? [] as $alert) : ?>
                        <p class="b3-allergy"><?= View::e($alert) ?></p>
                    <?php endforeach; ?>
                    <?php /* Authentification FACTICE du médecin (mot de passe non vérifié, AURA-18), comme la
                             fenêtre de validation du dossier. */ ?>
                    <?php $formId = 'validate-' . $carePlan['idCarePlan']; ?>
                    <form class="b3-validate" action="/care-plans/validate<?= View::e($episodeQuery) ?>"
                          method="post" data-ajax>
                        <?= $csrfField ?>
                        <input type="hidden" name="idCarePlan" value="<?= View::e($carePlan['idCarePlan']) ?>">
                        <label for="<?= View::e($formId) ?>-validator">Médecin qui valide</label>
                        <select id="<?= View::e($formId) ?>-validator" name="idValidator" required>
                            <option value="">Choisir un médecin</option>
                            <?php foreach ($validators as $value => $label) : ?>
                                <option value="<?= View::e($value) ?>"><?= View::e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="<?= View::e($formId) ?>-password">Mot de passe</label>
                        <input type="password" id="<?= View::e($formId) ?>-password" name="password"
                               autocomplete="off" required>
                        <button type="submit">Valider</button>
                        <p class="b3-hint">Authentification de démonstration : le mot de passe n'est pas vérifié.</p>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
