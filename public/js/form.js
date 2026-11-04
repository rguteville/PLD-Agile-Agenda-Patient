// Envoi AJAX des formulaires marqués data-ajax et affichage des erreurs
// renvoyées par le serveur à côté de chaque champ.
// Le contrôle côté serveur reste la garantie : ce script ne valide rien lui-même.
// Chargé par l'admission (POST /patients/create), le dossier du séjour (arrêt d'une ligne, POST
// /care-plans/stop) et l'écran B3 (POST /care-plans/create et /care-plans/validate). Pas par la
// modification d'un patient : son formulaire data-ajax y part en POST classique.
// Attend : form[data-ajax] ; lignes .form-row, zone .form-actions (components/form.php).
// Appel réseau : POST vers l'attribut action, corps FormData (jeton CSRF dans le champ csrf quand le
// formulaire en a un), en-têtes Accept: application/json et X-Requested-With: XMLHttpRequest.
// Réponse : {ok: false, errors: {champ: message}} (422) ou {ok: true, redirect: '/…'}.
// Événement émis : aura:form-errors (form-sections.js ouvre les sections en erreur).

const GENERIC_ERROR_MESSAGE = "L'enregistrement a échoué. Veuillez réessayer.";

// Retire les erreurs d'un envoi précédent (messages, classes et attributs ARIA).
function clearFormErrors(form) {
    form.querySelectorAll('.field-error, .form-error').forEach(el => el.remove());
    form.querySelectorAll('.has-error').forEach(row => row.classList.remove('has-error'));
    form.querySelectorAll('[aria-invalid]').forEach(input => {
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');
    });
}

// Message sous le champ, relié par aria-describedby ; champ inconnu : erreur générale du formulaire.
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
// au moment d'envoyer ; en tête du formulaire s'il n'a pas de zone d'actions.
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
        // Un seul envoi à la fois, boutons désactivés : un double clic enverrait deux fois l'ordonnance
        // (soins en double dans l'agenda) ou l'admission (deux patients).
        if (form.dataset.sending === '1') {
            return;
        }
        form.dataset.sending = '1';
        const buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        buttons.forEach(button => { button.disabled = true; });
        clearFormErrors(form);

        let data;
        let redirected = false;
        try {
            // getAttribute : un champ nommé « action » masquerait form.action (même piège que agenda.js).
            const response = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            data = await response.json();
        } catch (e) {
            data = null;
        }

        if (!data || typeof data !== 'object') {
            showFormError(form, GENERIC_ERROR_MESSAGE);
        } else if (!data.ok) {
            const errors = data.errors || {};
            Object.keys(errors).forEach(fieldName => showFieldError(form, fieldName, errors[fieldName]));
            if (Object.keys(errors).length === 0) {
                showFormError(form, GENERIC_ERROR_MESSAGE);
            }
            // Les sections repliées (form-sections.js) s'ouvrent sur le champ en erreur.
            form.dispatchEvent(new CustomEvent('aura:form-errors'));
        } else if (data.redirect) {
            // Succès : le serveur indique le dossier du séjour créé. Le formulaire reste bloqué
            // pendant le changement de page.
            redirected = true;
            window.location.assign(data.redirect);
        }
        if (!redirected) {
            delete form.dataset.sending;
            buttons.forEach(button => { button.disabled = false; });
        }
    });
});
