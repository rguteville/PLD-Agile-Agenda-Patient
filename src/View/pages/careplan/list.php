<?php

use Aura\Core\View;
use Aura\Domain\CarePlan;

/**
 * Prescriptions d'un séjour (Epic 4, tableau des prescriptions, figure 10 du cahier des charges).
 * Vue minimale pour tester le back : le front peut la remplacer en gardant les mêmes données.
 *
 * @var array<string, mixed> $banner vue-modèle de DossierPatientService::presenterBandeau()
 * @var int $idEpisodeOfCare
 * @var bool $isOpen séjour en cours
 * @var list<array<string, mixed>> $lines vue-modèle de CarePlanService::presentLine()
 */
?>
<?= View::render('components/bandeau_patient', ['bandeau' => $banner]) ?>

<section class="care-plans">
    <div class="page-header">
        <h1 class="form-title">Prescriptions du séjour n° <?= View::e($banner['numeroSejour']) ?></h1>
        <p class="page-actions">
            <a class="button button--secondary"
               href="/sejour?id=<?= View::e($idEpisodeOfCare) ?>">Dossier du séjour</a>
            <?php if ($isOpen) : ?>
                <a class="button" href="/care-plans/new?idEpisodeOfCare=<?= View::e($idEpisodeOfCare) ?>">
                    Nouvelle prescription
                </a>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($lines === []) : ?>
        <?= View::render('components/empty_state', [
            'title' => 'Aucune prescription pour ce séjour.',
            'text' => $isOpen ? 'Créez la première depuis « Nouvelle prescription ».' : 'Le séjour est clos.',
        ]) ?>
    <?php else : ?>
        <div class="table-scroll">
        <table class="care-plan-lines">
            <thead>
                <tr>
                    <th scope="col">Prescription</th>
                    <th scope="col">Fréquence</th>
                    <th scope="col">Début</th>
                    <th scope="col">Fin</th>
                    <th scope="col">Mode</th>
                    <th scope="col">Prescripteur</th>
                    <th scope="col">Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line) : ?>
                    <tr class="status-<?= View::e(strtolower($line['status'])) ?>">
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
                        <td><?= View::e($line['mode']) ?></td>
                        <td><?= View::e($line['prescriber']) ?></td>
                        <td class="col-status">
                            <?php if ($line['status'] === CarePlan::STATUS_STOPPED) : ?>
                                <?= View::render('components/status_badge', ['status' => 'stopped']) ?>
                                <span class="trace">par <?= View::e($line['stop']) ?></span>
                            <?php elseif ($line['status'] === CarePlan::STATUS_VALIDATED) : ?>
                                <?= View::render('components/status_badge', ['status' => 'validated']) ?>
                                <span class="trace">par <?= View::e($line['validation']) ?></span>
                            <?php else : ?>
                                <?php /* Le « petit stylo » du SI réel (RG5) : visible mais non validée. */ ?>
                                <?= View::render('components/status_badge', ['status' => 'to_validate']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
