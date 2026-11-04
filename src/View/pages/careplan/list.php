<?php

use Aura\Core\View;
use Aura\Domain\CarePlan;

/**
 * Prescriptions d'un séjour (Epic 4, tableau des prescriptions, figure 10 du cahier des charges).
 * Vue minimale pour tester le back : le front peut la remplacer en gardant les mêmes données.
 * Rendue par CarePlanController::list() (GET /care-plans?idEpisodeOfCare=…), données de
 * CarePlanService::listing(). Aucun script propre à la page.
 *
 * @var array<string, mixed> $banner vue-modèle de PatientRecordService::presentBanner()
 * @var int $idEpisodeOfCare
 * @var bool $isOpen séjour en cours (bouton « Nouvelle prescription »)
 * @var list<array<string, mixed>> $lines vue-modèle de CarePlanService::presentLine() (label, frequency,
 *      period, mode, prescriber, status, validation, stop, idCarePlan, allergen, carePlanNote et les
 *      champs de consigne : important, nurseInstructions, maxDose…)
 */
?>
<?= View::render('components/patient_banner', ['banner' => $banner]) ?>
<?= View::render('components/breadcrumb', ['items' => [
    ['label' => 'Patients', 'href' => '/patients'],
    ['label' => 'Dossier du séjour n° ' . $banner['episodeNumber'], 'href' => '/sejour?id=' . $idEpisodeOfCare],
    ['label' => 'Prescriptions'],
]]) ?>

<section class="care-plans">
    <div class="page-header">
        <h1 class="form-title">Prescriptions du séjour n° <?= View::e($banner['episodeNumber']) ?></h1>
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
                    <th scope="col">Période</th>
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
                            <?php /* Retours du 2026-11-03 : allergène du patient et note de l'ordonnance. */ ?>
                            <?php if (($line['allergen'] ?? null) !== null) : ?>
                                <br><strong class="allergy-flag">Contient un allergène du patient :
                                    <?= View::e($line['allergen']) ?></strong>
                            <?php endif; ?>
                            <?php if (($line['carePlanNote'] ?? null) !== null) : ?>
                                <br><small class="care-plan-note">Note de l'ordonnance n°
                                    <?= View::e($line['idCarePlan']) ?> : <?= View::e($line['carePlanNote']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= View::e($line['frequency']) ?></td>
                        <td><?= View::e($line['period']) ?></td>
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
                                <?php /* Cliquable : mène à la validation de l'ordonnance (2026-11-04). */ ?>
                                <?= View::render('components/to_validate_link', [
                                    'idEpisodeOfCare' => $idEpisodeOfCare,
                                    'idCarePlan' => $line['idCarePlan'] ?? null,
                                ]) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
