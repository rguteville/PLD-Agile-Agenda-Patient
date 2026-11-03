<?php

use Aura\Core\View;

/**
 * Confirmation de la suppression d'un patient (AURA-12.3) : rappel de l'identité avant de confirmer.
 * Si la suppression est impossible (séjour en cours, soin réalisé : RG10), le motif remplace le
 * bouton de confirmation ; le serveur refait de toute façon la vérification au POST.
 *
 * @var array{idPatient: int, lastName: string, firstName: string, ipp: ?string, birthDate: ?string} $identity
 * @var int $episodes nombre de séjours du patient dans le service
 * @var ?string $refusal motif du refus, null si la suppression est possible
 */
$refusal = $refusal ?? null;
$fullName = $identity['lastName'] . ' ' . $identity['firstName'];
?>
<section class="patient-delete" aria-labelledby="patient-delete-title">
    <h1 class="form-title" id="patient-delete-title">Supprimer le patient</h1>

    <dl class="patient-delete__identity">
        <div>
            <dt>Nom</dt>
            <dd><?= View::e($identity['lastName']) ?></dd>
        </div>
        <div>
            <dt>Prénom</dt>
            <dd><?= View::e($identity['firstName']) ?></dd>
        </div>
        <div>
            <dt>IPP</dt>
            <dd><?= View::e($identity['ipp'] ?? 'sans IPP') ?></dd>
        </div>
        <div>
            <dt>Né(e) le</dt>
            <dd><?= View::e($identity['birthDate'] ?? 'non renseignée') ?></dd>
        </div>
        <div>
            <dt>Séjours</dt>
            <dd><?= View::e($episodes) ?> séjour<?= $episodes > 1 ? 's' : '' ?> dans le service</dd>
        </div>
    </dl>

    <?php if ($refusal !== null) : ?>
        <p class="patient-delete__refusal" role="alert">
            <?= View::render('components/icon', ['name' => 'octagon-x']) ?>
            <span><?= View::e($refusal) ?></span>
        </p>
        <p class="page-actions">
            <a class="button button--secondary" href="/patients">Retour à la liste des patients</a>
        </p>
    <?php else : ?>
        <p class="patient-delete__notice">
            <?= View::render('components/icon', ['name' => 'triangle-alert']) ?>
            <span>
                <?= View::e($fullName) ?> n'apparaîtra plus dans la liste des patients ni dans les recherches.
                Son dossier et ses séjours sont conservés (suppression logique) et la suppression est
                enregistrée dans l'historique.
            </span>
        </p>
        <form method="post" action="/patients/supprimer?id=<?= View::e($identity['idPatient']) ?>"
              class="page-actions">
            <input type="hidden" name="csrf" value="<?= View::e($csrfToken ?? '') ?>">
            <button type="submit" class="button button--danger">Confirmer la suppression</button>
            <a class="button button--secondary" href="/patients">Annuler</a>
        </form>
    <?php endif; ?>
</section>
