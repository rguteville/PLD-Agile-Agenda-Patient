<?php

use Aura\Core\View;

/**
 * Agenda du service (AURA-29, route GET /agenda-service) : tous les patients en cours × créneaux
 * horaires d'une journée, avec le nombre de retards par patient. Vue-modèle construit par
 * AgendaService::presentService(). Les cellules montrent un badge par statut avec son effectif :
 * jamais la couleur seule.
 *
 * @var array{date: string, dateLabel: string, isToday?: bool, previous?: string, next?: string,
 *            slots: list<string>, late?: int,
 *            patients: list<array{idEpisodeOfCare: int, lastName: string, firstName: string, bed: ?string,
 *                                 episodeNumber: string, late: int,
 *                                 cells: array<string, array<string, int>>}>} $service
 *      cells : créneau => [clé de statut => nombre de soins]
 * @var array<string, string> $services référentiel des services (choix du service affiché)
 * @var string|null $idHealthcareService service affiché
 */
$services = $services ?? [];
$idHealthcareService = $idHealthcareService ?? null;
$dayUrl = static fn (string $date): string => '/agenda-service?idHealthcareService='
    . rawurlencode((string) $idHealthcareService) . '&date=' . $date;
?>
<section class="service-view">
    <div class="page-header">
        <h1 class="form-title">
            Agenda du service<?= $idHealthcareService !== null && isset($services[$idHealthcareService])
                ? ' · ' . View::e($services[$idHealthcareService]) : '' ?>
        </h1>
    </div>

    <?php if ($services !== [] && isset($service['previous'], $service['next'])) : ?>
        <form class="agenda-nav" method="get" action="/agenda-service" aria-label="Choisir le service et le jour">
            <label for="service-select" class="sr-only">Service</label>
            <select id="service-select" name="idHealthcareService">
                <?php foreach ($services as $id => $name) : ?>
                    <option value="<?= View::e($id) ?>"<?= (string) $id === $idHealthcareService
                        ? ' selected' : '' ?>><?= View::e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <a class="nav-day" href="<?= View::e($dayUrl($service['previous'])) ?>" aria-label="Jour précédent">
                <?= View::render('components/icon', ['name' => 'chevron-left']) ?>
            </a>
            <span class="agenda-day">
                <?= View::render('components/icon', ['name' => 'calendar']) ?>
                <label for="date"><?= View::e(ucfirst($service['dateLabel'])) ?></label>
            </span>
            <a class="nav-day" href="<?= View::e($dayUrl($service['next'])) ?>" aria-label="Jour suivant">
                <?= View::render('components/icon', ['name' => 'chevron-right']) ?>
            </a>
            <input type="date" id="date" name="date" value="<?= View::e($service['date']) ?>">
            <button type="submit" class="button button--secondary">Afficher</button>
            <?php if (!($service['isToday'] ?? true)) : ?>
                <a class="button button--secondary" href="<?= View::e($dayUrl('')) ?>">Aujourd'hui</a>
            <?php endif; ?>
        </form>
    <?php else : ?>
        <p class="agenda-day"><?= View::e(ucfirst($service['dateLabel'])) ?></p>
    <?php endif; ?>

    <?php if (($service['late'] ?? 0) > 0) : ?>
        <p class="form-error" role="status">
            <?= View::e($service['late']) ?> soin<?= $service['late'] > 1 ? 's' : '' ?> en retard dans le service.
        </p>
    <?php endif; ?>

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
