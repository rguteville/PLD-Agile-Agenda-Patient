<?php

use Aura\Core\View;

/**
 * Dialogue de validation d'un soin : qui valide, quand, note facultative (balisage et accessibilité
 * seulement). L'enregistrement en base n'existe pas encore : il demande une route POST, une règle
 * métier et une colonne « validé par » sur Appointment (diff de schéma à proposer au responsable
 * BDD). D'ici là, le formulaire est en method="dialog" : « Valider » ferme la fenêtre sans rien
 * envoyer, et le dialogue n'est rendu que si le contrôleur passe validationEnabled = true.
 *
 * @var array<string, string> $validators idPractitioner => « nom (rôle) »
 */
?>
<dialog id="validation-dialog" class="dialog" aria-labelledby="validation-dialog-title">
    <form method="dialog" class="dialog-form">
        <h2 id="validation-dialog-title">Valider un soin</h2>
        <p class="dialog-context" data-dialog-context aria-live="polite"></p>

        <div class="dialog-field">
            <label for="validation-validator">Validé par</label>
            <select id="validation-validator" name="idValidator" required>
                <option value="">— soignant —</option>
                <?php foreach ($validators as $value => $label) : ?>
                    <option value="<?= View::e($value) ?>"><?= View::e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="dialog-field">
            <label for="validation-time">Réalisé le</label>
            <input type="datetime-local" id="validation-time" name="completedAt" required>
        </div>

        <div class="dialog-field">
            <label for="validation-note">Note (facultative)</label>
            <textarea id="validation-note" name="comment" rows="2" maxlength="100"
                      placeholder="Retard, refus, particularité…"></textarea>
        </div>

        <p class="dialog-hint">
            <?= View::render('components/icon', ['name' => 'info']) ?>
            <span>La validation restera visible dans l'agenda : « validé par …, à hh:mm ».</span>
        </p>

        <div class="dialog-actions">
            <button type="button" class="button button--secondary" data-dialog-close>Annuler</button>
            <button type="submit" class="button">Valider</button>
        </div>
    </form>
</dialog>
