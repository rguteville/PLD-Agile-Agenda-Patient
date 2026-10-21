<?php

use Aura\Core\View;

/**
 * Dossier du séjour (AURA-14) et gestion des allergies connues du patient (AURA-13.2).
 *
 * @var array<string, mixed> $bandeau
 * @var array<string, mixed> $sejour
 * @var list<array{idAllergy: ?int, label: string, criticality: ?string, recordedAt: ?string}> $allergies
 * @var array<string, string> $substances allergènes du référentiel non encore enregistrés, id => libellé
 * @var list<string> $criticalities
 * @var ?string $allergyError message d'un ajout ou d'un retrait refusé, null sinon
 */
$idSejour = $sejour['idSejour'];
$allergyError = $allergyError ?? null;
?>
<?= View::render('components/bandeau_patient', ['bandeau' => $bandeau]) ?>

<section class="dossier">
    <h1 class="form-title">Dossier du séjour n° <?= View::e($bandeau['numeroSejour']) ?></h1>
    <dl>
        <dt>Statut</dt>
        <dd><?= $sejour['enCours'] ? 'En cours' : 'Clos le ' . View::e($sejour['dateSortie']) ?></dd>
        <dt>Admission</dt>
        <dd><?= View::e($bandeau['dateAdmission'] ?? 'non renseignée') ?></dd>
        <dt>Service</dt>
        <dd><?= View::e($sejour['service'] ?? 'non renseigné') ?></dd>
        <dt>Lit</dt>
        <dd><?= View::e($sejour['lit'] ?? 'non attribué') ?></dd>
        <dt>Box</dt>
        <dd><?= View::e($sejour['box'] ?? 'non attribué') ?></dd>
        <dt>Poids à l'admission</dt>
        <dd><?= View::e($sejour['poids'] ?? 'non renseigné') ?></dd>
        <dt>Taille à l'admission</dt>
        <dd><?= View::e($sejour['taille'] ?? 'non renseignée') ?></dd>
        <dt>Précautions</dt>
        <?php /* « non renseignées », pas « aucune » : rien de saisi ne veut pas dire aucun risque. */ ?>
        <dd><?= isset($sejour['precautions']) ? nl2br(View::e($sejour['precautions'])) : 'non renseignées' ?></dd>
        <dt>Téléphone</dt>
        <dd><?= View::e($sejour['telephone'] ?? 'non renseigné') ?></dd>
        <dt>E-mail</dt>
        <dd><?= View::e($sejour['email'] ?? 'non renseigné') ?></dd>
    </dl>
</section>

<section class="allergies-dossier" aria-labelledby="titre-allergies">
    <h2 id="titre-allergies">Allergies connues</h2>

    <?php if ($allergyError !== null) : ?>
        <p class="form-error" role="alert"><?= View::e($allergyError) ?></p>
    <?php endif; ?>

    <?php if ($allergies === []) : ?>
        <p class="aucune-allergie">Aucune allergie connue</p>
    <?php else : ?>
        <ul class="liste-allergies">
            <?php foreach ($allergies as $allergy) : ?>
                <li>
                    <span class="libelle"><?= View::e($allergy['label']) ?></span>
                    <?php
                    $details = $allergy['criticality'] !== null
                        ? 'criticité ' . $allergy['criticality']
                        : 'criticité non évaluée';
                    if ($allergy['recordedAt'] !== null) {
                        $details .= ', enregistrée le ' . $allergy['recordedAt'];
                    }
                    ?>
                    <span class="details"><?= View::e($details) ?></span>
                    <?php /* Retrait en POST, jamais en lien GET : un GET peut être suivi par un robot,
                             préchargé par le navigateur ou relancé depuis l'historique. */ ?>
                    <form method="post" action="/sejour/allergies/retirer" class="retirer-allergie"
                          data-confirm="<?= View::e(sprintf(
                              'Retirer l\'allergie « %s » du dossier de ce patient ? Elle disparaîtra du bandeau.',
                              $allergy['label']
                          )) ?>">
                        <input type="hidden" name="idSejour" value="<?= View::e($idSejour) ?>">
                        <input type="hidden" name="idAllergy" value="<?= View::e($allergy['idAllergy']) ?>">
                        <button type="submit" aria-label="Retirer l'allergie <?= View::e($allergy['label']) ?>">
                            Retirer
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($substances === []) : ?>
        <p class="referentiel-epuise">Tous les allergènes du référentiel sont déjà enregistrés pour ce patient.</p>
    <?php else : ?>
        <form method="post" action="/sejour/allergies/ajouter" class="ajouter-allergie">
            <input type="hidden" name="idSejour" value="<?= View::e($idSejour) ?>">
            <div class="champ">
                <label for="idSubstance">Allergène</label>
                <select id="idSubstance" name="idSubstance" required>
                    <option value="">Choisir dans la liste</option>
                    <?php foreach ($substances as $id => $description) : ?>
                        <option value="<?= View::e($id) ?>"><?= View::e($description) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="champ">
                <label for="criticality">Criticité</label>
                <select id="criticality" name="criticality">
                    <option value="">Non évaluée</option>
                    <?php foreach ($criticalities as $criticality) : ?>
                        <option value="<?= View::e($criticality) ?>"><?= View::e($criticality) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Ajouter</button>
        </form>
    <?php endif; ?>
</section>

<section class="dossier">
    <p class="a-venir">Prescriptions (AURA-20) et agenda (AURA-25) viendront s'afficher ici.</p>
    <a href="/agenda-patient?idSejour=<?= urlencode((string)$bandeau['numeroSejour']) ?>">Vers l'agenda.</a>
</section>
