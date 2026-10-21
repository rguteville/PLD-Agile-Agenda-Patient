<?php

use Aura\Core\View;
use Aura\Service\CarePlanService;

/**
 * AURA-20 / AURA-21 / AURA-23 : fenêtre « B3. Nouvelles prescriptions » (figures 7, 8 et 9 du
 * sujet). Toutes les listes viennent de CarePlanService::form() ; public/js/care-plan.js anime
 * les listes 1. 2. 3. et le panier, public/js/form.js envoie les formulaires.
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
 * @var array<string, string> $frequencyTypes ONCE / SCHEDULED / DAYS => libellé
 * @var array<string, string> $routes code => libellé
 * @var list<array{idCarePlan: int, mode: string, recordedAt: string, prescriber: string, lines: list<string>}>
 *      $pendingCarePlans ordonnances du séjour en attente de validation (RG5)
 * @var string $formError ordonnance refusée sans JavaScript (sinon form.js affiche l'erreur)
 */

// Données des listes 2 et 3, lues par public/js/care-plan.js. JSON_FORCE_OBJECT : toujours des
// objets { id: libellé }, même si les identifiants étaient 0, 1, 2…
$lists = [
    'careTypes' => $careTypes,
    'medications' => $medications,
];
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
    ['med-start', 'Début', true, 'date'],
    ['med-end', 'Arrêt', false, 'date'],
    ['med-administrationDuration', "Durée d'administration", false, 'dose'],
    ['med-antibioticReason', 'Motif (si antibiotique)', false, 'text'],
    ['med-germ', 'Germe (si antibiotique)', false, 'text'],
    ['med-renalAdjustment', 'Adaptation rénale', false, 'text'],
    ['med-adjustmentCriterion', "Critère d'adaptation", false, 'text'],
    ['med-offGhsReason', 'Motif (si hors GHS)', false, 'text'],
];
?>
<?= View::render('components/bandeau_patient', ['bandeau' => $banner]) ?>

<section class="b3">
    <h1 class="form-title">Nouvelles prescriptions</h1>
    <?php /* Même bouton bleu que « Prescription » sur le dossier du séjour. */ ?>
    <p class="episode-actions">
        <a class="button" href="/sejour?id=<?= View::e($idEpisodeOfCare) ?>">Retour au dossier du séjour</a>
    </p>

    <?php if ($savedCarePlanId !== null) : ?>
        <p class="b3-success" role="status">Ordonnance n° <?= View::e($savedCarePlanId) ?> enregistrée.</p>
    <?php endif; ?>
    <?php if ($validatedCarePlanId !== null) : ?>
        <p class="b3-success" role="status">Ordonnance n° <?= View::e($validatedCarePlanId) ?> validée.</p>
    <?php endif; ?>

    <div class="b3-body">
        <!-- Colonne de gauche : Mode, Prescripteur, listes 1. 2. 3., puis les paramètres -->
        <form id="care-plan-form" class="b3-entry" action="/care-plans/create<?= View::e($episodeQuery) ?>"
              method="post" data-ajax
              data-lists="<?= View::e(json_encode($lists, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)) ?>">
            <?php if ($formError !== '') : ?>
                <p class="form-error" role="alert"><?= View::e($formError) ?></p>
            <?php endif; ?>

            <div class="b3-row">
                <label for="mode">Mode:</label>
                <select id="mode" name="mode">
                    <?php foreach ($modes as $value => $label) : ?>
                        <option value="<?= View::e($value) ?>"><?= View::e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="b3-row">
                <label for="idPrescriber">Par:</label>
                <select id="idPrescriber" name="idPrescriber">
                    <option value="">— prescripteur —</option>
                    <?php foreach ($prescribers as $value => $label) : ?>
                        <option value="<?= View::e($value) ?>"
                                data-doctor="<?= isset($validators[$value]) ? '1' : '0' ?>">
                            <?= View::e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="b3-row">
                <label for="category">1.</label>
                <select id="category">
                    <option value=""></option>
                    <?php foreach ($categories as $id => $category) : ?>
                        <option value="<?= View::e($id) ?>" data-kind="<?= View::e($category['kind']) ?>">
                            <?= View::e($category['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="b3-row">
                <label for="item">2.</label>
                <select id="item" disabled></select>
            </div>

            <div class="b3-row">
                <label for="dosage-form">3.</label>
                <select id="dosage-form" disabled></select>
            </div>

            <p id="b3-message" class="b3-message" role="alert" hidden></p>
            <p id="b3-warning" class="b3-warning" hidden>
                &#9998; Prescripteur non médecin : l'ordonnance devra être validée par un médecin.
            </p>

            <!-- Paramètres d'un médicament (figure 9) -->
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
                        <label for="<?= View::e($id) ?>"
                               class="<?= $type === 'readonly' ? 'b3-greyed' : '' ?>"><?= View::e($label) ?></label>
                        <span class="b3-required"><?= $required ? '*' : '' ?></span>
                        <div class="b3-value">
                            <?php if ($type === 'route') : ?>
                                <select id="<?= View::e($id) ?>">
                                    <option value=""></option>
                                    <?php foreach ($routes as $value => $routeLabel) : ?>
                                        <option value="<?= View::e($value) ?>"><?= View::e($routeLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($type === 'date') : ?>
                                <input type="datetime-local" id="<?= View::e($id) ?>">
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
                    <!-- <button type="button" disabled>Nutriments</button> -->
                    <button type="button" id="med-ok" class="b3-primary">OK</button>
                    <button type="button" class="b3-cancel">Annuler</button>
                </div>
            </div>

            <!-- Paramètres d'un soin -->
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

                    <label for="care-start">Début</label>
                    <span class="b3-required">*</span>
                    <div class="b3-value"><input type="datetime-local" id="care-start"></div>

                    <label for="care-end">Arrêt</label>
                    <span class="b3-required"></span>
                    <div class="b3-value"><input type="datetime-local" id="care-end"></div>

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

            <!-- Lignes ajoutées par « OK », envoyées avec le formulaire : lines[n][…] -->
            <div id="b3-lines" hidden></div>
        </form>

        <!-- Colonne de droite : l'ordonnance en cours de saisie -->
        <div class="b3-summary">
            <h2 class="b3-summary-title">Prescriptions non sauvegardées</h2>
            <table class="b3-cart">
                <thead>
                    <tr><th>Catégorie</th><th>Prescription</th><th></th></tr>
                </thead>
                <tbody id="b3-cart"></tbody>
            </table>
            <div class="b3-buttons">
                <button type="submit" form="care-plan-form" class="b3-primary">Enregistrer l'ordonnance</button>
            </div>

            <!-- RG5 : ordonnances saisies par un non-médecin, à valider par un médecin (AURA-23) -->
            <h2 class="b3-summary-title">En attente de validation par un médecin</h2>
            <?php if ($pendingCarePlans === []) : ?>
                <p class="b3-empty">Aucune prescription en attente.</p>
            <?php endif; ?>
            <?php foreach ($pendingCarePlans as $carePlan) : ?>
                <div class="b3-pending">
                    <p class="b3-pending-header">
                        &#9998; Ordonnance n° <?= View::e($carePlan['idCarePlan']) ?>
                        du <?= View::e($carePlan['recordedAt']) ?>
                        · <?= View::e($carePlan['mode']) ?> · par <?= View::e($carePlan['prescriber']) ?>
                    </p>
                    <ul>
                        <?php foreach ($carePlan['lines'] as $line) : ?>
                            <li><?= View::e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <form class="b3-validate" action="/care-plans/validate<?= View::e($episodeQuery) ?>"
                          method="post" data-ajax>
                        <input type="hidden" name="idCarePlan" value="<?= View::e($carePlan['idCarePlan']) ?>">
                        <select name="idValidator" aria-label="Médecin qui valide">
                            <option value="">— médecin —</option>
                            <?php foreach ($validators as $value => $label) : ?>
                                <option value="<?= View::e($value) ?>"><?= View::e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Valider</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
