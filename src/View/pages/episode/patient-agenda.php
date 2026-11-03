<?php

use Aura\Core\View;

/**
 * Agenda du séjour (AURA-24, AURA-25) : bandeau identité + allergies en tête (RG2), puis
 * - vue Jour : retards épinglés en tête, puis les soins par rotation, avec filtres par statut et
 *   actions sur chaque soin (valider, non effectué, note : AURA-26, AURA-28) ;
 * - vue Semaine : une ligne par soin prescrit, une colonne par jour (lundi → dimanche).
 * Toute la logique (filtre du jour, tri, retard, rotations, semaine) est dans AgendaService,
 * testée sans base.
 *
 * @var array<string, mixed> $banner vue-modèle de PatientRecordService::presentBanner()
 * @var int $idEpisodeOfCare
 * @var string $view 'day' ou 'week'
 * @var array<string, mixed> $agenda vue-modèle de AgendaService::presentDay()
 * @var array<string, mixed>|null $week vue-modèle de AgendaService::presentWeek() (vue Semaine)
 * @var bool $validationEnabled boutons d'action + dialogue (séjour en cours)
 * @var array<string, string> $validators soignants proposés par le dialogue
 * @var string|null $csrfToken jeton de POST /agenda-patient/soin
 */
$view = $view ?? 'day';
$week = $week ?? null;
$validationEnabled = $validationEnabled ?? false;
$validators = $validators ?? [];
$isWeek = $view === 'week' && $week !== null;
$dayUrl = static fn (string $date): string => '/agenda-patient?id=' . $idEpisodeOfCare . '&date=' . $date;
$weekUrl = static fn (string $date): string => $dayUrl($date) . '&vue=semaine';
?>

<?= View::render('components/patient_banner', ['banner' => $banner]) ?>
<?= View::render('components/breadcrumb', ['items' => [
    ['label' => 'Patients', 'href' => '/patients'],
    ['label' => 'Dossier du séjour n° ' . $banner['episodeNumber'], 'href' => '/sejour?id=' . $idEpisodeOfCare],
    ['label' => 'Agenda'],
]]) ?>

<?php
// Même menu que le dossier : le bouton « Dossier du séjour » est exactement là où se trouve
// « Agenda du jour » dans le dossier (retours du 2026-11-02).
$recordUrl = '/sejour?id=' . $idEpisodeOfCare;
?>
<div class="record-layout">
<?= View::render('components/episode_menu', [
    'primary' => ['href' => $recordUrl, 'label' => 'Dossier du séjour', 'icon' => 'inbox'],
    'items' => [
        ['href' => $recordUrl . '#dossier-resume', 'label' => 'Dossier'],
        ['href' => $recordUrl . '#titre-allergies', 'label' => 'Allergies'],
        ['href' => $recordUrl . '#titre-dossier-complet', 'label' => 'Dossier complet'],
        ['href' => $recordUrl . '#titre-prescriptions', 'label' => 'Prescriptions en cours'],
    ],
]) ?>

<section class="agenda">
    <div class="page-header">
        <h1 class="form-title">Agenda du séjour n° <?= View::e($banner['episodeNumber']) ?></h1>
        <p class="page-actions">
            <span class="view-switch" role="group" aria-label="Affichage">
                <a class="button<?= $isWeek ? ' button--secondary' : '' ?>"
                   href="<?= View::e($dayUrl($agenda['date'])) ?>"<?= $isWeek ? '' : ' aria-current="page"' ?>>Jour</a>
                <a class="button<?= $isWeek ? '' : ' button--secondary' ?>"
                   href="<?= View::e($weekUrl($agenda['date'])) ?>"<?= $isWeek
                        ? ' aria-current="page"' : '' ?>>Semaine</a>
            </span>
            <button type="button" id="print-page" class="button button--secondary">
                <?= View::render('components/icon', ['name' => 'printer']) ?>
                Imprimer
            </button>
        </p>
    </div>

    <form class="agenda-nav" data-auto-submit-form method="get" action="/agenda-patient"
          aria-label="<?= $isWeek ? 'Choisir la semaine affichée' : 'Choisir le jour affiché' ?>">
        <input type="hidden" name="id" value="<?= View::e($idEpisodeOfCare) ?>">
        <?php if ($isWeek) : ?>
            <input type="hidden" name="vue" value="semaine">
            <a class="nav-day" href="<?= View::e($weekUrl($week['previous'])) ?>" aria-label="Semaine précédente">
                <?= View::render('components/icon', ['name' => 'chevron-left']) ?>
            </a>
            <span class="agenda-day">
                <?= View::render('components/icon', ['name' => 'calendar']) ?>
                <label for="date">Semaine <?= View::e($week['label']) ?></label>
            </span>
            <a class="nav-day" href="<?= View::e($weekUrl($week['next'])) ?>" aria-label="Semaine suivante">
                <?= View::render('components/icon', ['name' => 'chevron-right']) ?>
            </a>
        <?php else : ?>
            <a class="nav-day" href="<?= View::e($dayUrl($agenda['previous'])) ?>" aria-label="Jour précédent">
                <?= View::render('components/icon', ['name' => 'chevron-left']) ?>
            </a>
            <span class="agenda-day">
                <?= View::render('components/icon', ['name' => 'calendar']) ?>
                <label for="date"><?= View::e(ucfirst($agenda['dateLabel'])) ?></label>
            </span>
            <a class="nav-day" href="<?= View::e($dayUrl($agenda['next'])) ?>" aria-label="Jour suivant">
                <?= View::render('components/icon', ['name' => 'chevron-right']) ?>
            </a>
        <?php endif; ?>
        <input type="date" id="date" name="date" value="<?= View::e($agenda['date']) ?>">
        <button type="submit" class="button button--secondary" data-submit-fallback>Afficher</button>
        <?php if (!$agenda['isToday']) : ?>
            <a href="<?= View::e($isWeek ? $weekUrl('') : '/agenda-patient?id=' . $idEpisodeOfCare) ?>">Aujourd'hui</a>
        <?php endif; ?>
    </form>

    <?php $counts = $isWeek ? $week['counts'] : $agenda['counts']; ?>
    <ul class="stats" aria-label="<?= $isWeek ? 'Résumé de la semaine' : 'Résumé de la journée' ?>">
        <li><span class="stat-value"><?= View::e($counts['total']) ?></span> au total</li>
        <li class="stat--late"><span class="stat-value"><?= View::e($counts['late']) ?></span> en retard</li>
        <li><span class="stat-value"><?= View::e($counts['done']) ?></span> effectués</li>
        <li><span class="stat-value"><?= View::e($counts['planned']) ?></span> à venir</li>
    </ul>

    <?php if ($isWeek) : ?>
        <?php if ($week['rows'] === []) : ?>
            <?= View::render('components/empty_state', [
                'icon' => 'calendar',
                'title' => 'Aucun soin ni médicament prévu cette semaine.',
            ]) ?>
        <?php else : ?>
            <div class="table-scroll">
                <table class="week-grid">
                    <caption class="sr-only">Soins de la semaine <?= View::e($week['label']) ?>, par jour</caption>
                    <thead>
                        <tr>
                            <th scope="col">Soin / médicament</th>
                            <?php foreach ($week['days'] as $day) : ?>
                                <th scope="col"<?= $day['isToday'] ? ' class="is-today"' : '' ?>>
                                    <a href="<?= View::e($dayUrl($day['date'])) ?>"
                                       aria-label="Voir le <?= View::e($day['label']) ?> en vue Jour">
                                        <?= View::e(ucfirst($day['label'])) ?>
                                    </a>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($week['rows'] as $row) : ?>
                            <tr>
                                <th scope="row">
                                    <span class="item-label"><?= View::e($row['label']) ?></span>
                                    <span class="item-details">
                                        <?= View::e($row['kindLabel']) ?><?= $row['details'] !== null
                                            ? ', ' . View::e($row['details']) : '' ?>
                                    </span>
                                </th>
                                <?php foreach ($week['days'] as $day) : ?>
                                    <td<?= $day['isToday'] ? ' class="is-today"' : '' ?>>
                                        <?php foreach ($row['cells'][$day['date']] ?? [] as $item) : ?>
                                            <?= View::render('components/status_badge', [
                                                'status' => $item['status'],
                                                'label' => $item['time'],
                                            ]) ?>
                                        <?php endforeach; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="agenda-empty">Chaque horaire porte son statut (icône et couleur). Cliquez un jour pour
                valider ses soins.</p>
        <?php endif; ?>
    <?php elseif ($agenda['counts']['total'] === 0) : ?>
        <?= View::render('components/empty_state', [
            'icon' => 'calendar',
            'title' => 'Aucun soin ni médicament prévu ce jour.',
            'text' => 'Les soins apparaissent ici dès qu\'une prescription validée les planifie.',
        ]) ?>
    <?php else : ?>
        <?php /* Retours du 2026-11-04 : filtres regroupés derrière un bouton (agenda.js). */ ?>
        <details class="filter-menu" data-agenda-filters hidden>
            <summary class="button button--secondary">
                Filtres<span class="filter-menu__state" data-filter-state></span>
            </summary>
            <div class="agenda-filters" role="group" aria-label="Filtrer les soins">
                <?php foreach (
                ['all' => 'Tous', 'late' => 'En retard', 'planned' => 'À faire',
                    'done' => 'Réalisés', 'not_done' => 'Non effectués'] as $filter => $label
) : ?>
                    <button type="button" class="button button--secondary" data-filter="<?= View::e($filter) ?>"
                            aria-pressed="<?= $filter === 'all' ? 'true' : 'false' ?>"><?= View::e($label) ?></button>
                <?php endforeach; ?>
            </div>
        </details>

        <?php if ($agenda['late'] !== []) : ?>
            <?php /* Figure 4 du cahier des charges : les retards restent en tête, quelle que soit la rotation. */ ?>
            <section class="agenda-section agenda-section--late" aria-labelledby="agenda-late-title">
                <h2 id="agenda-late-title">
                    <?= View::render('components/icon', ['name' => 'clock-alert']) ?>
                    En retard
                    <span class="count">(<?= View::e(count($agenda['late'])) ?>)</span>
                </h2>
                <?= View::render('components/agenda_table', [
                    'items' => $agenda['late'],
                    'caption' => 'Soins en retard',
                    'validationEnabled' => $validationEnabled,
                    'idEpisodeOfCare' => $idEpisodeOfCare,
                ]) ?>
            </section>
        <?php endif; ?>

        <?php foreach ($agenda['shifts'] as $shift) : ?>
            <?php /* Audit IHM du 2026-11-04 : une rotation sans soin tient sur une ligne discrète. */ ?>
            <section class="agenda-section<?= $shift['items'] === [] ? ' is-empty' : '' ?>"
                     aria-labelledby="agenda-<?= View::e($shift['key']) ?>-title">
                <h2 id="agenda-<?= View::e($shift['key']) ?>-title">
                    <?= View::e($shift['label']) ?>
                    <?php $n = count($shift['items']); ?>
                    <?php $count = 'de ' . $shift['hours'] . ', ' . $n . ' soin' . ($n > 1 ? 's' : ''); ?>
                    <span class="count"><?= View::e($count) ?></span>
                    <?php if ($shift['items'] === []) : ?>
                        <span class="agenda-empty">rien de prévu</span>
                    <?php endif; ?>
                </h2>
                <?php if ($shift['items'] !== []) : ?>
                    <?= View::render('components/agenda_table', [
                        'items' => $shift['items'],
                        'caption' => 'Rotation ' . $shift['label'] . ', ' . $shift['hours'],
                        'validationEnabled' => $validationEnabled,
                        'idEpisodeOfCare' => $idEpisodeOfCare,
                    ]) ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($validationEnabled) : ?>
        <?= View::render('components/validation_dialog', [
            'validators' => $validators,
            'csrfToken' => $csrfToken ?? '',
            'back' => $dayUrl($agenda['date']),
        ]) ?>
    <?php endif; ?>
</section>
</div>
