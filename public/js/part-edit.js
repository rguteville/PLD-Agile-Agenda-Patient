// Modification d'une partie entière du dossier, fin de séjour (retours du 2026-11-02) et déclaration du
// décès (retours du 2026-11-04).
//
// Crayon « Modifier » à côté du titre d'une partie : les valeurs de la partie sont remplacées par un
// formulaire (components/part_form.php). « Enregistrer » ouvre la fenêtre de validation par un médecin
// (#auth-dialog, authentification FACTICE : le mot de passe n'est pas vérifié), puis envoie la partie.
// « Mettre fin au séjour » et « Déclarer le décès » passent par la même fenêtre, avec la date et l'heure
// (de sortie ou du décès) : le décès termine le séjour à cette heure, les soins prévus après sont annulés.
// Le serveur reste la garantie : liste blanche, validation, concurrence, traçabilité (RecordSectionService).
//
// Chargé par le dossier du séjour (GET /sejour), actif seulement si le dossier est éditable.
// Attend : [data-inline-edit] (data-csrf, data-id-episode-of-care), #auth-dialog ([data-auth-summary],
// [data-auth-discharge], [data-auth-error], [data-auth-cancel]), [data-part-edit], [data-part-view],
// form.part-form, [data-end-stay], [data-declare-death] (data-current).
// Appels réseau : POST en JSON, en-têtes Accept: application/json et X-CSRF-Token, avec validator et
// password en plus :
// - /sejour/partie/modifier {episodeOfCare, part, values, oldValues} ;
// - /sejour/cloturer {episodeOfCare, date (JJ/MM/AAAA), time (HH:MM)} ;
// - /sejour/deces {episodeOfCare, oldDeceased, date, time}.
// Réponse : {ok: true, redirect} ou {ok: false, errors: {champ ou auth: message}, message}.

(function () {
    const root = document.querySelector('[data-inline-edit]');
    const dialog = document.getElementById('auth-dialog');
    if (!root || !dialog) {
        return;
    }
    const csrf = root.dataset.csrf;
    const idEpisodeOfCare = root.dataset.idEpisodeOfCare;
    const authForm = dialog.querySelector('form');
    const authError = dialog.querySelector('[data-auth-error]');
    const authSummary = dialog.querySelector('[data-auth-summary]');
    const discharge = dialog.querySelector('[data-auth-discharge]');
    let pending = null; // { url, payload, form } en attente de validation

    // ── Ouvrir / fermer le formulaire d'une partie ──
    // Affichage en lecture d'une partie ([data-part-view]) et son formulaire (form.part-form).
    function partView(part) {
        return document.querySelector(`[data-part-view="${CSS.escape(part)}"]`);
    }

    function partForm(part) {
        return document.querySelector(`form.part-form[data-part="${CSS.escape(part)}"]`);
    }

    // Remplace la lecture par le formulaire de la partie (section dépliée, focus sur le premier champ).
    function openPart(part) {
        const form = partForm(part);
        const view = partView(part);
        if (!form) {
            return;
        }
        const details = form.closest('details');
        if (details) {
            details.open = true;
        }
        if (view) {
            view.hidden = true;
        }
        form.hidden = false;
        const first = form.querySelector('input:not([type="hidden"]), select, textarea');
        if (first) {
            first.focus();
        }
    }

    // Annulation : formulaire remis à zéro et masqué, lecture réaffichée, focus rendu au crayon.
    function closePart(form) {
        form.reset();
        clearErrors(form);
        form.hidden = true;
        const view = partView(form.dataset.part);
        if (view) {
            view.hidden = false;
        }
        const pencil = document.querySelector(`[data-part-edit="${CSS.escape(form.dataset.part)}"]`);
        if (pencil) {
            pencil.focus();
        }
    }

    document.querySelectorAll('[data-part-edit]').forEach(button => {
        button.addEventListener('click', event => {
            // Le crayon est dans le <summary> d'une section : il ne doit pas replier la section.
            event.preventDefault();
            event.stopPropagation();
            openPart(button.dataset.partEdit);
        });
    });

    // ── Erreurs ──
    function clearErrors(form) {
        form.querySelectorAll('.field-error').forEach(el => el.remove());
        form.querySelectorAll('.has-error').forEach(row => row.classList.remove('has-error'));
        form.querySelectorAll('[aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
        const general = form.querySelector('[data-part-error]');
        if (general) {
            general.hidden = true;
            general.textContent = '';
        }
    }

    // Erreurs du serveur sous chaque champ, message général en tête ; focus sur le premier champ fautif.
    function showErrors(form, errors, message) {
        clearErrors(form);
        let first = null;
        Object.entries(errors || {}).forEach(([field, text]) => {
            const control = form.querySelector(`[name="${CSS.escape(field)}"]`);
            const row = control ? control.closest('.form-row') : null;
            if (!row) {
                return;
            }
            const p = document.createElement('p');
            p.className = 'field-error';
            p.setAttribute('role', 'alert');
            p.textContent = text;
            row.classList.add('has-error');
            row.appendChild(p);
            control.setAttribute('aria-invalid', 'true');
            first = first || control;
        });
        const general = form.querySelector('[data-part-error]');
        if (general && message) {
            general.textContent = message;
            general.hidden = false;
        }
        if (first) {
            first.focus();
        }
    }

    // Valeurs du formulaire : un champ = une valeur (boutons radio compris).
    function formValues(form) {
        const values = {};
        form.querySelectorAll('input[name], select[name], textarea[name]').forEach(control => {
            if (control.type === 'radio') {
                if (control.checked) {
                    values[control.name] = control.value;
                } else if (!(control.name in values)) {
                    values[control.name] = '';
                }
                return;
            }
            values[control.name] = control.value;
        });
        return values;
    }

    // ── Fenêtre de validation ──
    // Ouvre la fenêtre de validation pour une requête { url, payload, form, summary, discharge,
    // dischargeLabels } ; date et heure pré-remplies à maintenant pour la sortie et le décès.
    function openDialog(request) {
        pending = request;
        authForm.reset();
        authError.hidden = true;
        authSummary.textContent = request.summary;
        discharge.hidden = !request.discharge;
        // Mêmes champs pour la sortie et le décès (qui termine le séjour) : seuls les libellés changent.
        const labels = request.dischargeLabels || ['Date de sortie', 'Heure de sortie'];
        dialog.querySelector('label[for="auth-discharge-date"]').textContent = labels[0];
        dialog.querySelector('label[for="auth-discharge-time"]').textContent = labels[1];
        if (request.discharge) {
            const now = new Date();
            const pad = n => String(n).padStart(2, '0');
            dialog.querySelector('[name="dischargeDate"]').value =
                `${pad(now.getDate())}/${pad(now.getMonth() + 1)}/${now.getFullYear()}`;
            dialog.querySelector('[name="dischargeTime"]').value = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
        }
        dialog.showModal();
        dialog.querySelector('[name="validator"]').focus();
    }

    function closeDialog() {
        dialog.close();
        pending = null;
    }

    dialog.querySelector('[data-auth-cancel]').addEventListener('click', closeDialog);

    // Après succès : recharger le dossier (l'ancre peut être la seule différence d'URL).
    function goTo(redirect) {
        const url = new URL(redirect, window.location.href);
        if (url.pathname + url.search === window.location.pathname + window.location.search) {
            window.location.hash = url.hash;
            window.location.reload();
        } else {
            window.location.assign(url);
        }
    }

    authForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (!pending) {
            return;
        }
        const submit = authForm.querySelector('button[type="submit"]');
        const payload = {
            ...pending.payload,
            validator: authForm.querySelector('[name="validator"]').value,
            password: authForm.querySelector('[name="password"]').value,
        };
        if (pending.discharge) {
            payload.date = authForm.querySelector('[name="dischargeDate"]').value;
            payload.time = authForm.querySelector('[name="dischargeTime"]').value;
        }
        submit.disabled = true;
        let data;
        try {
            const response = await fetch(pending.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf,
                },
                body: JSON.stringify(payload),
            });
            data = await response.json();
        } catch (e) {
            data = { ok: false, message: "L'enregistrement a échoué. Vérifiez la connexion puis réessayez." };
        }
        submit.disabled = false;

        if (data.ok) {
            goTo(data.redirect);
            return;
        }
        const errors = data.errors || {};
        // Erreur d'authentification ou de date de sortie, ou action sans formulaire (fin de séjour, décès) :
        // on reste dans la fenêtre et on y affiche le message.
        const firstError = Object.values(errors)[0];
        if (errors.auth || errors.dischargeDate || (!pending.form && (data.message || firstError))) {
            authError.textContent = errors.auth || errors.dischargeDate || data.message || firstError;
            authError.hidden = false;
            return;
        }
        const form = pending.form;
        closeDialog();
        if (form) {
            showErrors(form, errors, data.message || (Object.keys(errors).length === 0
                ? "L'enregistrement a échoué." : 'Corrigez les champs signalés.'));
        }
    });

    // ── Enregistrer une partie ──
    document.querySelectorAll('form.part-form').forEach(form => {
        form.querySelector('[data-part-cancel]').addEventListener('click', () => closePart(form));
        form.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closePart(form);
            }
        });
        form.addEventListener('submit', event => {
            event.preventDefault();
            clearErrors(form);
            let oldValues = {};
            try {
                oldValues = JSON.parse(form.dataset.old || '{}');
            } catch (e) {
                oldValues = {};
            }
            openDialog({
                url: '/sejour/partie/modifier',
                form,
                summary: `Modification de la partie « ${form.dataset.title} » du dossier.`,
                payload: { episodeOfCare: idEpisodeOfCare, part: form.dataset.part, values: formValues(form), oldValues },
            });
        });
    });

    // ── Déclaration du décès : POST /sejour/deces (date et heure du décès, valeur actuelle du décès) ──
    const death = document.querySelector('[data-declare-death]');
    if (death) {
        // Retours du 2026-11-04 : le décès met fin au séjour à l'heure saisie ; les soins prévus après sont annulés.
        death.addEventListener('click', () => openDialog({
            url: '/sejour/deces',
            form: null,
            discharge: true,
            dischargeLabels: ['Date du décès', 'Heure du décès'],
            summary: 'Déclarer le décès du patient : il sera signalé sur tous les écrans de soins, le séjour '
                + 'se terminera à l\'heure du décès et les soins prévus après seront annulés.',
            payload: {
                episodeOfCare: idEpisodeOfCare,
                oldDeceased: death.dataset.current || '',
            },
        }));
    }

    // ── Fin du séjour ──
    const endStay = document.querySelector('[data-end-stay]');
    if (endStay) {
        endStay.addEventListener('click', () => openDialog({
            url: '/sejour/cloturer',
            form: null,
            discharge: true,
            summary: 'Mettre fin au séjour : le patient n\'apparaîtra plus comme présent dans le service.',
            payload: { episodeOfCare: idEpisodeOfCare },
        }));
    }
})();
