<?php

use Aura\Core\View;

/**
 * Agenda du service (AURA-29, route GET /agenda-service). Refonte du 2026-11-04 (maquettes A + B
 * choisies par Raphaël), l'ancienne grille créneaux × patients montrant trop d'informations à la fois :
 *  - « Lits » (par défaut) : une ligne par patient, du plus urgent au moins urgent (retards, à faire dans
 *    la rotation, prochain soin), et un panneau de détail du lit choisi (?sejour=…) avec ses soins de la
 *    rotation et les actions (valider, note : AURA-26, AURA-28) ;
 *  - « Liste de travail » (?vue=liste) : les soins de tout le service, en retard (AURA-32, quel que soit le
 *    jour), doses de lignes arrêtées à tracer, maintenant (deux heures), plus tard (replié), réalisés (replié).
 * RG2 : allergies (ou « Aucune allergie connue ») dans le panneau du patient choisi ; ni le tableau ni la liste
 * de travail ne les répètent (choix de Raphaël du 2026-11-04 : écran plus léger). Vue-modèle :
 * AgendaService::presentBoard().
 * Rendue par ServiceController::getAgenda() (GET /agenda-service?idHealthcareService=…&date=…, &vue=liste,
 * &sejour=idEpisodeOfCare pour le lit détaillé). Scripts : agenda.js (impression, dialogue),
 * auto-submit.js (choix du service et du jour).
 *
 * @var array<string, mixed> $service vue-modèle de AgendaService::presentService() (jour, navigation)
 * @var array<string, mixed> $board vue-modèle de AgendaService::presentBoard() (isToday, rotation, totals,
 *      rows, selected, selectionMissing, worklist)
 * @var string $view « lits » ou « liste », « lits » par défaut
 * @var array<string, string> $services référentiel des services (choix du service affiché), [] par défaut
 * @var string|null $idHealthcareService service affiché, null par défaut
 * @var bool $validationEnabled actions sur les soins + dialogue, false par défaut
 * @var array<string, string> $validators soignants proposés par le dialogue, [] par défaut
 * @var string|null $csrfToken jeton de POST /agenda-patient/soin, null sans validation
 */
$services = $services ?? [];
$idHealthcareService = $idHealthcareService ?? null;
$validationEnabled = $validationEnabled ?? false;
$validators = $validators ?? [];
$view = $view ?? 'lits';
$dayUrl = static fn (string $date, ?string $mode = null, ?int $patient = null): string
    => '/agenda-service?idHealthcareService=' . rawurlencode((string) $idHealthcareService) . '&date=' . $date
    . (($mode ?? $view) === 'liste' ? '&vue=liste' : '') . ($patient !== null ? '&sejour=' . $patient : '');
$agendaUrl = static fn (int $idEpisodeOfCare): string => '/agenda-patient?id=' . $idEpisodeOfCare
    . '&date=' . $service['date'];
?>
<section class="service-view">
    <div class="page-header">
        <h1 class="form-title">
            Agenda du service<?= $idHealthcareService !== null && isset($services[$idHealthcareService])
                ? ' : ' . View::e($services[$idHealthcareService]) : '' ?>
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
        <?php
        // Virgules collées au mot qui précède (pas de retour à la ligne avant une virgule dans le HTML).
        $lateText = $totals['late'] > 0 ? $plural($totals['late'], 'soin') . ' en retard' : null;
        $todoText = $totals['todo'] . ' à faire' . ($rotation !== null
            ? ' d\'ici la fin de la rotation (de ' . $rotation['label'] . ')' : ' ce jour');
        ?>
        <?php $lateHtml = $lateText !== null
            ? ', <strong class="board-summary__late">' . View::e($lateText) . '</strong>' : ''; ?>
        <p class="board-summary"><?= View::e($plural($totals['patients'], 'patient')) . $lateHtml
            . ', ' . View::e($todoText) ?></p>

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
                                    <td class="board-row__bed"><?= View::e($row['bed'] ?? 'sans lit') ?></td>
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
                                            <span class="board-row__none">aucun</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= View::e($row['todo']) ?></td>
                                    <td>
                                        <?php if ($row['next'] !== null) : ?>
                                            <?= View::e($row['next']['time'] . ' ' . $row['next']['label']) ?>
                                        <?php else : ?>
                                            <span class="board-row__none">aucun</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php $selected = $board['selected']; ?>
                <?php if ($board['selectionMissing'] ?? false) : ?>
                    <aside class="board-panel" id="detail-lit" role="alert">
                        <p class="board-panel__empty">Ce séjour n'est pas en cours dans ce service (clos ou
                            transféré) : choisissez un patient dans le tableau.</p>
                    </aside>
                <?php endif; ?>
                <?php if ($selected !== null) : ?>
                    <aside class="board-panel" id="detail-lit" aria-labelledby="detail-lit-title">
                        <div class="board-panel__header">
                            <h2 id="detail-lit-title">
                                <?= $selected['bed'] !== null ? 'Lit ' . View::e($selected['bed']) . ', ' : '' ?>
                                <?= View::e($selected['patient']) ?>
                            </h2>
                            <p class="board-panel__links">
                                <a href="<?= View::e($agendaUrl($selected['idEpisodeOfCare'])) ?>">Agenda du patient</a>
                                <a href="/sejour?id=<?= View::e($selected['idEpisodeOfCare']) ?>">Dossier</a>
                            </p>
                            <p class="board-panel__meta">
                                Séjour n° <?= View::e($selected['episodeNumber']) ?><?php
                                if ($selected['referent'] !== null) :
                                    ?>, réf. <?= View::e($selected['referent']) ?><?php
                                elseif ($selected['referentKnown']) :
                                    ?>, <span class="referent-missing">référent à désigner</span><?php
                                endif; ?>
                            </p>
                            <?php if ($selected['deceased'] !== null) : ?>
                                <?= View::render('components/deceased_tag', ['label' => $selected['deceased']]) ?>
                            <?php endif; ?>
                            <?= View::render('components/allergy_tags', ['allergies' => $selected['allergies']]) ?>
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
                            ? 'Retards et soins de la rotation (de ' . View::e($rotation['label']) . ')'
                                . ' ; le reste de la journée est dans l\'agenda du patient.'
                            : 'Tous les soins du jour.' ?></p>
                    </aside>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <?php
            $worklist = $board['worklist'];
            // Les retards et les doses de lignes arrêtées sont toujours montrés, quel que soit le jour
            // (relecture du 2026-11-04) ; une section vide autre que « En retard » est omise.
            $sections = [
                ['key' => 'late', 'title' => 'En retard', 'open' => true],
                ['key' => 'stopped', 'title' => 'Lignes arrêtées : doses à tracer si faites avant l\'arrêt',
                    'open' => true],
                ...($rotation !== null ? [
                    ['key' => 'now', 'title' => 'Maintenant, jusqu\'à ' . $rotation['until'], 'open' => true],
                    ['key' => 'later', 'title' => 'Plus tard dans la rotation (de ' . $rotation['label'] . ')',
                        'open' => false],
                    ['key' => 'done', 'title' => 'Réalisés ou non effectués pendant la rotation', 'open' => false],
                ] : [
                    ['key' => 'later', 'title' => 'Soins prévus', 'open' => true],
                    ['key' => 'done', 'title' => 'Réalisés ou non effectués', 'open' => false],
                ]),
            ];
            ?>
            <?php foreach ($sections as $section) : ?>
                <?php $items = $worklist[$section['key']]; ?>
                <?php if ($items === [] && in_array($section['key'], ['stopped'], true)) : ?>
                    <?php continue; ?>
                <?php endif; ?>
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
