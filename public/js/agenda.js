// Agendas (patient, service, « Mon planning ») : impression, filtres par statut, et dialogue d'action sur
// un soin (AURA-26 : « Valider », puis Réalisé ou Non effectué ; AURA-28 : « Note », note seule).
// Chargé par EpisodeOfCareController::getAgenda() (GET /agenda-patient), ServiceController::getAgenda()
// (GET /agenda-service) et TeamController::planning() (GET /mon-planning).
// Attend : #print-page ; [data-agenda-filters] avec ses boutons [data-filter] et [data-filter-state] ;
// des lignes [data-appointment-row] (data-status) ; des boutons [data-care-action]
// (data-appointment, data-episode, data-label, components/care_buttons.php) ; #validation-dialog et son
// formulaire [data-care-form] (data-csrf, components/validation_dialog.php).
// Appel réseau : POST /agenda-patient/soin en JSON, en-têtes Accept: application/json et X-CSRF-Token.
// Réponse : {ok: true} (la page est rechargée) ou {ok: false, errors: {champ: message}, message}.
// Le serveur revalide tout : ce script n'est qu'un confort, la page fonctionne sans lui (POST classique).
const printButton = document.getElementById('print-page');
if (printButton) {
    printButton.addEventListener('click', () => window.print());
}

// ---- Filtres par statut (agenda du patient, « Mon planning ») : « Tous », « En retard »… ----
const filters = document.querySelector('[data-agenda-filters]');
if (filters) {
    filters.hidden = false;
    const rows = document.querySelectorAll('[data-appointment-row]');
    const state = filters.querySelector('[data-filter-state]');
    let status = 'all';

    // Masque les lignes hors filtre et rappelle le filtre actif sur le bouton replié (« Filtres : En retard »).
    function apply() {
        if (state) {
            const pressed = filters.querySelector('[data-filter][aria-pressed="true"]');
            state.textContent = status !== 'all' && pressed ? ` : ${pressed.textContent.trim()}` : '';
        }
        rows.forEach(row => {
            row.hidden = status !== 'all' && row.dataset.status !== status;
        });
        // Une rotation (.agenda-section) dont tous les soins sont filtrés se replie sur son titre.
        document.querySelectorAll('.agenda-section').forEach(section => {
            const visible = section.querySelectorAll('[data-appointment-row]:not([hidden])').length;
            section.classList.toggle('is-filtered-out', status !== 'all' && visible === 0);
        });
    }

    filters.querySelectorAll('[data-filter]').forEach(button => {
        button.addEventListener('click', () => {
            status = button.dataset.filter;
            filters.querySelectorAll('[data-filter]').forEach(other => {
                other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
            });
            apply();
        });
    });
}

// ---- Dialogue d'action sur un soin ----
const dialog = document.getElementById('validation-dialog');
const careForm = dialog ? dialog.querySelector('[data-care-form]') : null;
if (dialog && careForm && typeof dialog.showModal === 'function') {
    const context = dialog.querySelector('[data-dialog-context]');
    const time = careForm.elements.completedAt;
    const noteLabel = careForm.querySelector('[data-note-label]');
    const submit = careForm.querySelector('button[type="submit"]');

    // Maintenant, au format datetime-local (2026-10-14T08:00), à l'heure locale.
    function localNow() {
        const date = new Date();
        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        return date.toISOString().slice(0, 16);
    }

    // Efface les messages d'erreur et les aria-invalid laissés par un envoi précédent.
    function clearErrors() {
        careForm.querySelectorAll('[data-error]').forEach(p => {
            p.hidden = true;
            p.textContent = '';
        });
        careForm.querySelectorAll('[aria-invalid]').forEach(field => field.removeAttribute('aria-invalid'));
    }

    // L'heure de réalisation n'a de sens que pour « Réalisé » ; la note devient obligatoire pour
    // « Non effectué » (motif) et pour « Note ».
    function applyAction() {
        const action = careForm.elements.action.value;
        // Bouton « Note » : seulement la note (et qui l'écrit), sans le choix Réalisé / Non effectué.
        careForm.querySelector('[data-action-choice]').hidden = action === 'note';
        careForm.querySelectorAll('[data-only-action]').forEach(block => {
            block.hidden = block.dataset.onlyAction !== action;
        });
        time.required = action === 'done';
        careForm.elements.comment.required = action !== 'done';
        noteLabel.textContent = action === 'not_done' ? 'Motif (obligatoire)'
            : action === 'note' ? 'Note' : 'Note (facultative)';
    }

    careForm.querySelectorAll('input[name="action"]').forEach(radio => radio.addEventListener('change', applyAction));

    // Ouvre le dialogue pour le soin du bouton : action « done » (Valider) ou « note » (Note), heure
    // de réalisation à maintenant, champs vidés.
    document.querySelectorAll('[data-care-action]').forEach(button => {
        button.addEventListener('click', () => {
            clearErrors();
            careForm.elements.appointment.value = button.dataset.appointment;
            careForm.elements.episodeOfCare.value = button.dataset.episode;
            careForm.elements.action.value = button.dataset.careAction;
            careForm.elements.comment.value = '';
            careForm.elements.password.value = '';
            time.value = localNow();
            context.textContent = button.dataset.label;
            // Allergies du patient (RG2) quand l'écran les fournit avec le soin.
            const allergies = dialog.querySelector('[data-dialog-allergies]');
            if (allergies) {
                allergies.textContent = button.dataset.allergies || '';
                allergies.hidden = !button.dataset.allergies;
            }
            applyAction();
            dialog.showModal();
        });
    });
    dialog.querySelectorAll('[data-dialog-close]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });

    // Affiche un message sous le champ [data-error="key"] ; false si ce champ n'existe pas.
    function showError(key, message) {
        const p = careForm.querySelector(`[data-error="${key}"]`);
        if (!p) {
            return false;
        }
        p.textContent = message;
        p.hidden = false;
        return true;
    }

    // Envoi en JSON ; succès : rechargement (l'agenda affiche « par … ») ; refus : erreurs dans le dialogue.
    careForm.addEventListener('submit', async event => {
        event.preventDefault();
        clearErrors();
        const data = Object.fromEntries(new FormData(careForm).entries());
        submit.disabled = true;
        try {
            // getAttribute et non careForm.action : le formulaire a des champs nommés « action » (Réalisé /
            // Non effectué), et careForm.action renvoie alors ces champs au lieu de l'URL (la requête partait
            // vers « /agenda-patient/[object RadioNodeList] » : 404, « Enregistrement impossible »).
            const response = await fetch(careForm.getAttribute('action'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': careForm.dataset.csrf,
                },
                body: JSON.stringify(data),
            });
            const body = (await response.json().catch(() => null)) || {};
            if (response.ok && body.ok) {
                window.location.reload();
                return;
            }
            const errors = body.errors || {};
            Object.keys(errors).forEach(key => {
                if (!showError(key, errors[key])) {
                    showError('message', errors[key]);
                }
            });
            if (Object.keys(errors).length === 0) {
                showError('message', body.message || 'Enregistrement impossible. Réessayez.');
            }
        } catch (error) {
            showError('message', 'Le serveur ne répond pas. Réessayez.');
        } finally {
            submit.disabled = false;
        }
    });
}

// ---- Agenda du service, onglet « Lits » (retours du 2026-11-04) : un clic n'importe où sur la ligne d'un patient
// ouvre son panneau à droite, comme le lien sur son nom (qui reste le chemin au clavier et sans JavaScript). ----
document.querySelectorAll('tr[data-row-link]').forEach(row => {
    const link = row.querySelector('a[href]');
    if (!link) {
        return;
    }
    row.addEventListener('click', event => {
        // Un clic sur un lien ou un bouton de la ligne garde son propre effet ; une sélection de texte n'ouvre rien.
        if (event.target.closest('a, button, summary') || String(window.getSelection())) {
            return;
        }
        window.location.assign(link.href);
    });
});
