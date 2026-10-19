<?php

use Aura\Core\View;

/*
 * AURA-20 : saisie d'une prescription (fenêtre « B3. Nouvelles prescriptions »,
 * figures 7, 8 et 9 du sujet). Toutes les listes viennent de la base, préparées par
 * PrescriptionEntryService::getFormData().
 *
 * @var string $episodeId
 * @var string $savedCarePlanId identifiant de l'ordonnance qui vient d'être enregistrée, ou ''
 * @var string $validatedCarePlanId identifiant de l'ordonnance qui vient d'être validée, ou ''
 * @var array<string, string> $modes
 * @var array<string, string> $prescribers   idPractitioner => nom (rôle)
 * @var array<string, string> $validators   médecins (habilités à valider) : idPractitioner => nom (rôle)
 * @var list<array{id: string, mode: string, recordedAt: string, prescriber: string, lines: list<string>}>
 *      $pendingCarePlans   ordonnances du séjour en attente de validation (RG5)
 * @var list<array{id: string, label: string, kind: string}> $categories   figure 8 (kind : GROUP, CARE, MEDICATION)
 * @var array<string, string> $careTypes   idActivityDefinition => nom
 * @var array<string, array{label: string, forms: array<string, string>}> $medications   DCI => formes (idMedication)
 * @var array<string, string> $frequencies
 * @var list<string> $routes
 */

$savedCarePlanId = $savedCarePlanId ?? '';
$validatedCarePlanId = $validatedCarePlanId ?? '';

// Données des listes 2 et 3, lues par public/js/prescription.js
$donneesListes = [
    'careTypes' => $careTypes,
    'medications' => $medications,
];

// Paramètres d'une ligne de médicament, dans l'ordre de la figure 9 :
// [id du champ, libellé, obligatoire, type]. Les champs n'ont pas d'attribut name :
// « OK » les recopie dans des champs cachés lines[n][…] (voir prescription.js).
// Type « absent » : pas de colonne dans le schéma actuel → affiché grisé, non enregistré.
$parametresMedicament = [
    ['med-dci', 'DCI', true, 'lecture'],
    ['med-formulation', 'Formulation', true, 'lecture'],
    ['med-voie', "Voie d'administration", true, 'voie'],
    ['med-dose', 'Dose', true, 'texte'],
    ['med-doseMax', '(Dose max)', false, 'absent'],
    ['med-volumeDilution', 'Volume de dilution', false, 'absent'],
    ['med-solvant', 'Solvant', false, 'absent'],
    ['med-important', 'Important ->', false, 'texte'],
    ['med-nurseInstructions', 'Consignes pour IDE', false, 'texte'],
    ['med-debut', 'Début', true, 'date'],
    ['med-fin', 'Arrêt', false, 'date'],
    ['med-dureeAdministration', "Durée d'administration", false, 'absent'],
    ['med-motifAntibiotique', 'Motif (si antibiotique)', false, 'absent'],
    ['med-germe', 'Germe (si antibiotique)', false, 'absent'],
    ['med-adaptationRenale', 'Adaptation rénale', false, 'absent'],
    ['med-critereAdaptation', "Critère d'adaptation", false, 'absent'],
    ['med-motifHorsGhs', 'Motif (si hors GHS)', false, 'absent'],
];

?>

<section class="b3">
    <p class="b3-retour">
        <a href="/sejour?id=<?= View::e(rawurlencode($episodeId)) ?>">&larr; Retour au dossier du séjour</a>
    </p>
    <h1 class="b3-titre">B3. Nouvelles prescriptions</h1>

    <?php if ($savedCarePlanId !== '') : ?>
        <p class="b3-succes" role="status">Ordonnance n° <?= View::e($savedCarePlanId) ?> enregistrée.</p>
    <?php endif; ?>
    <?php if ($validatedCarePlanId !== '') : ?>
        <p class="b3-succes" role="status">Ordonnance n° <?= View::e($validatedCarePlanId) ?> validée.</p>
    <?php endif; ?>

    <div class="b3-corps">
        <!-- Colonne de gauche : Mode, Prescripteur, listes 1. 2. 3., puis les paramètres -->
        <form id="prescription-form" class="b3-saisie" action="/prescriptions/create" method="post" data-ajax
              data-listes="<?= View::e(json_encode($donneesListes, JSON_UNESCAPED_UNICODE)) ?>">
            <input type="hidden" name="episodeId" value="<?= View::e($episodeId) ?>">

            <div class="b3-ligne">
                <label for="mode">Mode:</label>
                <select id="mode" name="mode">
                    <?php foreach ($modes as $valeur => $libelle) : ?>
                        <option value="<?= View::e($valeur) ?>"><?= View::e($libelle) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="b3-ligne">
                <label for="prescriber">Par:</label>
                <select id="prescriber" name="prescriber">
                    <option value="">— prescripteur —</option>
                    <?php foreach ($prescribers as $valeur => $libelle) : ?>
                        <option value="<?= View::e($valeur) ?>"
                                data-doctor="<?= isset($validators[$valeur]) ? '1' : '0' ?>">
                            <?= View::e($libelle) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="b3-ligne">
                <label for="type">1.</label>
                <select id="type">
                    <option value=""></option>
                    <?php foreach ($categories as $category) : ?>
                        <option value="<?= View::e($category['id']) ?>"
                                data-kind="<?= View::e($category['kind']) ?>">
                            <?= View::e($category['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="b3-ligne">
                <label for="element">2.</label>
                <select id="element" disabled></select>
            </div>

            <div class="b3-ligne">
                <label for="forme">3.</label>
                <select id="forme" disabled></select>
            </div>

            <p id="b3-message" class="b3-message" role="alert" hidden></p>
            <p id="b3-avertissement" class="b3-avertissement" hidden>
                &#9998; Prescripteur non médecin : l'ordonnance devra être validée par un médecin.
            </p>

            <!-- Paramètres d'un médicament (figure 9) -->
            <div id="b3-param-medicament" class="b3-param" hidden>
                <div class="b3-param-titre">&#8863; <span class="b3-param-categorie"></span></div>
                <div class="b3-grille">
                    <label for="med-frequence">Fréquence</label>
                    <span class="b3-requis">*</span>
                    <div class="b3-valeur">
                        <select id="med-frequence">
                            <option value=""></option>
                            <?php foreach ($frequencies as $valeur => $libelle) : ?>
                                <option value="<?= View::e($valeur) ?>"><?= View::e($libelle) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span id="med-heures-bloc" hidden>
                            <input type="number" id="med-heures" min="1" step="1" class="b3-court"> h
                        </span>
                    </div>

                    <?php foreach ($parametresMedicament as [$id, $libelle, $requis, $type]) : ?>
                        <label for="<?= View::e($id) ?>"
                               class="<?= in_array($type, ['lecture', 'absent'], true) ? 'b3-grise' : '' ?>"
                            <?= $type === 'absent' ? 'title="Pas de colonne dans la base : non enregistré"' : '' ?>
                        ><?= View::e($libelle) ?></label>
                        <span class="b3-requis"><?= $requis ? '*' : '' ?></span>
                        <div class="b3-valeur">
                            <?php if ($type === 'voie') : ?>
                                <select id="<?= View::e($id) ?>">
                                    <option value=""></option>
                                    <?php foreach ($routes as $route) : ?>
                                        <option value="<?= View::e($route) ?>"><?= View::e($route) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($type === 'date') : ?>
                                <input type="datetime-local" id="<?= View::e($id) ?>">
                            <?php elseif ($type === 'absent') : ?>
                                <input type="text" id="<?= View::e($id) ?>" disabled>
                            <?php else : ?>
                                <input type="text" id="<?= View::e($id) ?>"
                                    <?= $type === 'lecture' ? 'readonly tabindex="-1"' : 'maxlength="200"' ?>>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="b3-note">Champs grisés : pas encore de colonne dans la base, non enregistrés.</p>
                <div class="b3-boutons">
                    <button type="button" disabled>Nutriments</button>
                    <button type="button" id="med-ok">OK</button>
                    <button type="button" class="b3-annuler">Annuler</button>
                </div>
            </div>

            <!-- Paramètres d'un soin -->
            <div id="b3-param-soin" class="b3-param" hidden>
                <div class="b3-param-titre">&#8863; <span class="b3-param-categorie"></span></div>
                <div class="b3-grille">
                    <label for="soin-nom" class="b3-grise">Soin</label>
                    <span class="b3-requis">*</span>
                    <div class="b3-valeur"><input type="text" id="soin-nom" readonly tabindex="-1"></div>

                    <label for="soin-frequence">Fréquence</label>
                    <span class="b3-requis">*</span>
                    <div class="b3-valeur">
                        <select id="soin-frequence">
                            <option value="ONCE">Une fois</option>
                            <option value="SCHEDULED">Toutes les … heures</option>
                        </select>
                        <span id="soin-heures-bloc" hidden>
                            <input type="number" id="soin-heures" min="1" step="1" class="b3-court"> h
                        </span>
                    </div>

                    <label for="soin-debut">Début</label>
                    <span class="b3-requis">*</span>
                    <div class="b3-valeur"><input type="datetime-local" id="soin-debut"></div>

                    <label for="soin-fin">Arrêt</label>
                    <span class="b3-requis"></span>
                    <div class="b3-valeur"><input type="datetime-local" id="soin-fin"></div>

                    <label for="soin-contrainte">Contrainte horaire</label>
                    <span class="b3-requis"></span>
                    <div class="b3-valeur">
                        <input type="text" id="soin-contrainte" maxlength="200" placeholder="à jeun, 8 h et 20 h…">
                    </div>
                </div>
                <div class="b3-boutons">
                    <button type="button" id="soin-ok">OK</button>
                    <button type="button" class="b3-annuler">Annuler</button>
                </div>
            </div>

            <!-- Lignes validées par « OK », envoyées avec le formulaire : lines[n][…] -->
            <div id="b3-lignes" hidden></div>
        </form>

        <!-- Colonne de droite : l'ordonnance en cours de saisie -->
        <div class="b3-recap">
            <h2 class="b3-recap-titre">Prescriptions non sauvegardées</h2>
            <table class="b3-panier">
                <thead>
                    <tr><th>Catégorie</th><th>Prescription</th><th></th></tr>
                </thead>
                <tbody id="b3-panier"></tbody>
            </table>
            <div class="b3-boutons">
                <button type="submit" form="prescription-form" class="b3-enregistrer">Enregistrer l'ordonnance</button>
            </div>

            <!-- RG5 : ordonnances saisies par un non-médecin, à valider par un médecin -->
            <h2 class="b3-recap-titre">En attente de validation par un médecin</h2>
            <?php if ($pendingCarePlans === []) : ?>
                <p class="b3-vide">Aucune prescription en attente.</p>
            <?php endif; ?>
            <?php foreach ($pendingCarePlans as $carePlan) : ?>
                <div class="b3-attente">
                    <p class="b3-attente-entete">
                        &#9998; Ordonnance n° <?= View::e($carePlan['id']) ?> du <?= View::e($carePlan['recordedAt']) ?>
                        · <?= View::e($carePlan['mode']) ?> · par <?= View::e($carePlan['prescriber']) ?>
                    </p>
                    <ul>
                        <?php foreach ($carePlan['lines'] as $line) : ?>
                            <li><?= View::e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <form class="b3-valider" action="/prescriptions/validate" method="post" data-ajax>
                        <input type="hidden" name="episodeId" value="<?= View::e($episodeId) ?>">
                        <input type="hidden" name="carePlanId" value="<?= View::e($carePlan['id']) ?>">
                        <select name="validator" aria-label="Médecin qui valide">
                            <option value="">— médecin —</option>
                            <?php foreach ($validators as $valeur => $libelle) : ?>
                                <option value="<?= View::e($valeur) ?>"><?= View::e($libelle) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Valider</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
