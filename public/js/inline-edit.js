// Édition champ par champ du dossier complet (PR « dossier-patient-edition-inline »).
//
// Chaque <dd class="inline-edit"> porte l'entité, l'identifiant, le champ, le type et la valeur brute.
// Clic ou Entrée sur la valeur → contrôle de saisie du bon type. Quitter le champ (blur) ou Entrée
// (Ctrl+Entrée pour une zone multiligne) avec une valeur CHANGÉE → confirmation sous le champ
// (« ancienne → nouvelle », Enregistrer / Annuler). Échap annule et restaure. Valeur inchangée → rien.
// Enregistrement en JSON (POST, un champ à la fois, ancienne valeur jointe pour détecter un conflit).
// Le serveur reste la garantie : liste blanche, validation et historique sont faits côté PHP.

(function () {
    const root = document.querySelector('[data-inline-edit]');
    if (!root) {
        return;
    }
    const endpoint = root.dataset.endpoint;
    const csrf = root.dataset.csrf;
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

    // Contrôle de saisie adapté au type du champ.
    function buildInput(cell) {
        const type = cell.dataset.type;
        const raw = cell.dataset.raw || '';
        let input;
        if (type === 'textarea') {
            input = document.createElement('textarea');
            input.rows = 4;
        } else if (type === 'select') {
            input = document.createElement('select');
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = EMPTY_LABEL;
            input.appendChild(empty);
            let options = {};
            try {
                options = JSON.parse(cell.dataset.options || '{}');
            } catch (e) {
                options = {};
            }
            Object.keys(options).forEach(value => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = options[value];
                input.appendChild(option);
            });
        } else {
            input = document.createElement('input');
            if (type === 'number') {
                input.type = 'number';
                input.step = '0.01';
                input.min = '0.01';
                input.max = '999.99';
                input.inputMode = 'decimal';
            } else if (type === 'date') {
                input.type = 'date';
            } else if (type === 'email' || type === 'tel') {
                input.type = type;
            } else {
                input.type = 'text';
            }
        }
        input.className = 'inline-edit__input';
        input.value = raw;
        input.setAttribute('aria-label', cell.dataset.label || '');
        return input;
    }

    function displayFor(cell, raw) {
        if (raw === '' || raw === null) {
            return null;
        }
        if (cell.dataset.type === 'select') {
            try {
                const options = JSON.parse(cell.dataset.options || '{}');
                return options[raw] || raw;
            } catch (e) {
                return raw;
            }
        }
        if (cell.dataset.type === 'date') {
            const parts = raw.split('-');
            return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : raw;
        }
        return raw;
    }

    function renderValue(cell, display) {
        const button = cell.querySelector('.inline-edit__value');
        button.textContent = '';
        if (display === null) {
            const span = document.createElement('span');
            span.className = 'non-renseigne';
            span.textContent = EMPTY_LABEL;
            button.appendChild(span);
        } else {
            button.textContent = display;
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

    function leaveEditMode(cell) {
        cell.classList.remove('inline-edit--editing');
        cell.querySelectorAll('.inline-edit__input, .inline-edit__confirm, .inline-edit__error').forEach(el => el.remove());
        cell.querySelector('.inline-edit__value').hidden = false;
    }

    function cancel(cell) {
        leaveEditMode(cell);
        cell.querySelector('.inline-edit__value').focus();
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
        const cells = [
            `${pad(now.getDate())}/${pad(now.getMonth() + 1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`,
            'non identifié',
            cell.dataset.label,
            oldDisplay === null ? 'vide' : oldDisplay,
            newDisplay === null ? 'vide' : newDisplay,
        ];
        cells.forEach(text => {
            row.insertCell().textContent = text;
        });
    }

    async function save(cell, input, oldRaw, newRaw) {
        clearError(cell);
        let response;
        let data;
        try {
            response = await fetch(endpoint, {
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
                    field: cell.dataset.field,
                    value: newRaw,
                    oldValue: oldRaw,
                }),
            });
            data = await response.json();
        } catch (e) {
            showError(cell, "L'enregistrement a échoué. Vérifiez la connexion puis réessayez.");
            input.focus();
            return;
        }

        if (data.conflict) {
            // Quelqu'un d'autre a modifié le champ : on repart de la valeur actuelle, la saisie reste ouverte.
            cell.dataset.raw = data.value === null ? '' : data.value;
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
        renderValue(cell, data.display === null ? null : data.display);
        leaveEditMode(cell);
        cell.querySelector('.inline-edit__value').focus();
        if (data.saved) {
            announce('Enregistré', false);
            appendHistory(cell, oldDisplay, data.display);
        }
    }

    function showConfirmation(cell, input, oldRaw, newRaw) {
        cell.querySelectorAll('.inline-edit__confirm').forEach(el => el.remove());
        const box = document.createElement('div');
        // Même composant visuel que la confirmation de rattachement de l'admission (.confirm-box).
        box.className = 'confirm-box inline-edit__confirm';
        box.setAttribute('role', 'group');
        box.setAttribute('aria-label', 'Confirmer la modification');

        const text = document.createElement('p');
        const oldDisplay = displayFor(cell, oldRaw);
        const newDisplay = displayFor(cell, newRaw);
        text.textContent = `${oldDisplay === null ? EMPTY_LABEL : oldDisplay} → ${newDisplay === null ? EMPTY_LABEL : newDisplay}`;
        box.appendChild(text);

        const saveButton = document.createElement('button');
        saveButton.type = 'button';
        saveButton.className = 'button inline-edit__save';
        saveButton.textContent = 'Enregistrer';
        saveButton.addEventListener('click', () => save(cell, input, oldRaw, newRaw));

        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'button button--secondary inline-edit__cancel';
        cancelButton.textContent = 'Annuler';
        cancelButton.addEventListener('click', () => cancel(cell));

        box.appendChild(saveButton);
        box.appendChild(cancelButton);
        cell.appendChild(box);
        saveButton.focus();
    }

    // Valeur « changée » : comparée après nettoyage, comme le fera le serveur.
    function normalize(value) {
        return (value || '').replace(/\r\n/g, '\n').trim();
    }

    function commit(cell, input) {
        const oldRaw = normalize(cell.dataset.raw);
        const newRaw = normalize(input.value);
        if (newRaw === oldRaw) {
            leaveEditMode(cell);
            return;
        }
        showConfirmation(cell, input, oldRaw, newRaw);
    }

    function enterEditMode(cell) {
        if (cell.classList.contains('inline-edit--editing')) {
            return;
        }
        cell.classList.add('inline-edit--editing');
        const button = cell.querySelector('.inline-edit__value');
        button.hidden = true;
        const input = buildInput(cell);
        cell.appendChild(input);
        input.focus();

        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                cancel(cell);
            } else if (event.key === 'Enter' && (input.tagName !== 'TEXTAREA' || event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                commit(cell, input);
            }
        });
        // Un clic sur Enregistrer / Annuler déclenche aussi le blur : on laisse la confirmation
        // déjà affichée en place et on ne la recrée pas.
        input.addEventListener('blur', () => {
            setTimeout(() => {
                if (!cell.classList.contains('inline-edit--editing')) {
                    return;
                }
                if (cell.querySelector('.inline-edit__confirm') || cell.contains(document.activeElement)) {
                    return;
                }
                commit(cell, input);
            }, 0);
        });
        input.addEventListener('change', () => {
            if (input.tagName === 'SELECT') {
                commit(cell, input);
            }
        });
    }

    root.querySelectorAll('.inline-edit').forEach(cell => {
        cell.querySelector('.inline-edit__value').addEventListener('click', () => enterEditMode(cell));
    });
})();
