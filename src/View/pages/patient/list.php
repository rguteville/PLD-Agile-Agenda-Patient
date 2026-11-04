<?php

use Aura\Core\View;

/**
 * Liste des patients : séjour en cours mis en avant, dossier toujours accessible (séjour en
 * cours, sinon dernier séjour), historique des séjours dépliable. Décès : cœur barré à côté du nom
 * (components/deceased_tag, iconOnly), libellé en infobulle et en texte alternatif.
 * Rendue par PatientController::list() (GET /patients?q=…). Aucun script propre à la page.
 *
 * @var list<array<string, mixed>> $patients vue-modèle de PatientListService::presentLine() (idPatient,
 *      lastName, firstName, ipp, birthDate, deceased, deceasedLabel, current, record, history)
 * @var ?string $search terme saisi, null si aucun
 * @var bool $deleted vrai juste après une suppression, false par défaut
 */
$deleted = $deleted ?? false;
?>
<div class="page-header">
    <h1 class="form-title">Patients</h1>
    <p class="page-actions">
        <a class="button" href="/">Admettre un patient</a>
    </p>
</div>

<?php if ($deleted) : ?>
    <p class="page-notice" role="status">
        <?= View::render('components/icon', ['name' => 'circle-check']) ?>
        Patient supprimé. Son dossier reste conservé (suppression logique).
    </p>
<?php endif; ?>

<form class="patient-search" method="get" action="/patients" role="search">
    <label for="q">Rechercher</label>
    <input type="search" id="q" name="q" value="<?= View::e($search ?? '') ?>"
           placeholder="Nom, prénom ou IPP" maxlength="50" autocomplete="off">
    <button type="submit" class="button--secondary">
        <?= View::render('components/icon', ['name' => 'search']) ?>
        Rechercher
    </button>
    <?php if ($search !== null) : ?>
        <a href="/patients">Tous les patients</a>
    <?php endif; ?>
</form>

<?php if ($patients === []) : ?>
    <?= View::render('components/empty_state', $search !== null
        ? ['icon' => 'search', 'title' => 'Aucun patient ne correspond à « ' . $search . ' ».',
            'text' => 'Vérifiez l\'orthographe ou l\'IPP, ou affichez tous les patients.']
        : ['title' => 'Aucun patient dans le service.', 'text' => 'Admettez le premier patient depuis l\'accueil.']) ?>
<?php else : ?>
    <div class="table-scroll">
    <table class="patient-list">
        <caption><?= count($patients) ?> patient<?= count($patients) > 1 ? 's' : '' ?></caption>
        <thead>
            <tr>
                <th scope="col">Nom</th>
                <th scope="col">Prénom</th>
                <th scope="col">IPP</th>
                <th scope="col">Né(e) le</th>
                <th scope="col">Séjour en cours</th>
                <th scope="col">Dossier</th>
                <th scope="col">Séjours</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($patients as $p) : ?>
                <?php $name = $p['lastName'] . ' ' . $p['firstName']; ?>
                <tr<?= $p['current'] !== null ? ' class="patient-list__row--current"' : '' ?>>
                    <?php /* Décès : cœur barré À GAUCHE du nom, libellé en infobulle et alternative. */ ?>
                    <td><?= $p['deceased']
                        ? View::render('components/deceased_tag', [
                            'label' => $p['deceasedLabel'],
                            'iconOnly' => true,
                        ]) . ' '
                        : '' ?><?= View::e($p['lastName']) ?></td>
                    <td><?= View::e($p['firstName']) ?></td>
                    <td><?= View::e($p['ipp'] ?? 'sans IPP') ?></td>
                    <td><?= View::e($p['birthDate'] ?? 'non renseignée') ?></td>
                    <td>
                        <?php if ($p['current'] !== null) : ?>
                            <?php $current = $p['current']; ?>
                            <?php /* Deux lignes seulement : la colonne dit déjà « en cours ». */ ?>
                            <div class="current-episode">
                                <span>Lit <?= View::e($current['bed'] ?? 'non attribué') ?>,
                                    <?= View::e($current['service'] ?? 'service non renseigné') ?></span>
                                <?php $since = 'n° ' . $current['episodeNumber'] . ', depuis le ' . $current['since'];
                                ?>
                                <small class="current-episode__number"><?= View::e($since) ?></small>
                            </div>
                        <?php else : ?>
                            <span class="no-service">aucun</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php /* Dossier toujours accessible : séjour en cours, sinon le dernier séjour (clos). */ ?>
                        <?php if ($p['record'] === null) : ?>
                            <span class="no-service">Aucun séjour : pas de dossier</span>
                        <?php elseif ($p['record']['ongoing']) : ?>
                            <a href="/sejour?id=<?= View::e($p['record']['idEpisodeOfCare']) ?>"
                               aria-label="Dossier du séjour en cours de <?= View::e($name) ?>">Dossier</a>
                        <?php else : ?>
                            <?php $closedOn = $p['record']['dischargeDate'] ?? '?'; ?>
                            <?php $recordLabel = 'Dossier du dernier séjour de ' . $name . ', clos le ' . $closedOn; ?>
                            <a href="/sejour?id=<?= View::e($p['record']['idEpisodeOfCare']) ?>"
                               aria-label="<?= View::e($recordLabel) ?>">Dernier dossier</a>
                            <small class="patient-list__closed">clos le <?= View::e($closedOn) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['history'] === []) : ?>
                            <span class="no-service">aucun</span>
                        <?php else : ?>
                            <?php $count = count($p['history']); ?>
                            <details class="episode-history-toggle">
                                <summary>
                                    <?= View::render('components/icon', ['name' => 'history']) ?>
                                    <?= $count ?> séjour<?= $count > 1 ? 's' : '' ?>
                                    <span class="sr-only">de <?= View::e($name) ?></span>
                                </summary>
                                <?= View::render('components/episode_history', [
                                    'episodes' => $p['history'],
                                    'label' => 'Séjours de ' . $name . ', du plus récent au plus ancien',
                                ]) ?>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="patient-list__delete" href="/patients/supprimer?id=<?= View::e($p['idPatient']) ?>"
                           aria-label="Supprimer <?= View::e($name) ?>">Supprimer</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
