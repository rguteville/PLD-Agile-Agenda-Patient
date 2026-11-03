<?php

use Aura\Core\View;

/**
 * Liste des patients avec leur séjour en cours (AURA-12.1).
 *
 * @var list<array{idPatient: int, lastName: string, firstName: string, ipp: ?string,
 *                 birthDate: ?string, idEpisodeOfCare: ?int, episodeNumber: ?string}> $patients
 * @var ?string $search terme saisi, null si aucun
 * @var bool $deleted vrai juste après une suppression (AURA-12.3)
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
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($patients as $p) : ?>
                <tr>
                    <td><?= View::e($p['lastName']) ?></td>
                    <td><?= View::e($p['firstName']) ?></td>
                    <td><?= View::e($p['ipp'] ?? 'sans IPP') ?></td>
                    <td><?= View::e($p['birthDate'] ?? 'non renseignée') ?></td>
                    <td>
                        <?php if ($p['episodeNumber'] !== null) : ?>
                            n° <?= View::e($p['episodeNumber']) ?>
                        <?php else : ?>
                            <span class="no-service">aucun</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['idEpisodeOfCare'] !== null) : ?>
                            <a href="/sejour?id=<?= View::e($p['idEpisodeOfCare']) ?>"
                               aria-label="Dossier de <?= View::e($p['lastName'] . ' '
                                                      . $p['firstName']) ?>">Dossier</a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="patient-list__delete" href="/patients/supprimer?id=<?= View::e($p['idPatient']) ?>"
                           aria-label="Supprimer <?= View::e($p['lastName'] . ' ' . $p['firstName']) ?>">Supprimer</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
