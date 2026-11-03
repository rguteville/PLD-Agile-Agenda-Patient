<?php

use Aura\Core\View;
use Aura\Domain\CareTeam;
use Aura\Service\CareTeamService;

/**
 * Équipes d'une vacation (AURA-30) : équipes composées (soignants, patients), patients encore sans
 * équipe, et formulaire de composition d'une équipe. Contrôles côté serveur (CareTeamService).
 *
 * @var array<string, string> $services référentiel des services
 * @var string $idHealthcareService service affiché
 * @var array<string, mixed> $shiftView vacation affichée (date, shift, label, previous, next)
 * @var list<CareTeam> $teams équipes actives de la vacation
 * @var list<array<string, mixed>> $episodes séjours en cours du service (AgendaService::getAgendaService())
 * @var array<string, string> $practitioners soignants proposés
 * @var array<string, string> $errors erreurs du formulaire de composition
 * @var array<string, mixed> $values saisie à réafficher après un refus
 * @var string $csrfToken
 */
$errors = $errors ?? [];
$values = $values ?? [];
$url = static fn (string $date, string $shift): string => '/equipes?idHealthcareService='
    . rawurlencode($idHealthcareService) . '&date=' . $date . '&vacation=' . strtolower($shift);
$episodeLabel = [];
foreach ($episodes as $e) {
    $episodeLabel[(int) $e['idEpisodeOfCare']] = ($e['bed'] !== null ? 'Lit ' . $e['bed'] . ', ' : '')
        . mb_strtoupper((string) $e['lastName']) . ' ' . $e['firstName'];
}
$assigned = [];
foreach ($teams as $team) {
    foreach ($team->getEpisodeIds() as $id) {
        $assigned[$id] = $team->getName();
    }
}
$unassigned = array_diff_key($episodeLabel, $assigned);
$checkedMembers = array_map('strval', (array) ($values['members'] ?? []));
$checkedPatients = array_map('strval', (array) ($values['patients'] ?? []));
// Contexte renvoyé avec chaque formulaire, pour revenir à la même vacation.
$contextFields = '<input type="hidden" name="csrf" value="' . View::e($csrfToken) . '">'
    . '<input type="hidden" name="idHealthcareService" value="' . View::e($idHealthcareService) . '">'
    . '<input type="hidden" name="date" value="' . View::e($shiftView['date']) . '">'
    . '<input type="hidden" name="vacation" value="' . View::e(strtolower($shiftView['shift'])) . '">';
?>
<section class="team-view">
    <div class="page-header">
        <h1 class="form-title">Équipes : <?= View::e($services[$idHealthcareService] ?? '') ?></h1>
    </div>

    <form class="agenda-nav" data-auto-submit-form
          method="get" action="/equipes" aria-label="Choisir le service et la vacation">
        <label for="service-select" class="sr-only">Service</label>
        <select id="service-select" name="idHealthcareService">
            <?php foreach ($services as $id => $name) : ?>
                <option value="<?= View::e($id) ?>"<?= (string) $id === $idHealthcareService ? ' selected' : '' ?>>
                    <?= View::e($name) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <a class="nav-day" aria-label="Vacation précédente"
           href="<?= View::e($url($shiftView['previous']['date'], $shiftView['previous']['shift'])) ?>">
            <?= View::render('components/icon', ['name' => 'chevron-left']) ?>
        </a>
        <span class="agenda-day">
            <?= View::render('components/icon', ['name' => 'calendar']) ?>
            <?= View::e(ucfirst($shiftView['label'])) ?>
        </span>
        <a class="nav-day" aria-label="Vacation suivante"
           href="<?= View::e($url($shiftView['next']['date'], $shiftView['next']['shift'])) ?>">
            <?= View::render('components/icon', ['name' => 'chevron-right']) ?>
        </a>
        <label for="team-date" class="sr-only">Date</label>
        <input type="date" id="team-date" name="date" value="<?= View::e($shiftView['date']) ?>">
        <label for="team-shift" class="sr-only">Vacation</label>
        <select id="team-shift" name="vacation">
            <?php foreach (CareTeamService::SHIFTS as $key => $shift) : ?>
                <option value="<?= View::e(strtolower($key)) ?>"<?= $key === $shiftView['shift'] ? ' selected' : '' ?>>
                    <?= View::e($shift['label'] . ' (' . $shift['hours'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button button--secondary" data-submit-fallback>Afficher</button>
    </form>

    <?php if ($unassigned !== []) : ?>
        <p class="form-error" role="status">
            <?= View::e(count($unassigned)) ?> patient<?= count($unassigned) > 1 ? 's' : '' ?> sans équipe :
            <?= View::e(implode(', ', $unassigned)) ?>.
        </p>
    <?php endif; ?>

    <?php if ($teams === []) : ?>
        <?= View::render('components/empty_state', [
            'icon' => 'info',
            'title' => 'Aucune équipe composée pour cette vacation.',
            'text' => 'Composez les équipes ci-dessous, au début de la vacation.',
        ]) ?>
    <?php else : ?>
        <ul class="team-cards">
            <?php foreach ($teams as $team) : ?>
                <li class="team-card">
                    <h2 class="team-card__title"><?= View::e($team->getName()) ?></h2>
                    <p class="team-card__label">Soignants</p>
                    <ul class="team-card__list">
                        <?php foreach ($team->getMembers() as $idPractitioner => $name) : ?>
                            <li>
                                <?= View::e($name) ?>
                                <?php $planningUrl = '/mon-planning?idPractitioner='
                                    . rawurlencode((string) $idPractitioner)
                                    . '&date=' . $shiftView['date'] . '&vacation=' . strtolower($shiftView['shift']); ?>
                                <a href="<?= View::e($planningUrl) ?>">
                                    planning
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="team-card__label">Patients</p>
                    <ul class="team-card__list">
                        <?php foreach ($team->getEpisodeIds() as $id) : ?>
                            <li>
                                <a href="<?= View::e('/agenda-patient?id=' . $id . '&date=' . $shiftView['date']) ?>">
                                    <?= View::e($episodeLabel[$id] ?? 'Séjour ' . $id . ' (sorti)') ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($team->getEpisodeIds() === []) : ?>
                            <li>Aucun patient attribué.</li>
                        <?php endif; ?>
                    </ul>
                    <form method="post" action="/equipes/retirer"
                          data-confirm="Retirer l'équipe <?= View::e($team->getName()) ?> ?">
                        <?= $contextFields ?>
                        <input type="hidden" name="idCareTeam" value="<?= View::e($team->getIdCareTeam()) ?>">
                        <button type="submit" class="button button--secondary">Retirer l'équipe</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="/equipes/creer" class="team-form" aria-labelledby="team-form-title">
        <h2 id="team-form-title" class="form-subtitle">Composer une équipe</h2>
        <?= $contextFields ?>
        <div class="form-row">
            <label for="team-name">Nom de l'équipe</label>
            <input type="text" id="team-name" name="name" maxlength="<?= CareTeamService::NAME_MAX ?>" required
                   placeholder="Équipe C"
                   value="<?= View::e(is_string($values['name'] ?? null) ? $values['name'] : '') ?>"
                   <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="team-name-error"' : '' ?>>
            <?php if (isset($errors['name'])) : ?>
                <p class="field-error" id="team-name-error" role="alert"><?= View::e($errors['name']) ?></p>
            <?php endif; ?>
        </div>

        <fieldset class="checkbox-group"<?= isset($errors['members'])
            ? ' aria-describedby="team-members-error"' : '' ?>>
            <legend>Soignants</legend>
            <?php foreach ($practitioners as $id => $label) : ?>
                <label>
                    <input type="checkbox" name="members[]" value="<?= View::e($id) ?>"
                        <?= in_array((string) $id, $checkedMembers, true) ? 'checked' : '' ?>>
                    <?= View::e($label) ?>
                </label>
            <?php endforeach; ?>
            <?php if (isset($errors['members'])) : ?>
                <p class="field-error" id="team-members-error" role="alert"><?= View::e($errors['members']) ?></p>
            <?php endif; ?>
        </fieldset>

        <fieldset class="checkbox-group"<?= isset($errors['patients'])
            ? ' aria-describedby="team-patients-error"' : '' ?>>
            <legend>Patients sans équipe</legend>
            <?php foreach ($unassigned as $id => $label) : ?>
                <label>
                    <input type="checkbox" name="patients[]" value="<?= View::e($id) ?>"
                        <?= in_array((string) $id, $checkedPatients, true) ? 'checked' : '' ?>>
                    <?= View::e($label) ?>
                </label>
            <?php endforeach; ?>
            <?php if ($unassigned === []) : ?>
                <p>Tous les patients du service ont une équipe pour cette vacation.</p>
            <?php endif; ?>
            <?php if (isset($errors['patients'])) : ?>
                <p class="field-error" id="team-patients-error" role="alert"><?= View::e($errors['patients']) ?></p>
            <?php endif; ?>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="button">Composer l'équipe</button>
        </div>
    </form>
</section>
