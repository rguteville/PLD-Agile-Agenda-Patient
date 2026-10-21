<?php

use Aura\Core\View;

/**
 * @var array<string, mixed> $bandeau
 * @var array<string, mixed> $sejour
 * @var int $idSejour
 * @var list<array<string, mixed>> $prescriptions lignes validées, vue-modèle de CarePlanService::presentLine()
 * @var array<string, string> $doctors médecins pouvant arrêter une ligne : idPractitioner => « nom (rôle) »
 */
?>
<?= View::render('components/bandeau_patient', ['bandeau' => $bandeau]) ?>

<section class="dossier">
    <h1 class="form-title">Dossier du séjour n° <?= View::e($bandeau['numeroSejour']) ?></h1>
    <?php if ($sejour['enCours']) : ?>
        <?php /* AURA-20 : vers l'écran B3 « Nouvelles prescriptions » (séjour en cours seulement). */ ?>
        <p class="episode-actions">
            <a class="button" href="/care-plans/new?idEpisodeOfCare=<?= View::e($idSejour) ?>">Prescription</a>
        </p>
    <?php endif; ?>
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
        <dt>Poids à l'admission</dt>
        <dd><?= View::e($sejour['poids'] ?? 'non renseigné') ?></dd>
        <dt>Taille à l'admission</dt>
        <dd><?= View::e($sejour['taille'] ?? 'non renseignée') ?></dd>
        <dt>Précautions</dt>
        <?php /* « non renseignées », pas « aucune » : rien de saisi ne veut pas dire aucun risque. */ ?>
        <dd><?= isset($sejour['precautions']) ? nl2br(View::e($sejour['precautions'])) : 'non renseignées' ?></dd>
        <dt>Téléphone</dt>
        <dd><?= View::e($sejour['telephone'] ?? 'non renseigné') ?></dd>
        <dt>E-mail</dt>
        <dd><?= View::e($sejour['email'] ?? 'non renseigné') ?></dd>
    </dl>
    <p class="a-venir">Allergies (AURA-13), prescriptions (AURA-20) et agenda (AURA-25) viendront s'afficher ici.</p>

    <?php /* RG5 : seules les prescriptions validées par un médecin, et pas celles qui sont arrêtées. */ ?>
    <h2 class="dossier-subtitle">Prescriptions en cours</h2>
    <?php if ($prescriptions === []) : ?>
        <p class="liste-vide">Aucune prescription validée.</p>
    <?php else : ?>
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
    <?php endif; ?>
    <p><a href="/care-plans?idEpisodeOfCare=<?= View::e($idSejour) ?>">Toutes les prescriptions du séjour</a></p>
    <a href="/agenda-patient?numSejour=<?= urlencode((string)$bandeau['numeroSejour']) ?>">Vers l'agenda.</a>
</section>
