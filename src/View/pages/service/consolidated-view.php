<?php

use Aura\Core\View;

/**
 * Agenda du service (AURA-29, route GET /agenda-service), en vertical : les heures défilent de haut
 * en bas (un créneau par ligne), un patient en cours par colonne (lit, identité, allergies RG2,
 * référent, retards). Chaque case liste les soins du créneau avec leur statut et, pour un soin
 * prévu ou en retard, les actions de l'agenda (valider, non effectué, note : AURA-26, AURA-28).
 * En tête, les soins en retard de tout le service (AURA-32). Vue-modèle construit par
 * AgendaService::presentService() ; jamais la couleur seule (icône et texte sur chaque statut).
 *
 * @var array<string, mixed> $service vue-modèle de AgendaService::presentService()
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
$lateItems = $service['lateItems'] ?? [];
$dayUrl = static fn (string $date): string => '/agenda-service?idHealthcareService='
    . rawurlencode((string) $idHealthcareService) . '&date=' . $date;
$agendaUrl = static fn (int $idEpisodeOfCare): string => '/agenda-patient?id=' . $idEpisodeOfCare
    . '&date=' . $service['date'];
$slotLabel = static function (string $slot): string {
    $end = (int) substr($slot, 0, 2) + \Aura\Service\AgendaService::SERVICE_SLOT_HOURS;

    return (int) substr($slot, 0, 2) . ' h – ' . $end . ' h';
};
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
            <button type="submit" class="button button--secondary" data-submit-fallback>Afficher</button>
            <?php if (!($service['isToday'] ?? true)) : ?>
                <a class="button button--secondary" href="<?= View::e($dayUrl('')) ?>">Aujourd'hui</a>
            <?php endif; ?>
        </form>
    <?php else : ?>
        <p class="agenda-day"><?= View::e(ucfirst($service['dateLabel'])) ?></p>
    <?php endif; ?>

    <?php if ($lateItems !== []) : ?>
        <?php /* AURA-32 : alerte des soins en retard sur tout le service, pour réaffecter un soignant. */ ?>
        <section class="service-alert" role="region" aria-labelledby="service-alert-title">
            <h2 id="service-alert-title">
                <?= View::render('components/icon', ['name' => 'clock-alert']) ?>
                <?= View::e(count($lateItems)) ?> soin<?= count($lateItems) > 1 ? 's' : '' ?> en retard
                dans le service
            </h2>
            <ul class="service-alert__list">
                <?php foreach ($lateItems as $item) : ?>
                    <li>
                        <time datetime="<?= View::e($item['datetime']) ?>"><?= View::e($item['time']) ?></time>
                        <a href="<?= View::e($agendaUrl($item['idEpisodeOfCare'])) ?>">
                            <?= $item['bed'] !== null ? 'Lit ' . View::e($item['bed']) . ' · ' : '' ?>
                            <?= View::e($item['patient']) ?>
                        </a>
                        <span class="item-label"><?= View::e($item['label']) ?></span>
                        <?php if ($validationEnabled) : ?>
                            <?= View::render('components/care_buttons', [
                                'item' => $item,
                                'idEpisodeOfCare' => $item['idEpisodeOfCare'],
                                'context' => $item['patient'],
                                'withNote' => false,
                            ]) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($service['patients'] === []) : ?>
        <?= View::render('components/empty_state', [
            'title' => 'Aucun patient dans le service ce jour.',
        ]) ?>
    <?php else : ?>
        <?php
        // AURA-29 : filtre par type de soin, sur les soins affichés ce jour.
        $careLabels = [];
        foreach ($service['patients'] as $patient) {
            foreach ($patient['items'] ?? [] as $slotItems) {
                foreach ($slotItems as $item) {
                    $careLabels[$item['label']] = true;
                }
            }
        }
        $careLabels = array_keys($careLabels);
        sort($careLabels);
        ?>
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
                <?php if (count($careLabels) > 1) : ?>
                    <label for="care-type-filter" class="sr-only">Type de soin</label>
                    <select id="care-type-filter" data-type-filter>
                        <option value="">Tous les types de soin</option>
                        <?php foreach ($careLabels as $careLabel) : ?>
                            <option value="<?= View::e($careLabel) ?>"><?= View::e($careLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
        </details>

        <div class="table-scroll">
            <table class="service-timeline">
                <caption class="sr-only">
                    Soins par créneau horaire (lignes) et par patient (colonnes), <?= View::e($service['dateLabel']) ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col" class="col-time">Heure</th>
                        <?php foreach ($service['patients'] as $patient) : ?>
                            <th scope="col" class="service-patient">
                                <?php if ($patient['bed'] !== null) : ?>
                                    <span class="patient-bed">Lit <?= View::e($patient['bed']) ?></span>
                                <?php endif; ?>
                                <a href="<?= View::e($agendaUrl($patient['idEpisodeOfCare'])) ?>">
                                    <span class="patient-name"><?= View::e($patient['lastName']) ?></span>
                                    <?= View::e($patient['firstName']) ?>
                                </a>
                                <span class="patient-bed">séjour n° <?= View::e($patient['episodeNumber']) ?></span>
                                <?php if (($patient['deceased'] ?? null) !== null) : ?>
                                    <?= View::render('components/deceased_tag', ['label' => $patient['deceased']]) ?>
                                <?php endif; ?>
                                <?php if (($patient['referent'] ?? null) !== null) : ?>
                                    <span class="patient-bed">Réf. <?= View::e($patient['referent']) ?></span>
                                <?php elseif ($patient['referentKnown'] ?? false) : ?>
                                    <span class="referent-missing">
                                        <?= View::render('components/icon', ['name' => 'info']) ?>
                                        Référent à désigner
                                    </span>
                                <?php endif; ?>
                                <?php if (is_array($patient['allergies'])) : ?>
                                    <span class="service-patient__allergies">
                                        <?php if ($patient['allergies'] === []) : ?>
                                            <span class="allergy-tag allergy-tag--none">Aucune allergie connue</span>
                                        <?php else : ?>
                                            <span class="sr-only">Allergies :</span>
                                            <?php foreach ($patient['allergies'] as $allergy) : ?>
                                                <span class="allergy-tag allergy-tag--<?=
                                                    View::e($allergy['level']) ?>">
                                                    <?= View::e($allergy['label']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($patient['late'] > 0) : ?>
                                    <?= View::render('components/status_badge', [
                                        'status' => 'late',
                                        'label' => $patient['late'] . ' en retard',
                                    ]) ?>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($service['slots'] as $slot) : ?>
                        <?php
                        $isNow = ($service['currentSlot'] ?? null) === $slot;
                        // Audit IHM du 2026-11-04 : un créneau sans aucun soin dans le service est resserré.
                        $slotEmpty = in_array($slot, $service['emptySlots'] ?? [], true);
                        ?>
                        <tr class="service-slot<?= $isNow ? ' is-now' : '' ?><?= $slotEmpty ? ' is-empty' : '' ?>">
                            <th scope="row" class="col-time">
                                <?= View::e($slotLabel($slot)) ?>
                                <?php if ($isNow) : ?>
                                    <span class="now-marker">maintenant</span>
                                <?php endif; ?>
                            </th>
                            <?php foreach ($service['patients'] as $patient) : ?>
                                <?php $items = $patient['items'][$slot] ?? []; ?>
                                <td class="cell-slot">
                                    <?php if ($items === []) : ?>
                                        <span class="sr-only">Rien de prévu</span>
                                    <?php else : ?>
                                        <ul class="slot-items">
                                            <?php foreach ($items as $item) : ?>
                                                <li class="slot-item is-<?= View::e($item['status']) ?>"
                                                    data-appointment-row data-status="<?= View::e($item['status']) ?>"
                                                    data-care-label="<?= View::e($item['label']) ?>">
                                                    <span class="slot-item__head">
                                                        <time datetime="<?= View::e($item['datetime']) ?>">
                                                            <?= View::e($item['time']) ?>
                                                        </time>
                                                        <span class="item-label"><?= View::e($item['label']) ?></span>
                                                    </span>
                                                    <?php if ($item['details'] !== null) : ?>
                                                        <span class="item-details"><?=
                                                            View::e($item['details']) ?></span>
                                                    <?php endif; ?>
                                                    <?php /* Audit IHM du 2026-11-04 : « + », statut, auteur et
                                                             boutons sur une seule ligne sous le libellé. */ ?>
                                                    <span class="slot-item__foot">
                                                    <?= View::render('components/prescription_details', [
                                                        'item' => $item,
                                                    ]) ?>
                                                    <?= View::render('components/status_badge', [
                                                        'status' => $item['status'],
                                                    ]) ?>
                                                    <?php if ($item['notValidated'] ?? false) : ?>
                                                        <?= View::render('components/status_badge', [
                                                            'status' => 'to_validate',
                                                            'label' => 'Ordonnance non validée',
                                                        ]) ?>
                                                    <?php endif; ?>
                                                    <?php if (($item['performedBy'] ?? null) !== null) : ?>
                                                        <span class="trace">par <?=
                                                            View::e($item['performedBy']) ?></span>
                                                    <?php endif; ?>
                                                    <?php
                                                    $who = mb_strtoupper($patient['lastName']) . ' '
                                                        . $patient['firstName'];
                                                    ?>
                                                    <?php if ($validationEnabled) : ?>
                                                        <?= View::render('components/care_buttons', [
                                                            'item' => $item,
                                                            'idEpisodeOfCare' => $patient['idEpisodeOfCare'],
                                                            'context' => $who,
                                                        ]) ?>
                                                    <?php endif; ?>
                                                    </span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
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

    <?php if ($validationEnabled) : ?>
        <?= View::render('components/validation_dialog', [
            'validators' => $validators,
            'csrfToken' => $csrfToken ?? '',
            'back' => $dayUrl($service['date']),
        ]) ?>
    <?php endif; ?>
</section>
