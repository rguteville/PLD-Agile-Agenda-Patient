<?php

use Aura\Core\View;
use Aura\Domain\CareTeam;
use Aura\Service\CareTeamService;

/**
 * « Mon planning » (AURA-31) : les soins des patients attribués au soignant pendant la vacation, par
 * ordre chronologique, retards en tête d'écran, patients de l'équipe (allergies RG2) et actions sur
 * chaque soin. Le soignant se choisit dans la liste tant que l'identification n'existe pas (AURA-50).
 *
 * @var array<string, string> $practitioners soignants : id => « Prénom Nom (rôle) »
 * @var string|null $idPractitioner soignant affiché
 * @var array<string, mixed> $shiftView vacation affichée (date, shift, label, previous, next)
 * @var list<CareTeam> $teams équipes du soignant pour la vacation
 * @var array<string, mixed>|null $planning vue-modèle de AgendaService::presentShift()
 * @var bool $validationEnabled
 * @var array<string, string> $validators
 * @var string|null $csrfToken
 */
$teams = $teams ?? [];
$planning = $planning ?? null;
$validationEnabled = $validationEnabled ?? false;
$url = static fn (string $date, string $shift): string => '/mon-planning?idPractitioner='
    . rawurlencode((string) $idPractitioner) . '&date=' . $date . '&vacation=' . strtolower($shift);
?>
<section class="planning-view">
    <div class="page-header">
        <h1 class="form-title">
            Mon planning<?= $idPractitioner !== null ? ' · ' . View::e($practitioners[$idPractitioner]) : '' ?>
        </h1>
        <p class="page-actions">
            <a class="button button--secondary" href="/agenda-service">Agenda du service</a>
            <a class="button button--secondary" href="/equipes">Équipes</a>
        </p>
    </div>

    <form class="agenda-nav" method="get" action="/mon-planning" aria-label="Choisir le soignant et la vacation">
        <label for="planning-practitioner">Soignant</label>
        <select id="planning-practitioner" name="idPractitioner">
            <option value="">— choisir —</option>
            <?php foreach ($practitioners as $id => $label) : ?>
                <option value="<?= View::e($id) ?>"<?= (string) $id === $idPractitioner ? ' selected' : '' ?>>
                    <?= View::e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($idPractitioner !== null) : ?>
            <a class="nav-day" aria-label="Vacation précédente"
               href="<?= View::e($url($shiftView['previous']['date'], $shiftView['previous']['shift'])) ?>">
                <?= View::render('components/icon', ['name' => 'chevron-left']) ?>
            </a>
        <?php endif; ?>
        <span class="agenda-day">
            <?= View::render('components/icon', ['name' => 'calendar']) ?>
            <?= View::e(ucfirst($shiftView['label'])) ?>
        </span>
        <?php if ($idPractitioner !== null) : ?>
            <a class="nav-day" aria-label="Vacation suivante"
               href="<?= View::e($url($shiftView['next']['date'], $shiftView['next']['shift'])) ?>">
                <?= View::render('components/icon', ['name' => 'chevron-right']) ?>
            </a>
        <?php endif; ?>
        <label for="planning-date" class="sr-only">Date</label>
        <input type="date" id="planning-date" name="date" value="<?= View::e($shiftView['date']) ?>">
        <label for="planning-shift" class="sr-only">Vacation</label>
        <select id="planning-shift" name="vacation">
            <?php foreach (CareTeamService::SHIFTS as $key => $shift) : ?>
                <option value="<?= View::e(strtolower($key)) ?>"<?= $key === $shiftView['shift'] ? ' selected' : '' ?>>
                    <?= View::e($shift['label'] . ' (' . $shift['hours'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button button--secondary">Afficher</button>
    </form>

    <?php if ($idPractitioner === null) : ?>
        <?= View::render('components/empty_state', [
            'icon' => 'info',
            'title' => 'Choisissez votre nom pour afficher vos soins de la vacation.',
        ]) ?>
    <?php elseif ($teams === []) : ?>
        <?= View::render('components/empty_state', [
            'icon' => 'info',
            'title' => 'Aucune équipe ne vous est attribuée pour cette vacation.',
            'text' => 'Le cadre compose les équipes dans l\'écran « Équipes ».',
        ]) ?>
    <?php else : ?>
        <p class="planning-teams">
            <?php foreach ($teams as $team) : ?>
                <?php $others = array_diff_key($team->getMembers(), [$idPractitioner => true]); ?>
                <span>
                    <strong><?= View::e($team->getName()) ?></strong><?= $others !== []
                        ? ' avec ' . View::e(implode(', ', $others)) : '' ?>
                </span>
            <?php endforeach; ?>
        </p>

        <?php if ($planning['counts']['late'] > 0) : ?>
            <p class="service-alert" role="alert">
                <?= View::render('components/icon', ['name' => 'clock-alert']) ?>
                <?php $late = $planning['counts']['late']; ?>
                <strong><?= View::e($late) ?> soin<?= $late > 1 ? 's' : '' ?>
                    en retard</strong> parmi vos patients.
            </p>
        <?php endif; ?>

        <div class="planning-layout">
            <aside class="planning-patients" aria-labelledby="planning-patients-title">
                <h2 id="planning-patients-title" class="form-subtitle">
                    Mes patients (<?= View::e(count($planning['patients'])) ?>)
                </h2>
                <ul>
                    <?php foreach ($planning['patients'] as $patient) : ?>
                        <li class="team-card">
                            <?php $agendaUrl = '/agenda-patient?id=' . $patient['idEpisodeOfCare']
                                . '&date=' . $shiftView['date']; ?>
                            <a href="<?= View::e($agendaUrl) ?>">
                                <strong>
                                    <?= $patient['bed'] !== null ? 'Lit ' . View::e($patient['bed']) . ' · ' : '' ?>
                                    <?= View::e($patient['name']) ?>
                                </strong>
                            </a>
                            <?php if (is_array($patient['allergies'])) : ?>
                                <span class="service-patient__allergies">
                                    <?php if ($patient['allergies'] === []) : ?>
                                        <span class="allergy-tag allergy-tag--none">Aucune allergie connue</span>
                                    <?php else : ?>
                                        <span class="sr-only">Allergies :</span>
                                        <?php foreach ($patient['allergies'] as $allergy) : ?>
                                            <span class="allergy-tag allergy-tag--<?= View::e($allergy['level']) ?>">
                                                <?= View::e($allergy['label']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <span class="patient-bed">
                                <?= View::e($patient['late']) ?> en retard · <?= View::e($patient['planned']) ?> à faire
                                · <?= View::e($patient['done']) ?> réalisé<?= $patient['done'] > 1 ? 's' : '' ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </aside>

            <div class="planning-main">
                <?php if ($planning['items'] === []) : ?>
                    <?= View::render('components/empty_state', [
                        'icon' => 'calendar',
                        'title' => 'Aucun soin prévu pour vos patients pendant cette vacation.',
                    ]) ?>
                <?php else : ?>
                    <div class="agenda-filters" role="group" aria-label="Filtrer les soins" data-agenda-filters hidden>
                        <?php foreach (
                        ['all' => 'Tous', 'late' => 'En retard', 'planned' => 'À faire',
                            'done' => 'Réalisés', 'not_done' => 'Non effectués'] as $filter => $label
) : ?>
                            <button type="button" class="button button--secondary" data-filter="<?= View::e($filter) ?>"
                                    aria-pressed="<?= $filter === 'all' ? 'true' : 'false' ?>">
                                <?= View::e($label) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="table-scroll">
                        <table class="agenda-table">
                            <caption class="sr-only">Soins de la vacation, par heure</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="col-time">Heure</th>
                                    <th scope="col">Patient</th>
                                    <th scope="col">Soin / médicament</th>
                                    <th scope="col" class="col-status">Statut</th>
                                    <?php if ($validationEnabled) : ?>
                                        <th scope="col" class="col-action"><span class="sr-only">Actions</span></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($planning['items'] as $item) : ?>
                                    <tr class="is-<?= View::e($item['status']) ?>" data-appointment-row
                                        data-status="<?= View::e($item['status']) ?>">
                                        <td class="col-time">
                                            <time datetime="<?= View::e($item['datetime']) ?>">
                                                <?= View::e($item['time']) ?>
                                            </time>
                                        </td>
                                        <td>
                                            <?php if ($item['bed'] !== null) : ?>
                                                <strong><?= View::e($item['bed']) ?></strong>
                                            <?php endif; ?>
                                            <?= View::e($item['patient']) ?>
                                        </td>
                                        <td>
                                            <span class="item-label"><?= View::e($item['label']) ?></span>
                                            <?php if ($item['details'] !== null) : ?>
                                                <span class="item-details"><?= View::e($item['details']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-status">
                                            <?= View::render('components/status_badge', [
                                                'status' => $item['status'],
                                            ]) ?>
                                            <?php if (($item['performedBy'] ?? null) !== null) : ?>
                                                <span class="trace">par <?= View::e($item['performedBy']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($validationEnabled) : ?>
                                            <td class="col-action">
                                                <?= View::render('components/care_buttons', [
                                                    'item' => $item,
                                                    'idEpisodeOfCare' => $item['idEpisodeOfCare'],
                                                    'context' => $item['patient'],
                                                ]) ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($validationEnabled) : ?>
        <?= View::render('components/validation_dialog', [
            'validators' => $validators,
            'csrfToken' => $csrfToken ?? '',
            'back' => $url($shiftView['date'], $shiftView['shift']),
        ]) ?>
    <?php endif; ?>
</section>
