<?php

use Aura\Core\View;

/** TODO
 * Attention à prévoir le cas de chevauchement d'appointments sur la même période
 *
 * @var array<string, mixed> $agenda
 */
?>


<section class="agenda">
    <div class="page-header">
        <h1 class="form-title">Agenda du service </h1>
        <p class="page-actions">
            <a class="button button--secondary"
               href="/sejour?id=<?= View::e($idEpisodeOfCare) ?>">Dossier du séjour</a>
            <button type="button" id="print-page" class="button button--secondary">
                <?= View::render('components/icon', ['name' => 'printer']) ?>
                Imprimer la journée
            </button>
        </p>
    </div>

    <form class="agenda-nav" method="get" action="/agenda-patient" aria-label="Choisir le jour affiché">
        <input type="hidden" name="id" value="<?= View::e($idEpisodeOfCare) ?>">
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
        <input type="date" id="date" name="date" value="<?= View::e($agenda['date']) ?>">
        <button type="submit" class="button button--secondary">Afficher</button>
        <?php if (!$agenda['isToday']) : ?>
            <a href="/agenda-patient?id=<?= View::e($idEpisodeOfCare) ?>">Aujourd'hui</a>
        <?php endif; ?>
    </form>

    <ul class="stats" aria-label="Résumé de la journée">
        <li><span class="stat-value"><?= View::e($agenda['counts']['total']) ?></span> au total</li>
        <li class="stat--late"><span class="stat-value"><?= View::e($agenda['counts']['late']) ?></span> en retard</li>
        <li><span class="stat-value"><?= View::e($agenda['counts']['done']) ?></span> effectués</li>
        <li><span class="stat-value"><?= View::e($agenda['counts']['planned']) ?></span> à venir</li>
    </ul>

    <?php if ($agenda['counts']['total'] === 0) : ?>
        <?= View::render('components/empty_state', [
            'icon' => 'calendar',
            'title' => 'Aucun soin ni médicament prévu ce jour.',
            'text' => 'Les soins apparaissent ici dès qu\'une prescription validée les planifie.',
        ]) ?>
    <?php else : ?>
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
                ]) ?>
            </section>
        <?php endif; ?>

        <?php foreach ($agenda['shifts'] as $shift) : ?>
            <section class="agenda-section" aria-labelledby="agenda-<?= View::e($shift['key']) ?>-title">
                <h2 id="agenda-<?= View::e($shift['key']) ?>-title">
                    <?= View::e($shift['label']) ?>
                    <span class="count"><?= View::e($shift['hours']) ?> · <?= View::e(count($shift['items'])) ?></span>
                </h2>
                <?php if ($shift['items'] === []) : ?>
                    <p class="agenda-empty">Rien de prévu sur cette rotation.</p>
                <?php else : ?>
                    <?= View::render('components/agenda_table', [
                        'items' => $shift['items'],
                        'caption' => 'Rotation ' . $shift['label'] . ', ' . $shift['hours'],
                        'validationEnabled' => $validationEnabled,
                    ]) ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($validationEnabled) : ?>
        <?= View::render('components/validation_dialog', ['validators' => $validators]) ?>
    <?php endif; ?>
</section>
