<?php

use Aura\Core\View;

/**
 * Agenda du service (AURA-29, route GET /agenda-service). Refonte du 2026-11-04 (maquettes A + B
 * choisies par Raphaël : https://claude.ai/artifact/CmNLWFne8QUYcPzGyUeUDn), l'ancienne grille
 * créneaux × patients montrant trop d'informations à la fois :
 *  - « Lits » (par défaut) : une ligne par patient, du plus urgent au moins urgent (retards, à faire dans
 *    la rotation, prochain soin), et un panneau de détail du lit choisi (?patient=…) avec ses soins de la
 *    rotation et les actions (valider, note : AURA-26, AURA-28) ;
 *  - « Liste de travail » (?vue=liste) : les soins de la rotation de tout le service, en retard (AURA-32),
 *    maintenant (deux heures), plus tard (replié), réalisés (replié).
 * RG2 : allergies sur chaque ligne et dans le panneau. Vue-modèle : AgendaService::presentBoard().
 *
 * @var array<string, mixed> $service vue-modèle de AgendaService::presentService() (jour, navigation)
 * @var array<string, mixed> $board vue-modèle de AgendaService::presentBoard()
 * @var string $view « lits » ou « liste »
 * @var array<string, string> $services référentiel des services (choix du service affiché)
 * @var string|null $idHealthcareService service affiché
 * @var bool $validationEnabled actions sur les soins + dialogue
 * @var array<string, string> $validators soignants proposés par le dialogue
 * @var string|null $csrfToken jeton de POST /agenda-patient/soin
 */
$services = $services ?? [];
$idHealthcareService = $idHealthcareService ?? null;
$validationEnabled = $validationEnabled ?? false;
$validators = $validators ?? [];
$view = $view ?? 'lits';
$dayUrl = static fn (string $date, ?string $mode = null, ?int $patient = null): string
    => '/agenda-service?idHealthcareService=' . rawurlencode((string) $idHealthcareService) . '&date=' . $date
    . (($mode ?? $view) === 'liste' ? '&vue=liste' : '') . ($patient !== null ? '&patient=' . $patient : '');
$agendaUrl = static fn (int $idEpisodeOfCare): string => '/agenda-patient?id=' . $idEpisodeOfCare
    . '&date=' . $service['date'];
?>
<section class="service-view">
    <div class="page-header">
        <h1 class="form-title">
            Agenda du service<?= $idHealthcareService !== null && isset($services[$idHealthcareService])
                ? ' · ' . View::e($services[$idHealthcareService]) : '' ?>
        </h1>
        <p class="page-actions">
            <button type="button" id="print-page" class="button button--secondary">
                <?= View::render('components/icon', ['name' => 'printer']) ?>
                Imprimer
            </button>
        </p>
    </div>

    <?php if ($services !== [] && isset($service['previous'], $service['next'])) : ?>
        <form class="agenda-nav" data-auto-submit-form
              method="get" action="/agenda-service" aria-label="Choisir le service et le jour">
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
            <?php if ($view === 'liste') : ?>
                <input type="hidden" name="vue" value="liste">
            <?php endif; ?>
            <button type="submit" class="button button--secondary" data-submit-fallback>Afficher</button>
            <?php if (!($service['isToday'] ?? true)) : ?>
                <a class="button button--secondary" href="<?= View::e($dayUrl('')) ?>">Aujourd'hui</a>
            <?php endif; ?>
        </form>
    <?php else : ?>
        <p class="agenda-day"><?= View::e(ucfirst($service['dateLabel'])) ?></p>
    <?php endif; ?>

    <?php
    $rows = $board['rows'];
    $totals = $board['totals'];
    $rotation = $board['rotation'];
    $plural = static fn (int $n, string $word): string => $n . ' ' . $word . ($n > 1 ? 's' : '');
    ?>
    <nav class="view-tabs" aria-label="Présentation">
        <a href="<?= View::e($dayUrl($service['date'], 'lits')) ?>"<?= $view === 'lits'
            ? ' aria-current="page"' : '' ?>>Lits</a>
        <a href="<?= View::e($dayUrl($service['date'], 'liste')) ?>"<?= $view === 'liste'
            ? ' aria-current="page"' : '' ?>>Liste de travail</a>
    </nav>

    <?php if ($rows === []) : ?>
        <?= View::render('components/empty_state', [
            'icon' => 'calendar',
            'title' => 'Aucun patient en cours dans ce service.',
        ]) ?>
    <?php else : ?>
        <p class="board-summary" aria-live="polite">
            <?= View::e($plural($totals['patients'], 'patient')) ?>
            <?php if ($totals['late'] > 0) : ?>
                · <strong class="board-summary__late"><?= View::e($plural($totals['late'], 'soin') . ' en retard')
                ?></strong>
            <?php endif; ?>
            · <?= View::e($totals['todo']) ?> à faire<?= $rotation !== null
                ? ' d\'ici la fin de la rotation (' . View::e($rotation['label']) . ')' : ' ce jour' ?>
        </p>

        <?php if ($view === 'lits') : ?>
            <div class="board">
                <div class="table-scroll board__table">
                    <table class="board-table">
                        <caption class="sr-only">
                            Patients du service, du plus urgent au moins urgent ; choisir un patient affiche
                            ses soins à côté.
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Lit</th>
                                <th scope="col">Patient</th>
                                <th scope="col">En retard</th>
                                <th scope="col">À faire</th>
                                <th scope="col">Prochain soin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <?php $isSelected = ($board['selected']['idEpisodeOfCare'] ?? null)
                                    === $row['idEpisodeOfCare']; ?>
                                <tr class="board-row<?= $isSelected ? ' is-selected' : '' ?>">
                                    <td class="board-row__bed"><?= View::e($row['bed'] ?? '—') ?></td>
                                    <th scope="row" class="board-row__patient">
                                        <?php $rowUrl = $dayUrl($service['date'], 'lits', $row['idEpisodeOfCare']); ?>
                                        <a href="<?= View::e($rowUrl) ?>#detail-lit"<?= $isSelected
                                            ? ' aria-current="true"' : '' ?>>
                                            <?= View::e($row['patient']) ?>
                                        </a>
                                        <?php if ($row['deceased'] !== null) : ?>
                                            <?= View::render('components/deceased_tag', [
                                                'label' => $row['deceased'],
                                            ]) ?>
                                        <?php endif; ?>
                                        <?php if (is_array($row['allergies']) && $row['allergies'] !== []) : ?>
                                            <span class="board-row__allergies">
                                                <span class="sr-only">Allergies :</span>
                                                <?= View::e(implode(', ', array_column($row['allergies'], 'label'))) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($row['referent'] === null && $row['referentKnown']) : ?>
                                            <span class="board-row__referent">référent à désigner</span>
                                        <?php endif; ?>
                                    </th>
                                    <td>
                                        <?php if ($row['late'] > 0) : ?>
                                            <?= View::render('components/status_badge', [
                                                'status' => 'late',
                                                'label' => (string) $row['late'],
                                            ]) ?>
                                        <?php else : ?>
                                            <span class="board-row__none">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= View::e($row['todo']) ?></td>
                                    <td>
                                        <?php if ($row['next'] !== null) : ?>
                                            <?= View::e($row['next']['time'] . ' ' . $row['next']['label']) ?>
                                        <?php else : ?>
                                            <span class="board-row__none">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php $selected = $board['selected']; ?>
                <?php if ($selected !== null) : ?>
                    <aside class="board-panel" id="detail-lit" aria-labelledby="detail-lit-title">
                        <div class="board-panel__header">
                            <h2 id="detail-lit-title">
                                <?= $selected['bed'] !== null ? 'Lit ' . View::e($selected['bed']) . ' · ' : '' ?>
                                <?= View::e($selected['patient']) ?>
                            </h2>
                            <p class="board-panel__links">
                                <a href="<?= View::e($agendaUrl($selected['idEpisodeOfCare'])) ?>">Agenda du patient</a>
                                <a href="/sejour?id=<?= View::e($selected['idEpisodeOfCare']) ?>">Dossier</a>
                            </p>
                            <p class="board-panel__meta">
                                Séjour n° <?= View::e($selected['episodeNumber']) ?>
                                <?php if ($selected['referent'] !== null) : ?>
                                    · Réf. <?= View::e($selected['referent']) ?>
                                <?php elseif ($selected['referentKnown']) : ?>
                                    · <span class="referent-missing">Référent à désigner</span>
                                <?php endif; ?>
                            </p>
                            <?php if ($selected['deceased'] !== null) : ?>
                                <?= View::render('components/deceased_tag', ['label' => $selected['deceased']]) ?>
                            <?php endif; ?>
                            <?php if (is_array($selected['allergies'])) : ?>
                                <p class="board-panel__allergies service-patient__allergies">
                                    <?php if ($selected['allergies'] === []) : ?>
                                        <span class="allergy-tag allergy-tag--none">Aucune allergie connue</span>
                                    <?php else : ?>
                                        <span class="sr-only">Allergies :</span>
                                        <?php foreach ($selected['allergies'] as $allergy) : ?>
                                            <span class="allergy-tag allergy-tag--<?= View::e($allergy['level']) ?>">
                                                <?= View::e($allergy['label']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php if ($selected['items'] === []) : ?>
                            <p class="board-panel__empty">Aucun soin <?= $rotation !== null
                                ? 'sur cette rotation' : 'ce jour' ?>.</p>
                        <?php else : ?>
                            <ol class="care-lines">
                                <?php foreach ($selected['items'] as $item) : ?>
                                    <?= View::render('components/care_line', [
                                        'item' => $item,
                                        'validationEnabled' => $validationEnabled,
                                        'agendaUrl' => $agendaUrl($item['idEpisodeOfCare']),
                                    ]) ?>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                        <p class="board-panel__foot"><?= $rotation !== null
                            ? 'Retards et soins de la rotation ' . View::e($rotation['label'])
                                . ' ; le reste de la journée est dans l\'agenda du patient.'
                            : 'Tous les soins du jour.' ?></p>
                    </aside>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <?php
            $worklist = $board['worklist'];
            $sections = $rotation !== null ? [
                ['key' => 'late', 'title' => 'En retard', 'open' => true],
                ['key' => 'now', 'title' => 'Maintenant, jusqu\'à ' . $rotation['until'], 'open' => true],
                ['key' => 'later', 'title' => 'Plus tard dans la rotation (jusqu\'à la fin, '
                    . $rotation['label'] . ')', 'open' => false],
                ['key' => 'done', 'title' => 'Réalisés ou non effectués pendant la rotation', 'open' => false],
            ] : [
                ['key' => 'later', 'title' => 'Soins prévus', 'open' => true],
                ['key' => 'done', 'title' => 'Réalisés ou non effectués', 'open' => false],
            ];
            ?>
            <?php foreach ($sections as $section) : ?>
                <?php $items = $worklist[$section['key']]; ?>
                <?php $open = $section['open'] ? ' open' : ''; ?>
                <details class="worklist worklist--<?= View::e($section['key']) ?>"<?= $open ?>>
                    <summary>
                        <span class="worklist__title"><?= View::e($section['title']) ?></span>
                        <span class="worklist__count"><?= View::e(count($items)) ?></span>
                    </summary>
                    <?php if ($items === []) : ?>
                        <p class="worklist__empty">Rien ici.</p>
                    <?php else : ?>
                        <ol class="care-lines">
                            <?php foreach ($items as $item) : ?>
                                <?= View::render('components/care_line', [
                                    'item' => $item,
                                    'showPatient' => true,
                                    // Le titre de la section dit déjà « en retard » ou « à faire ».
                                    'showStatus' => $section['key'] === 'done',
                                    'validationEnabled' => $validationEnabled,
                                    'agendaUrl' => $agendaUrl($item['idEpisodeOfCare']),
                                ]) ?>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($validationEnabled) : ?>
        <?= View::render('components/validation_dialog', [
            'validators' => $validators,
            'csrfToken' => $csrfToken ?? '',
            'back' => $dayUrl($service['date'], null, $board['selected']['idEpisodeOfCare'] ?? null),
        ]) ?>
    <?php endif; ?>
</section>
