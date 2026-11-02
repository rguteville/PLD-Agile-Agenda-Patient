// Édition champ par champ du dossier complet (PR « dossier-patient-edition-inline »).
//
// Chaque <dd class="inline-edit"> porte l'entité, l'identifiant, le champ, le type et la valeur brute.
// Au survol (ou au focus clavier), un crayon apparaît sur la valeur. Clic ou Entrée : la valeur est
// REMPLACÉE sur place par un champ du bon type (elle n'est jamais affichée deux fois), suivi du bouton
// « Confirmer » et d'une petite croix d'annulation (indispensable au doigt sur tablette, où Échap
// n'existe pas). Entrée (Ctrl+Entrée en multiligne) confirme aussi ; Échap annule et restaure.
// Une valeur inchangée referme simplement le champ. Enregistrement en JSON (POST, un champ à la fois,
// ancienne valeur jointe pour détecter un conflit). Le serveur reste la garantie : liste blanche,
// validation, concurrence et historique sont faits côté PHP.

(function () {
    const root = document.querySelector('[data-inline-edit]');
    if (!root) {
        return;
    }
    const endpoint = root.dataset.endpoint;
    const csrf = root.dataset.csrf;
    const idSejour = root.dataset.idSejour;
    const status = document.getElementById('inline-edit-status');
    const EMPTY_LABEL = 'Non renseigné';
    const STATUS_DELAY_MS = 2000;
    let statusTimer = null;

    function announce(message, isError) {
        if (!status) {
            return;
        }
        clearTimeout(statusTimer);
        status.textContent = message;
        status.hidden = false;
        status.classList.toggle('inline-edit__status--error', Boolean(isError));
        statusTimer = setTimeout(() => {
            status.hidden = true;
        }, STATUS_DELAY_MS);
    }

    function parseOptions(cell) {
        try {
            return JSON.parse(cell.dataset.options || '{}');
        } catch (e) {
            return {};
        }
    }

    // Contrôle de saisie adapté au type du champ, prérempli avec la valeur actuelle.
    function buildInput(cell) {
        const type = cell.dataset.type;
        let input;
        if (type === 'textarea') {
            input = document.createElement('textarea');
            input.rows = 4;
        } else if (type === 'select') {
            input = document.createElement('select');
            input.add(new Option(EMPTY_LABEL, ''));
            const options = parseOptions(cell);
            Object.keys(options).forEach(value => input.add(new Option(options[value], value)));
        } else {
            input = document.createElement('input');
            if (type === 'number') {
                input.type = 'number';
                input.step = '0.01';
                input.min = '0.01';
                input.max = '999.99';
                input.inputMode = 'decimal';
            } else if (type === 'date' || type === 'email' || type === 'tel') {
                input.type = type;
            } else {
                input.type = 'text';
            }
        }
        input.className = 'inline-edit__input';
        input.value = cell.dataset.raw || '';
        input.setAttribute('aria-label', cell.dataset.label || '');
        return input;
    }

    function displayFor(cell, raw) {
        if (raw === '' || raw === null) {
            return null;
        }
        if (cell.dataset.type === 'select') {
            return parseOptions(cell)[raw] || raw;
        }
        if (cell.dataset.type === 'date') {
            const parts = raw.split('-');
            return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : raw;
        }
        return raw;
    }

    // Réécrit seulement le texte de la valeur : le crayon (frère du texte) est conservé.
    function renderValue(cell, display) {
        const text = cell.querySelector('.inline-edit__text');
        text.textContent = '';
        if (display === null) {
            const span = document.createElement('span');
            span.className = 'non-renseigne';
            span.textContent = EMPTY_LABEL;
            text.appendChild(span);
        } else {
            text.textContent = display;
        }
    }

    function showError(cell, message) {
        clearError(cell);
        const p = document.createElement('p');
        p.className = 'inline-edit__error';
        p.setAttribute('role', 'alert');
        p.textContent = message;
        cell.appendChild(p);
    }

    function clearError(cell) {
        cell.querySelectorAll('.inline-edit__error').forEach(el => el.remove());
    }

    function isEditing(cell) {
        return cell.classList.contains('inline-edit--editing');
    }

    // Referme le champ : la valeur (avec son crayon) reprend sa place, rien n'est dupliqué.
    function leaveEditMode(cell, focusValue) {
        cell.classList.remove('inline-edit--editing');
        cell.querySelectorAll('.inline-edit__editor, .inline-edit__error').forEach(el => el.remove());
        const button = cell.querySelector('.inline-edit__value');
        button.hidden = false;
        if (focusValue) {
            button.focus();
        }
    }

    // Ajoute une ligne en tête de l'historique affiché (le serveur l'a déjà écrite en base).
    function appendHistory(cell, oldDisplay, newDisplay) {
        const table = document.getElementById('dossier-historique-table');
        if (!table) {
            return;
        }
        const row = table.tBodies[0].insertRow(0);
        const now = new Date();
        const pad = n => String(n).padStart(2, '0');
        [
            `${pad(now.getDate())}/${pad(now.getMonth() + 1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`,
            'non identifié',
            cell.dataset.label,
            oldDisplay === null ? 'vide' : oldDisplay,
            newDisplay === null ? 'vide' : newDisplay,
        ].forEach(text => {
            row.insertCell().textContent = text;
        });
    }

    // Valeur « changée » : comparée après nettoyage, comme le fera le serveur.
    function normalize(value) {
        return (value || '').replace(/\r\n/g, '\n').trim();
    }

    async function submitEdit(cell, input, button) {
        // Un seul envoi à la fois : Entrée répétée ou double clic ne renvoient pas la même modification.
        if (button.disabled) {
            return;
        }
        const oldRaw = normalize(cell.dataset.raw);
        const newRaw = normalize(input.value);
        if (newRaw === oldRaw) {
            leaveEditMode(cell, true);
            return;
        }

        clearError(cell);
        button.disabled = true;
        let data;
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    entity: cell.dataset.entity,
                    id: cell.dataset.id,
                    sejour: idSejour,
                    field: cell.dataset.field,
                    value: newRaw,
                    oldValue: oldRaw,
                }),
            });
            data = await response.json();
        } catch (e) {
            data = { ok: false, message: "L'enregistrement a échoué. Vérifiez la connexion puis réessayez." };
        }
        button.disabled = false;

        if (data.conflict) {
            // Quelqu'un d'autre a modifié le champ entre-temps : la valeur actuelle devient la référence,
            // la saisie reste ouverte pour que l'utilisateur décide en connaissance de cause.
            cell.dataset.raw = data.value === null ? '' : data.value;
            renderValue(cell, data.display);
            showError(cell, `${data.message} (${data.display === null ? EMPTY_LABEL : data.display})`);
            input.focus();
            return;
        }
        if (!data.ok) {
            showError(cell, data.message || "L'enregistrement a échoué.");
            input.focus();
            return;
        }

        const oldDisplay = displayFor(cell, oldRaw);
        cell.dataset.raw = data.value === null ? '' : data.value;
        renderValue(cell, data.display);
        leaveEditMode(cell, true);
        if (data.saved) {
            announce('Enregistré', false);
            appendHistory(cell, oldDisplay, data.display);
        }
    }

    function enterEditMode(cell) {
        if (isEditing(cell)) {
            return;
        }
        cell.classList.add('inline-edit--editing');
        cell.querySelector('.inline-edit__value').hidden = true;

        const editor = document.createElement('div');
        editor.className = 'inline-edit__editor';
        const input = buildInput(cell);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button inline-edit__confirm-button';
        button.textContent = 'Confirmer';
        button.setAttribute('aria-label', `Confirmer : ${cell.dataset.label || ''}`);
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'inline-edit__cancel-button';
        cancel.textContent = '✕';
        cancel.title = 'Annuler (Échap)';
        cancel.setAttribute('aria-label', `Annuler la modification : ${cell.dataset.label || ''}`);
        editor.appendChild(input);
        editor.appendChild(button);
        editor.appendChild(cancel);
        cell.appendChild(editor);
        input.focus();
        if (typeof input.select === 'function' && input.tagName !== 'SELECT') {
            input.select();
        }

        button.addEventListener('click', () => submitEdit(cell, input, button));
        cancel.addEventListener('click', () => leaveEditMode(cell, true));
        editor.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                leaveEditMode(cell, true);
            } else if (event.key === 'Enter' && event.target === input
                && (input.tagName !== 'TEXTAREA' || event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                submitEdit(cell, input, button);
            }
        });
        // Focus sorti de la cellule sans rien changer : on referme. Une saisie modifiée reste ouverte
        // (rien n'est perdu ni enregistré sans « Confirmer »).
        editor.addEventListener('focusout', () => {
            setTimeout(() => {
                if (isEditing(cell) && !cell.contains(document.activeElement)
                    && normalize(input.value) === normalize(cell.dataset.raw)) {
                    leaveEditMode(cell, false);
                }
            }, 0);
        });
    }

    // Fermeture ou rechargement de la page avec une saisie modifiée non confirmée : le navigateur demande
    // confirmation, rien n'est perdu sans que l'utilisateur le sache.
    window.addEventListener('beforeunload', event => {
        const pending = [...root.querySelectorAll('.inline-edit--editing')].some(cell => {
            const input = cell.querySelector('.inline-edit__input');
            return input && normalize(input.value) !== normalize(cell.dataset.raw);
        });
        if (pending) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    root.querySelectorAll('.inline-edit').forEach(cell => {
        cell.querySelector('.inline-edit__value').addEventListener('click', () => enterEditMode(cell));
    });
})();
