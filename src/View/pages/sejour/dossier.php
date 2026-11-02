<?php

use Aura\Core\View;

/**
 * Dossier du séjour (AURA-14) et gestion des allergies connues du patient (AURA-13.2).
 *
 * @var array<string, mixed> $bandeau
 * @var array<string, mixed> $sejour
 * @var int $idSejour
 * @var list<array<string, mixed>> $prescriptions lignes validées, vue-modèle de CarePlanService::presentLine()
 * @var array<string, string> $doctors médecins pouvant arrêter une ligne : idPractitioner => « nom (rôle) »
 * @var list<array{idAllergy: ?int, label: string, criticality: ?string, recordedAt: ?string}> $allergies
 * @var array<string, string> $substances allergènes du référentiel non encore enregistrés, id => libellé
 * @var list<string> $criticalities
 * @var ?string $allergyError message d'un ajout ou d'un retrait refusé, null sinon
 * @var list<array<string, mixed>> $sections dossier complet, vue-modèle de DossierPatientService::presentSections()
 * @var bool $editable édition champ par champ active (inline-edit.js, endpoint /sejour/champ/modifier)
 * @var ?string $csrfToken jeton de session envoyé par le script dans X-CSRF-Token
 * @var list<array<string, mixed>> $history dernières modifications, vue-modèle de RecordEditService::history()
 */
$allergyError = $allergyError ?? null;
$sections = $sections ?? [];
$editable = $editable ?? false;
$csrfToken = $csrfToken ?? null;
$history = $history ?? [];
// Contenu HTML (échappé) d'une ligne du dossier complet : valeur, ou état explicite si absente.
$valeurLigne = static function (array $row): string {
    if ($row['value'] !== null) {
        $html = View::e($row['value']);
    } else {
        $html = '<span class="non-renseigne">' . ($row['computed'] ? 'Non calculable' : 'Non renseigné') . '</span>';
    }
    if ($row['computed'] && $row['help'] !== null) {
        $html .= ' <small>' . View::e($row['help']) . '</small>';
    }

    return $html;
};
?>
<?= View::render('components/bandeau_patient', ['bandeau' => $bandeau]) ?>

<section class="dossier">
    <div class="page-header">
        <h1 class="form-title">Dossier du séjour n° <?= View::e($bandeau['numeroSejour']) ?></h1>
        <p class="page-actions">
            <a class="button button--secondary"
               href="/agenda-patient?id=<?= View::e($idSejour) ?>">Agenda du jour</a>
            <a class="button button--secondary"
               href="/care-plans?idEpisodeOfCare=<?= View::e($idSejour) ?>">Toutes les prescriptions</a>
            <?php if ($sejour['enCours']) : ?>
                <?php /* AURA-20 : vers l'écran B3 « Nouvelles prescriptions » (séjour en cours seulement). */ ?>
                <a class="button" href="/care-plans/new?idEpisodeOfCare=<?= View::e($idSejour) ?>">Prescription</a>
            <?php endif; ?>
        </p>
    </div>
    <dl>
        <dt>Statut</dt>
        <dd>
            <?php if ($sejour['enCours']) : ?>
                <?= View::render('components/status_badge', ['status' => 'planned', 'label' => 'En cours']) ?>
            <?php else : ?>
                <?= View::render('components/status_badge', ['status' => 'stopped', 'label' => 'Clos']) ?>
                le <?= View::e($sejour['dateSortie']) ?>
            <?php endif; ?>
        </dd>
        <dt>Admission</dt>
        <dd><?= View::e($bandeau['dateAdmission'] ?? 'non renseignée') ?></dd>
        <dt>Service</dt>
        <dd><?= View::e($sejour['service'] ?? 'non renseigné') ?></dd>
        <dt>Motif d'admission</dt>
        <dd><?= isset($sejour['motifAdmission']) ? nl2br(View::e($sejour['motifAdmission'])) : 'non renseigné' ?></dd>
        <dt>Lit</dt>
        <dd><?= View::e($sejour['lit'] ?? 'non attribué') ?></dd>
        <dt>Box</dt>
        <dd><?= View::e($sejour['box'] ?? 'non attribué') ?></dd>
        <dt>Précautions</dt>
        <?php /* « non renseignées », pas « aucune » : rien de saisi ne veut pas dire aucun risque. */ ?>
        <dd><?= isset($sejour['precautions']) ? nl2br(View::e($sejour['precautions'])) : 'non renseignées' ?></dd>
        <dt>Téléphone</dt>
        <dd><?= View::e($sejour['telephone'] ?? 'non renseigné') ?></dd>
        <dt>E-mail</dt>
        <dd><?= View::e($sejour['email'] ?? 'non renseigné') ?></dd>
    </dl>

<section class="allergies-dossier" aria-labelledby="titre-allergies">
    <h2 id="titre-allergies">Allergies connues</h2>

    <?php if ($allergyError !== null) : ?>
        <p class="form-error" role="alert"><?= View::e($allergyError) ?></p>
    <?php endif; ?>

    <?php if ($allergies === []) : ?>
        <p class="aucune-allergie">
            <?= View::render('components/icon', ['name' => 'circle-check']) ?>
            Aucune allergie connue
        </p>
    <?php else : ?>
        <ul class="liste-allergies">
            <?php foreach ($allergies as $allergy) : ?>
                <li>
                    <span class="libelle"><?= View::e($allergy['label']) ?></span>
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
                    <form method="post" action="/sejour/allergies/retirer" class="retirer-allergie"
                          data-confirm="<?= View::e(sprintf(
                              'Retirer l\'allergie « %s » du dossier de ce patient ? Elle disparaîtra du bandeau.',
                              $allergy['label']
                          )) ?>">
                        <input type="hidden" name="idSejour" value="<?= View::e($idSejour) ?>">
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
        <p class="referentiel-epuise">Tous les allergènes du référentiel sont déjà enregistrés pour ce patient.</p>
    <?php else : ?>
        <form method="post" action="/sejour/allergies/ajouter" class="ajouter-allergie">
            <input type="hidden" name="idSejour" value="<?= View::e($idSejour) ?>">
            <div class="champ">
                <label for="idSubstance">Allergène</label>
                <select id="idSubstance" name="idSubstance" required>
                    <option value="">Choisir dans la liste</option>
                    <?php foreach ($substances as $id => $description) : ?>
                        <option value="<?= View::e($id) ?>"><?= View::e($description) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="champ">
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
    <h2 class="dossier-subtitle" id="titre-dossier-complet">Dossier complet</h2>
    <?php if ($editable) : ?>
        <p class="dossier-aide">Chaque valeur se modifie en place : cliquez dessus (ou Entrée au clavier),
            puis confirmez l'enregistrement. Échap annule.</p>
    <?php endif; ?>
    <div class="dossier-sections" aria-labelledby="titre-dossier-complet"<?= $editable
        ? ' data-inline-edit data-endpoint="/sejour/champ/modifier" data-csrf="' . View::e($csrfToken) . '"'
            . ' data-id-sejour="' . View::e($idSejour) . '"'
        : '' ?>>
    <?php /* Messages d'enregistrement et d'erreur annoncés aux lecteurs d'écran (aria-live). */ ?>
    <p class="inline-edit__status" id="inline-edit-status" aria-live="polite" hidden></p>
    <?php foreach ($sections as $section) : ?>
        <details class="dossier-section"
                 id="dossier-<?= View::e($section['id']) ?>"<?= $section['filled'] > 0 ? ' open' : '' ?>>
            <summary>
                <span><?= View::e($section['title']) ?></span>
                <span class="dossier-section__count">
                    <?= View::e($section['filled']) ?> sur <?= View::e($section['total']) ?>
                    renseigné<?= $section['filled'] > 1 ? 's' : '' ?>
                </span>
            </summary>
            <dl>
                <?php foreach ($section['rows'] as $row) : ?>
                    <dt><?= View::e($row['label']) ?></dt>
                    <?php if ($editable && !$row['computed']) : ?>
                        <?php /* Bouton focalisable : Entrée ou clic passe en saisie (inline-edit.js). */ ?>
                        <dd class="inline-edit"
                            data-entity="<?= View::e($row['entity']) ?>"
                            data-id="<?= View::e($row['entity'] === 'patient' ? $sejour['idPatient'] : $idSejour) ?>"
                            data-field="<?= View::e($row['field']) ?>"
                            data-type="<?= View::e($row['type']) ?>"
                            data-label="<?= View::e($row['label']) ?>"
                            data-raw="<?= View::e($row['raw'] ?? '') ?>"<?= $row['options'] !== null
                                ? ' data-options="'
                                    . View::e(json_encode($row['options'], JSON_UNESCAPED_UNICODE)) . '"'
                                : '' ?>>
                            <button type="button" class="inline-edit__value"
                                    aria-label="Modifier : <?= View::e($row['label']) ?>"
                                    ><?= $valeurLigne($row) ?></button>
                        </dd>
                    <?php else : ?>
                        <?php /* Valeur collée à la balise : pre-line conserve les retours à la ligne. */ ?>
                        <dd data-entity="<?= View::e($row['entity']) ?>"
                            data-field="<?= View::e($row['field']) ?>"><?= $valeurLigne($row) ?></dd>
                    <?php endif; ?>
                <?php endforeach; ?>
            </dl>
        </details>
    <?php endforeach; ?>

    <?php if ($editable) : ?>
        <?php /* Traçabilité : qui a modifié quoi, de quelle valeur à quelle valeur (table AuditEvent). */ ?>
        <details class="dossier-section dossier-historique" id="dossier-historique">
            <summary>
                <span>Historique des modifications</span>
                <span class="dossier-section__count">
                    <?= count($history) ?> dernière<?= count($history) > 1 ? 's' : '' ?>
                </span>
            </summary>
            <?php if ($history === []) : ?>
                <p class="dossier-historique__vide">Aucune modification enregistrée depuis le dossier.</p>
            <?php else : ?>
                <div class="table-scroll">
                <table class="dossier-historique__table" id="dossier-historique-table">
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
                                    : '<span class="non-renseigne">vide</span>' ?></td>
                                <td><?= $event['newValue'] !== null
                                    ? View::e($event['newValue'])
                                    : '<span class="non-renseigne">vide</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </details>
    <?php endif; ?>
    </div>

    <?php /* RG5 : seules les prescriptions validées par un médecin, et pas celles qui sont arrêtées. */ ?>
    <h2 class="dossier-subtitle">Prescriptions en cours</h2>
    <?php if ($prescriptions === []) : ?>
        <?= View::render('components/empty_state', [
            'title' => 'Aucune prescription validée.',
            'text' => 'Les ordonnances en attente de validation médicale sont sur l\'écran Prescription.',
        ]) ?>
    <?php else : ?>
        <div class="table-scroll">
        <table class="dossier-prescriptions">
            <thead>
                <tr>
                    <th scope="col">Prescription</th>
                    <th scope="col">Fréquence</th>
                    <th scope="col">Début</th>
                    <th scope="col">Fin</th>
                    <th scope="col">Validée par</th>
                    <?php if ($sejour['enCours']) : ?>
                        <th scope="col">Arrêt</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($prescriptions as $line) : ?>
                    <tr>
                        <td>
                            <?= View::e($line['label']) ?>
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
                            <?php foreach ($notes as $note => $prefix) : ?>
                                <?php if ($line[$note] !== null) : ?>
                                    <br><small><?= View::e($prefix . $line[$note]) ?></small>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                        <td><?= View::e($line['frequency']) ?></td>
                        <td><?= View::e($line['startDate']) ?></td>
                        <td><?= View::e($line['endDate'] ?? '—') ?></td>
                        <td><?= View::e($line['validation']) ?></td>
                        <?php if ($sejour['enCours']) : ?>
                            <td>
                                <?php /* AURA-22 : un médecin arrête la ligne ; elle quitte alors cette liste. */ ?>
                                <form class="stop-form" method="post" data-ajax
                                      action="/care-plans/stop?idEpisodeOfCare=<?= View::e($idSejour) ?>">
                                    <input type="hidden" name="idLine" value="<?= View::e($line['idLine']) ?>">
                                    <input type="hidden" name="isMedication"
                                           value="<?= $line['isMedication'] ? '1' : '0' ?>">
                                    <select name="idStoppedBy" aria-label="Médecin qui arrête">
                                        <option value="">— médecin —</option>
                                        <?php foreach ($doctors as $value => $label) : ?>
                                            <option value="<?= View::e($value) ?>"><?= View::e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">Arrêter</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
