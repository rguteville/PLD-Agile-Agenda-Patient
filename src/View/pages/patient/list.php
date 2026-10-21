<?php

use Aura\Core\View;

/**
 * Liste des patients avec leur séjour en cours (AURA-12.1).
 *
 * @var list<array{idPatient: int, nom: string, prenom: string, ipp: ?string,
 *                 dateNaissance: ?string, idSejour: ?int, numeroSejour: ?string}> $patients
 * @var ?string $recherche terme saisi, null si aucun
 */
?>
<h1 class="form-title">Patients</h1>

<form class="recherche-patients" method="get" action="/patients" role="search">
    <label for="q">Rechercher</label>
    <input type="search" id="q" name="q" value="<?= View::e($recherche ?? '') ?>"
           placeholder="Nom, prénom ou IPP" maxlength="50" autocomplete="off">
    <button type="submit">Rechercher</button>
    <?php if ($recherche !== null) : ?>
        <a href="/patients">Tous les patients</a>
    <?php endif; ?>
</form>

<?php if ($patients === []) : ?>
    <p class="liste-vide">
        <?php if ($recherche !== null) : ?>
            Aucun patient ne correspond à « <?= View::e($recherche) ?> ».
        <?php else : ?>
            Aucun patient dans le service.
        <?php endif; ?>
    </p>
<?php else : ?>
    <table class="liste-patients">
        <caption><?= count($patients) ?> patient<?= count($patients) > 1 ? 's' : '' ?></caption>
        <thead>
            <tr>
                <th scope="col">Nom</th>
                <th scope="col">Prénom</th>
                <th scope="col">IPP</th>
                <th scope="col">Né(e) le</th>
                <th scope="col">Séjour en cours</th>
                <th scope="col">Dossier</th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($patients as $p) : ?>
                <tr>
                    <td><?= View::e($p['nom']) ?></td>
                    <td><?= View::e($p['prenom']) ?></td>
                    <td><?= View::e($p['ipp'] ?? 'sans IPP') ?></td>
                    <td><?= View::e($p['dateNaissance'] ?? 'non renseignée') ?></td>
                    <td>
                        <?php if ($p['numeroSejour'] !== null) : ?>
                            n° <?= View::e($p['numeroSejour']) ?>
                        <?php else : ?>
                            <span class="hors-service">aucun</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['idSejour'] !== null) : ?>
                            <a href="/sejour?id=<?= View::e($p['idSejour']) ?>">Voir</a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/patients/modifier?id=<?= View::e($p['idPatient']) ?>">Modifier</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p><a href="/">Admettre un patient</a></p>
