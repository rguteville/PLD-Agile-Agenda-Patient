<?php

use Aura\Core\View;
use Aura\Domain\CareTeam;
use Aura\Service\CareTeamService;

/**
 * « Mon planning » (AURA-31) : les soins des patients attribués au soignant pendant la rotation, par
 * ordre chronologique, retards en tête d'écran, patients de l'équipe (allergies RG2) et actions sur
 * chaque soin (« Valider » et « Note », components/care_buttons). Le soignant se choisit dans la liste
 * tant que l'identification n'existe pas (AURA-50).
 * Rendue par TeamController::planning() (GET /mon-planning?idPractitioner=…&date=…&rotation=…).
 * Scripts : agenda.js (filtres, dialogue), auto-submit.js (choix du soignant et de la rotation).
 *
 * @var array<string, string> $practitioners soignants : id => « Prénom Nom (rôle) »
 * @var string|null $idPractitioner soignant affiché
 * @var array<string, mixed> $shiftView rotation affichée (date, shift, label, previous et next :
 *      [date, shift])
 * @var list<CareTeam> $teams équipes du soignant pour la rotation, [] par défaut
 * @var array<string, mixed>|null $planning vue-modèle de AgendaService::presentShift() (items, patients,
 *      counts), null par défaut (aucun soignant choisi)
 * @var bool $validationEnabled boutons d'action + dialogue, false par défaut
 * @var array<string, string> $validators soignants proposés par le dialogue
 * @var string|null $csrfToken jeton de POST /agenda-patient/soin, null sans validation
 */
$teams = $teams ?? [];
$planning = $planning ?? null;
$validationEnabled = $validationEnabled ?? false;
$url = static fn (string $date, string $shift): string => '/mon-planning?idPractitioner='
    . rawurlencode((string) $idPractitioner) . '&date=' . $date . '&rotation=' . CareTeamService::urlValue($shift);
?>
<section class="planning-view">
    <div class="page-header">
        <h1 class="form-title">
            Mon planning<?= $idPractitioner !== null ? ' : ' . View::e($practitioners[$idPractitioner]) : '' ?>
        </h1>
        <p class="page-actions">
            <a class="button button--secondary" href="/agenda-service">Agenda du service</a>
            <a class="button button--secondary" href="/equipes">Équipes</a>
        </p>
    </div>

    <form class="agenda-nav" data-auto-submit-form
          method="get" action="/mon-planning" aria-label="Choisir le soignant et la rotation">
        <label for="planning-practitioner">Soignant</label>
        <select id="planning-practitioner" name="idPractitioner">
            <option value="">Choisir</option>
            <?php foreach ($practitioners as $id => $label) : ?>
                <option value="<?= View::e($id) ?>"<?= (string) $id === $idPractitioner ? ' selected' : '' ?>>
                    <?= View::e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($idPractitioner !== null) : ?>
            <a class="nav-day" aria-label="Rotation précédente"
               href="<?= View::e($url($shiftView['previous']['date'], $shiftView['previous']['shift'])) ?>">
                <?= View::render('components/icon', ['name' => 'chevron-left']) ?>
            </a>
        <?php endif; ?>
        <span class="agenda-day">
            <?= View::render('components/icon', ['name' => 'calendar']) ?>
            <?= View::e(ucfirst($shiftView['label'])) ?>
        </span>
        <?php if ($idPractitioner !== null) : ?>
            <a class="nav-day" aria-label="Rotation suivante"
               href="<?= View::e($url($shiftView['next']['date'], $shiftView['next']['shift'])) ?>">
                <?= View::render('components/icon', ['name' => 'chevron-right']) ?>
            </a>
        <?php endif; ?>
        <label for="planning-date" class="sr-only">Date</label>
        <input type="date" id="planning-date" name="date" value="<?= View::e($shiftView['date']) ?>">
        <label for="planning-shift" class="sr-only">Rotation</label>
        <select id="planning-shift" name="rotation">
            <?php foreach (CareTeamService::SHIFTS as $key => $shift) : ?>
                <?php $selected = $key === $shiftView['shift'] ? ' selected' : ''; ?>
                <option value="<?= View::e(CareTeamService::urlValue($key)) ?>"<?= $selected ?>>
                    <?= View::e($shift['label'] . ' (' . $shift['hours'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button button--secondary" data-submit-fallback>Afficher</button>
    </form>

    <?php if ($idPractitioner === null) : ?>
        <?= View::render('components/empty_state', [
            'icon' => 'info',
            'title' => 'Choisissez votre nom pour afficher vos soins de la rotation.',
        ]) ?>
    <?php elseif ($teams === []) : ?>
        <?= View::render('components/empty_state', [
            'icon' => 'info',
            'title' => 'Aucune équipe ne vous est attribuée pour cette rotation.',
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
                                    <?= $patient['bed'] !== null ? 'Lit ' . View::e($patient['bed']) . ', ' : '' ?>
                                    <?= View::e($patient['name']) ?>
                                </strong>
                            </a>
                            <?php if (($patient['deceased'] ?? null) !== null) : ?>
                                <?= View::render('components/deceased_tag', ['label' => $patient['deceased']]) ?>
                            <?php endif; ?>
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
                                <?= View::e($patient['late']) ?> en retard, <?= View::e($patient['planned']) ?> à faire,
                                <?= View::e($patient['done']) ?> réalisé<?= $patient['done'] > 1 ? 's' : '' ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </aside>

            <div class="planning-main">
                <?php if ($planning['items'] === []) : ?>
                    <?= View::render('components/empty_state', [
                        'icon' => 'calendar',
                        'title' => 'Aucun soin prévu pour vos patients pendant cette rotation.',
                    ]) ?>
                <?php else : ?>
                    <?php /* Filtres regroupés derrière un bouton (agenda.js). */ ?>
                    <details class="filter-menu" data-agenda-filters hidden>
                        <summary class="button button--secondary">
                            Filtres<span class="filter-menu__state" data-filter-state></span>
                        </summary>
                        <div class="agenda-filters" role="group" aria-label="Filtrer les soins">
                            <?php
                            $filters = [
                                'all' => 'Tous', 'late' => 'En retard', 'planned' => 'À faire',
                                'done' => 'Réalisés', 'not_done' => 'Non effectués',
                            ];
                            ?>
                            <?php foreach ($filters as $filter => $label) : ?>
                                <button type="button" class="button button--secondary"
                                        data-filter="<?= View::e($filter) ?>"
                                        aria-pressed="<?= $filter === 'all' ? 'true' : 'false' ?>">
                                    <?= View::e($label) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <div class="table-scroll">
                        <table class="agenda-table">
                            <caption class="sr-only">Soins de la rotation, par heure</caption>
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
                                            <?php /* Le « + » de la prescription à droite du titre. */ ?>
                                            <span class="care-title">
                                                <span class="item-label"><?= View::e($item['label']) ?></span>
                                                <?= View::render('components/prescription_details', [
                                                    'item' => $item,
                                                ]) ?>
                                            </span>
                                            <?php if ($item['details'] !== null) : ?>
                                                <span class="item-details"><?= View::e($item['details']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-status">
                                            <?= View::render('components/status_badge', [
                                                'status' => $item['status'],
                                            ]) ?>
                                            <?php if ($item['notValidated'] ?? false) : ?>
                                                <?= View::render('components/to_validate_link', [
                                                    'idEpisodeOfCare' => $item['idEpisodeOfCare'],
                                                    'idCarePlan' => $item['idCarePlan'] ?? null,
                                                ]) ?>
                                            <?php endif; ?>
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
