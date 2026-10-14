// Envoi AJAX des formulaires marqués data-ajax et affichage des erreurs
// renvoyées par le serveur (AURA-10.2) à côté de chaque champ.
// Le contrôle côté serveur reste la garantie : ce script ne valide rien lui-même.

const MESSAGE_ERREUR_GENERIQUE = "L'enregistrement a échoué. Veuillez réessayer.";

function effacerErreurs(form) {
    form.querySelectorAll('.field-error, .form-error').forEach(el => el.remove());
    form.querySelectorAll('.has-error').forEach(row => row.classList.remove('has-error'));
    form.querySelectorAll('[aria-invalid]').forEach(input => {
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');
    });
}

function afficherErreurChamp(form, nom, message) {
    const input = form.querySelector(`[name="${CSS.escape(nom)}"]`);
    const row = input ? input.closest('.form-row') : null;
    if (!row) {
        afficherErreurFormulaire(form, message);
        return;
    }

    const erreurId = `${nom}-error`;
    const p = document.createElement('p');
    p.className = 'field-error';
    p.id = erreurId;
    p.setAttribute('role', 'alert');
    p.textContent = message;

    row.classList.add('has-error');
    row.appendChild(p);
    row.querySelectorAll(`[name="${CSS.escape(nom)}"]`).forEach(champ => {
        champ.setAttribute('aria-invalid', 'true');
        champ.setAttribute('aria-describedby', erreurId);
    });
}

function afficherErreurFormulaire(form, message) {
    const p = document.createElement('p');
    p.className = 'form-error';
    p.setAttribute('role', 'alert');
    p.textContent = message;
    form.prepend(p);
}

document.querySelectorAll('form[data-ajax]').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        effacerErreurs(form);

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
            afficherErreurFormulaire(form, MESSAGE_ERREUR_GENERIQUE);
            return;
        }

        if (!data.ok) {
            const erreurs = data.erreurs || {};
            Object.keys(erreurs).forEach(nom => afficherErreurChamp(form, nom, erreurs[nom]));
            if (Object.keys(erreurs).length === 0) {
                afficherErreurFormulaire(form, MESSAGE_ERREUR_GENERIQUE);
            }
            return;
        }

        // Succès (AURA-10.4) : le serveur indique le dossier du séjour créé.
        if (data.redirect) {
            window.location.assign(data.redirect);
        }
    });
});
