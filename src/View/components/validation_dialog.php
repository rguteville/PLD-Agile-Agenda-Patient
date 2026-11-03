<?php

use Aura\Core\View;

/**
 * Dialogue d'action sur un soin (AURA-26, AURA-28) : « Réalisé » (qui, quand), « Non effectué »
 * (motif obligatoire) ou simple note. Ouvert par les boutons de l'agenda (data-care-action) ;
 * public/js/agenda.js l'envoie en JSON à POST /agenda-patient/soin et recharge la page. Sans
 * JavaScript, le formulaire part en POST classique et le serveur renvoie à la page d'origine.
 *
 * Authentification FACTICE (comme les parties du dossier) : soignant + mot de passe non vérifié.
 *
 * @var array<string, string> $validators idPractitioner => « Prénom Nom (rôle) »
 * @var string $csrfToken jeton de session de l'endpoint
 * @var string $back page d'origine, pour le retour sans JavaScript
 */
$back = $back ?? '';
?>
<dialog id="validation-dialog" class="dialog" aria-labelledby="validation-dialog-title">
    <form method="post" action="/agenda-patient/soin" class="dialog-form" data-care-form
          data-csrf="<?= View::e($csrfToken) ?>">
        <h2 id="validation-dialog-title">Soin</h2>
        <p class="dialog-context" data-dialog-context aria-live="polite"></p>
        <input type="hidden" name="csrf" value="<?= View::e($csrfToken) ?>">
        <input type="hidden" name="episodeOfCare" value="">
        <input type="hidden" name="appointment" value="">
        <input type="hidden" name="back" value="<?= View::e($back) ?>">

        <?php /* Retours du 2026-11-04 : Réalisé / Non effectué côte à côte ; la note seule s'ouvre par le
                 bouton « Note » de l'agenda (agenda.js masque alors [data-action-choice]). */ ?>
        <fieldset class="dialog-field care-actions" data-action-choice>
            <legend>Le soin a été</legend>
            <label><input type="radio" name="action" value="done" checked> Réalisé</label>
            <label><input type="radio" name="action" value="not_done"> Non effectué</label>
            <label hidden><input type="radio" name="action" value="note"> Note</label>
        </fieldset>

        <div class="dialog-row">
            <div class="dialog-field">
                <label for="validation-performer">Soignant</label>
                <select id="validation-performer" name="idPerformer" required aria-describedby="validation-auth-error">
                    <option value="">Choisir un soignant</option>
                    <?php foreach ($validators as $value => $label) : ?>
                        <option value="<?= View::e($value) ?>"><?= View::e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="dialog-field">
                <label for="validation-password">Mot de passe</label>
                <input type="password" id="validation-password" name="password" autocomplete="off" required
                       aria-describedby="validation-auth-error">
                <p class="form-error" role="alert" id="validation-auth-error" data-error="auth" hidden></p>
            </div>

            <div class="dialog-field" data-only-action="done">
                <label for="validation-time">Réalisé le</label>
                <input type="datetime-local" id="validation-time" name="completedAt"
                       aria-describedby="validation-time-error">
                <p class="form-error" role="alert" id="validation-time-error" data-error="completedAt" hidden></p>
            </div>
        </div>

        <div class="dialog-field">
            <label for="validation-note" data-note-label>Note</label>
            <textarea id="validation-note" name="comment" rows="2" maxlength="100"
                      placeholder="patient au scanner, refus, conditions particulières"
                      aria-describedby="validation-note-error"></textarea>
            <p class="form-error" role="alert" id="validation-note-error" data-error="comment" hidden></p>
        </div>

        <p class="form-error" role="alert" data-error="message" hidden></p>
        <p class="dialog-hint">
            <?= View::render('components/icon', ['name' => 'info']) ?>
            <span>Authentification de démonstration : le mot de passe n'est pas vérifié. L'agenda affichera
                « par … » avec l'heure de réalisation.</span>
        </p>

        <div class="dialog-actions">
            <button type="button" class="button button--secondary" data-dialog-close>Annuler</button>
            <button type="submit" class="button">Enregistrer</button>
        </div>
    </form>
</dialog>
