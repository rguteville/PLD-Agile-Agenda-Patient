// Envoi AJAX des formulaires marqués data-ajax et affichage des erreurs
// renvoyées par le serveur (AURA-10.2) à côté de chaque champ.
// Le contrôle côté serveur reste la garantie : ce script ne valide rien lui-même.

const GENERIC_ERROR_MESSAGE = "L'enregistrement a échoué. Veuillez réessayer.";

function clearFormErrors(form) {
    form.querySelectorAll('.field-error, .form-error').forEach(el => el.remove());
    form.querySelectorAll('.has-error').forEach(row => row.classList.remove('has-error'));
    form.querySelectorAll('[aria-invalid]').forEach(input => {
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');
    });
}

function showFieldError(form, fieldName, message) {
    const input = form.querySelector(`[name="${CSS.escape(fieldName)}"]`);
    const row = input ? input.closest('.form-row') : null;
    if (!row) {
        showFormError(form, message);
        return;
    }

    const errorId = `${fieldName}-error`;
    const p = document.createElement('p');
    p.className = 'field-error';
    p.id = errorId;
    p.setAttribute('role', 'alert');
    p.textContent = message;

    row.classList.add('has-error');
    row.appendChild(p);
    row.querySelectorAll(`[name="${CSS.escape(fieldName)}"]`).forEach(field => {
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', errorId);
    });
}

// Erreur hors champ (ex. « patient déjà hospitalisé ») : à côté du bouton d'envoi, là où l'on regarde
// au moment d'admettre (retours du 2026-11-03) ; en tête du formulaire s'il n'a pas de zone d'actions.
function showFormError(form, message) {
    const p = document.createElement('p');
    p.className = 'form-error';
    p.setAttribute('role', 'alert');
    p.textContent = message;
    const actions = form.querySelector('.form-actions');
    if (actions) {
        actions.appendChild(p);
    } else {
        form.prepend(p);
    }
}

document.querySelectorAll('form[data-ajax]').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        clearFormErrors(form);

        let data;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            data = await response.json();
        } catch (e) {
            showFormError(form, GENERIC_ERROR_MESSAGE);
            return;
        }

        if (!data.ok) {
            const errors = data.errors || {};
            Object.keys(errors).forEach(fieldName => showFieldError(form, fieldName, errors[fieldName]));
            if (Object.keys(errors).length === 0) {
                showFormError(form, GENERIC_ERROR_MESSAGE);
            }
            // Les sections repliées (form-sections.js) s'ouvrent sur le champ en erreur.
            form.dispatchEvent(new CustomEvent('aura:form-errors'));
            return;
        }

        // Succès (AURA-10.4) : le serveur indique le dossier du séjour créé.
        if (data.redirect) {
            window.location.assign(data.redirect);
        }
    });
});
