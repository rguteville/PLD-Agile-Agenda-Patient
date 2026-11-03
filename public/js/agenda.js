// Agenda (patient et service) : impression, filtres par statut, et dialogue d'action sur un soin
// (AURA-26 valider, non effectué, AURA-28 note), envoyé en JSON à POST /agenda-patient/soin.
// Le serveur revalide tout : ce script n'est qu'un confort, la page fonctionne sans lui (POST classique).
const printButton = document.getElementById('print-page');
if (printButton) {
    printButton.addEventListener('click', () => window.print());
}

// ---- Filtres : « Tous », « En retard », « À faire », « Réalisés », « Non effectués » ----
const filters = document.querySelector('[data-agenda-filters]');
if (filters) {
    filters.hidden = false;
    const rows = document.querySelectorAll('[data-appointment-row]');
    filters.querySelectorAll('[data-filter]').forEach(button => {
        button.addEventListener('click', () => {
            const filter = button.dataset.filter;
            filters.querySelectorAll('[data-filter]').forEach(other => {
                other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
            });
            rows.forEach(row => {
                row.hidden = filter !== 'all' && row.dataset.status !== filter;
            });
            // Une rotation dont tous les soins sont filtrés se replie sur son titre.
            document.querySelectorAll('.agenda-section, .service-slot').forEach(section => {
                const visible = section.querySelectorAll('[data-appointment-row]:not([hidden])').length;
                section.classList.toggle('is-filtered-out', filter !== 'all' && visible === 0);
            });
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

    function clearErrors() {
        careForm.querySelectorAll('[data-error]').forEach(p => {
            p.hidden = true;
            p.textContent = '';
        });
        careForm.querySelectorAll('[aria-invalid]').forEach(field => field.removeAttribute('aria-invalid'));
    }

    // L'heure de réalisation n'a de sens que pour « Réalisé » ; la note devient un motif obligatoire
    // pour « Non effectué ».
    function applyAction() {
        const action = careForm.elements.action.value;
        careForm.querySelectorAll('[data-only-action]').forEach(block => {
            block.hidden = block.dataset.onlyAction !== action;
        });
        time.required = action === 'done';
        careForm.elements.comment.required = action !== 'done';
        noteLabel.textContent = action === 'not_done' ? 'Motif (obligatoire)'
            : action === 'note' ? 'Note' : 'Note (facultative)';
    }

    careForm.querySelectorAll('input[name="action"]').forEach(radio => radio.addEventListener('change', applyAction));

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
            applyAction();
            dialog.showModal();
        });
    });
    dialog.querySelectorAll('[data-dialog-close]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });

    function showError(key, message) {
        const p = careForm.querySelector(`[data-error="${key}"]`);
        if (!p) {
            return false;
        }
        p.textContent = message;
        p.hidden = false;
        return true;
    }

    careForm.addEventListener('submit', async event => {
        event.preventDefault();
        clearErrors();
        const data = Object.fromEntries(new FormData(careForm).entries());
        submit.disabled = true;
        try {
            const response = await fetch(careForm.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': careForm.dataset.csrf,
                },
                body: JSON.stringify(data),
            });
            const body = await response.json().catch(() => ({}));
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
