<?php

use Aura\Core\View;

/**
 * Vue consolidée du service : tous les patients × créneaux horaires d'une journée, avec le nombre de
 * retards par patient. BALISAGE SEUL : aucune route ni requête « tous patients » n'existe encore
 * (logique métier à créer dans son ticket) ; ce gabarit fixe le contrat du vue-modèle et est couvert
 * par un test de rendu. Les cellules montrent un badge par statut avec son effectif : jamais la
 * couleur seule.
 *
 * @var array{date: string, dateLabel: string, slots: list<string>,
 *            patients: list<array{idSejour: int, nom: string, prenom: string, lit: ?string,
 *                                 numeroSejour: string, late: int,
 *                                 cells: array<string, array<string, int>>}>} $service
 *      cells : créneau => [clé de statut => nombre de soins]
 */
?>
<section class="service-view">
    <div class="page-header">
        <h1 class="form-title">Vue du service · <?= View::e(ucfirst($service['dateLabel'])) ?></h1>
    </div>

    <?php if ($service['patients'] === []) : ?>
        <?= View::render('components/empty_state', [
            'title' => 'Aucun patient dans le service ce jour.',
        ]) ?>
    <?php else : ?>
        <div class="table-scroll">
            <table class="service-grid">
                <caption class="sr-only">
                    Soins par patient et par créneau, <?= View::e($service['dateLabel']) ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col" class="col-patient">Patient</th>
                        <?php foreach ($service['slots'] as $slot) : ?>
                            <th scope="col"><?= View::e($slot) ?></th>
                        <?php endforeach; ?>
                        <th scope="col" class="col-late">Retards</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($service['patients'] as $patient) : ?>
                        <tr>
                            <th scope="row" class="col-patient">
                                <?php $agendaUrl = '/agenda-patient?id=' . $patient['idEpisodeOfCare']
                                    . '&date=' . $service['date']; ?>
                                <a href="<?= View::e($agendaUrl) ?>">
                                    <span class="patient-name"><?= View::e($patient['lastName']) ?></span>
                                    <?= View::e($patient['firstName']) ?>
                                </a>
                                <span class="patient-bed">
                                    <?= $patient['bed'] !== null ? 'Lit ' . View::e($patient['bed']) . ' · ' : '' ?>
                                    séjour n° <?= View::e($patient['episodeNumber']) ?>
                                </span>
                            </th>
                            <?php foreach ($service['slots'] as $slot) : ?>
                                <td class="cell-slot">
                                    <?php $cell = $patient['cells'][$slot] ?? []; ?>
                                    <?php if ($cell === []) : ?>
                                        <span class="sr-only">Rien de prévu</span>
                                    <?php else : ?>
                                        <span class="slot-items">
                                            <?php foreach ($cell as $status => $count) : ?>
                                                <?= View::render('components/status_badge', [
                                                    'status' => $status,
                                                    'label' => (string) $count,
                                                ]) ?>
                                            <?php endforeach; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="col-late">
                                <?php if ($patient['late'] > 0) : ?>
                                    <?= View::render('components/status_badge', [
                                        'status' => 'late',
                                        'label' => $patient['late'] . ' en retard',
                                    ]) ?>
                                <?php else : ?>
                                    <span class="sr-only">Aucun retard</span>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <ul class="service-legend" aria-label="Légende">
            <?php foreach (['planned', 'late', 'done', 'not_done', 'cancelled'] as $status) : ?>
                <li><?= View::render('components/status_badge', ['status' => $status]) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
