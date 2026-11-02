// Recherche d'un patient existant à l'admission (PR « admission-recherche-patient »), par IPP
// (AURA-15, prioritaire) ou par nom / prénom. Un seul chemin de code pour les deux modes :
// le serveur (/patients/recherche?q=…) reconnaît un IPP (6 à 9 chiffres) et renvoie 0 ou 1 résultat.
//
// Identitovigilance : rien n'est sélectionné automatiquement. Les résultats affichent nom, prénom,
// nom de naissance, date de naissance et âge, sexe, IPP, dernier séjour ; un avertissement
// apparaît en cas d'homonymes ; la sélection demande une confirmation ; seule la confirmation
// charge la fiche (/patients/fiche?id=…) pour pré-remplir le formulaire. Le champ caché idPatient
// rattache ensuite le séjour au patient existant (contrôlé côté serveur).
//
// Accessibilité : combobox (role, aria-expanded, aria-activedescendant), listbox, flèches / Entrée /
// Échap, nombre de résultats annoncé en aria-live.

(function () {
    const form = document.getElementById('admission-form');
    if (!form) {
        return;
    }
    const ippInput = form.querySelector('input[name="ipp"]');
    const ippStatus = document.getElementById('ipp-statut');
    const searchInput = form.querySelector('input[name="patientSearch"]');
    const searchStatus = document.getElementById('patient-search-status');
    const listbox = document.getElementById('patient-suggestions');
    const idPatientInput = form.querySelector('input[name="idPatient"]');
    const modeInput = form.querySelector('input[name="searchMode"]');
    if (!ippInput || !searchInput || !listbox || !idPatientInput || !modeInput) {
        return;
    }

    const SEARCH_URL = '/patients/recherche';
    const RECORD_URL = '/patients/fiche';
    const DEBOUNCE_MS = 300;
    const MIN_LENGTH = 2;
    const IPP_PATTERN = /^\d{6,9}$/;
    const MESSAGES = {
        searching: 'Recherche en cours…',
        none: 'Aucun patient trouvé — poursuivez la saisie pour créer un nouveau dossier.',
        ippUnknown: 'IPP inconnu, saisie manuelle.',
        tooShort: 'Saisissez au moins 2 caractères.',
        failure: 'La recherche a échoué. Veuillez réessayer.',
        refine: 'Plus de 20 résultats : affinez votre recherche.',
        homonyms: 'Plusieurs patients portent ce nom — vérifiez la date de naissance et l\'IPP avant de sélectionner.',
        results: n => `${n} résultat${n > 1 ? 's' : ''}`,
        confirm: p => `Rattacher ce séjour à : ${p.nom} ${p.prenom}, né(e) le ${p.dateNaissance || 'date inconnue'}, IPP ${p.ipp || 'sans IPP'} ?`,
        banner: p => `Données issues du dossier existant de ${p.nom} ${p.prenom} (IPP ${p.ipp || 'sans IPP'}) — vérifiez-les.`,
        change: 'Changer de patient',
        loadFailure: 'Impossible de charger la fiche du patient. Réessayez.',
    };

    let timer = null;
    let results = [];
    let activeIndex = -1;
    let activeInput = searchInput;
    let requestId = 0;

    function setStatus(target, message, kind) {
        if (!target) {
            return;
        }
        target.textContent = '';
        target.className = 'field-status' + (kind ? ' ' + kind : '');
        if (message) {
            const p = document.createElement('p');
            p.textContent = message;
            target.appendChild(p);
        }
    }

    function describe(p) {
        const parts = [];
        parts.push(p.dateNaissance ? `né(e) le ${p.dateNaissance}${p.age !== null ? ` (${p.age} ans)` : ''}` : 'date de naissance inconnue');
        parts.push(p.sexe ? `sexe ${p.sexe}` : 'sexe non renseigné');
        parts.push(p.ipp ? `IPP ${p.ipp}` : 'sans IPP');
        if (p.nomNaissance) {
            parts.push(`né(e) ${p.nomNaissance}`);
        }
        parts.push(p.dernierSejour ? `dernier séjour le ${p.dernierSejour}` : 'aucun séjour');
        return parts.join(', ');
    }

    function closeList() {
        listbox.hidden = true;
        listbox.textContent = '';
        results = [];
        activeIndex = -1;
        [ippInput, searchInput].forEach(input => {
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        });
    }

    function setActive(index) {
        const options = listbox.querySelectorAll('[role="option"]');
        options.forEach(o => o.classList.remove('is-active'));
        if (index < 0 || index >= options.length) {
            activeIndex = -1;
            activeInput.removeAttribute('aria-activedescendant');
            return;
        }
        activeIndex = index;
        options[index].classList.add('is-active');
        activeInput.setAttribute('aria-activedescendant', options[index].id);
        options[index].scrollIntoView({ block: 'nearest' });
    }

    function renderResults(data, statusTarget, isIpp) {
        closeList();
        results = data.resultats || [];
        if (results.length === 0) {
            setStatus(statusTarget, isIpp ? MESSAGES.ippUnknown : MESSAGES.none, 'inconnu');
            return;
        }
        setStatus(statusTarget, MESSAGES.results(results.length), 'trouve');
        if (data.homonymes) {
            const warning = document.createElement('li');
            warning.className = 'suggestions__warning';
            warning.setAttribute('role', 'presentation');
            warning.textContent = MESSAGES.homonyms;
            listbox.appendChild(warning);
        }
        results.forEach((p, i) => {
            const li = document.createElement('li');
            li.id = `patient-suggestion-${i}`;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            li.className = 'suggestions__option';
            const name = document.createElement('strong');
            name.textContent = `${p.nom} ${p.prenom}`;
            const details = document.createElement('span');
            details.textContent = ' — ' + describe(p);
            li.appendChild(name);
            li.appendChild(details);
            // mousedown plutôt que click : le blur du champ ne doit pas fermer la liste avant la sélection.
            li.addEventListener('mousedown', event => {
                event.preventDefault();
                select(i);
            });
            listbox.appendChild(li);
        });
        if (data.tronque) {
            const more = document.createElement('li');
            more.className = 'suggestions__warning';
            more.setAttribute('role', 'presentation');
            more.textContent = MESSAGES.refine;
            listbox.appendChild(more);
        }
        listbox.hidden = false;
        activeInput.setAttribute('aria-expanded', 'true');
    }

    async function search(query, input, statusTarget) {
        const q = query.trim();
        activeInput = input;
        if (q.length < MIN_LENGTH) {
            closeList();
            setStatus(statusTarget, q.length === 0 ? '' : MESSAGES.tooShort, 'inconnu');
            return;
        }
        const isIpp = IPP_PATTERN.test(q);
        setStatus(statusTarget, MESSAGES.searching, 'en-cours');
        const current = ++requestId;
        let data;
        try {
            const response = await fetch(`${SEARCH_URL}?q=${encodeURIComponent(q)}`, {
                headers: { 'Accept': 'application/json' },
            });
            data = await response.json();
        } catch (e) {
            if (current === requestId) {
                closeList();
                setStatus(statusTarget, MESSAGES.failure, 'erreur');
            }
            return;
        }
        if (current !== requestId) {
            return; // une saisie plus récente a pris le relais
        }
        if (!data.ok) {
            closeList();
            setStatus(statusTarget, (data.erreurs && Object.values(data.erreurs)[0]) || MESSAGES.failure, 'erreur');
            return;
        }
        renderResults(data, statusTarget, isIpp);
    }

    function scheduleSearch(input, statusTarget) {
        clearTimeout(timer);
        timer = setTimeout(() => search(input.value, input, statusTarget), DEBOUNCE_MS);
    }

    // ---- Sélection : confirmation explicite, puis chargement de la fiche ----

    function removeConfirmation() {
        form.querySelectorAll('.confirm-box--patient').forEach(el => el.remove());
    }

    function select(index) {
        const p = results[index];
        if (!p) {
            return;
        }
        const mode = IPP_PATTERN.test(activeInput.value.trim()) ? 'ipp' : 'name';
        closeList();
        removeConfirmation();

        const box = document.createElement('div');
        box.className = 'confirm-box confirm-box--patient';
        box.setAttribute('role', 'group');
        box.setAttribute('aria-label', 'Confirmer le rattachement');
        const text = document.createElement('p');
        text.textContent = MESSAGES.confirm(p);
        const confirmButton = document.createElement('button');
        confirmButton.type = 'button';
        confirmButton.className = 'button';
        confirmButton.textContent = 'Confirmer';
        confirmButton.addEventListener('click', () => loadRecord(p, mode));
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'button button--secondary';
        cancelButton.textContent = 'Annuler';
        cancelButton.addEventListener('click', () => {
            removeConfirmation();
            activeInput.focus();
        });
        box.appendChild(text);
        box.appendChild(confirmButton);
        box.appendChild(cancelButton);
        listbox.insertAdjacentElement('afterend', box);
        confirmButton.focus();
    }

    function setFieldValue(name, value) {
        const control = form.querySelector(`[name="${CSS.escape(name)}"]`);
        if (!control || control.type === 'hidden') {
            return;
        }
        control.value = value || '';
        control.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function fillForm(fiche) {
        Object.keys(fiche.patient).forEach(name => {
            if (name === 'gender') {
                form.querySelectorAll('input[type="radio"][name="gender"]').forEach(radio => {
                    radio.checked = radio.value === fiche.patient.gender;
                });
                return;
            }
            setFieldValue(name, fiche.patient[name]);
        });
        const allergies = fiche.allergies || [];
        const criticalities = fiche.allergyCriticalities || {};
        form.querySelectorAll('input[type="checkbox"][name="allergies[]"]').forEach(box => {
            box.checked = allergies.includes(box.value);
            const select = form.querySelector(`select[name="allergyCriticality[${CSS.escape(box.value)}]"]`);
            if (select) {
                select.value = box.checked ? (criticalities[box.value] || '') : '';
            }
        });
        form.dispatchEvent(new CustomEvent('aura:allergies-filled'));
    }

    function showBanner(p) {
        removeBanner();
        const banner = document.createElement('div');
        banner.className = 'patient-banner';
        banner.setAttribute('role', 'status');
        const text = document.createElement('p');
        text.textContent = MESSAGES.banner(p);
        const change = document.createElement('button');
        change.type = 'button';
        change.className = 'button button--secondary';
        change.textContent = MESSAGES.change;
        change.addEventListener('click', resetPatient);
        banner.appendChild(text);
        banner.appendChild(change);
        const heading = document.getElementById('identite');
        (heading ? heading.parentElement : listbox).insertAdjacentElement('beforebegin', banner);
    }

    function removeBanner() {
        form.querySelectorAll('.patient-banner').forEach(el => el.remove());
    }

    // « Changer de patient » : vide tout le formulaire, y compris le rattachement.
    function resetPatient() {
        form.reset();
        idPatientInput.value = '';
        modeInput.value = '';
        removeBanner();
        removeConfirmation();
        closeList();
        setStatus(ippStatus, '', null);
        setStatus(searchStatus, '', null);
        form.querySelectorAll('input, textarea, select').forEach(control => {
            control.dispatchEvent(new Event('input', { bubbles: true }));
        });
        searchInput.focus();
    }

    async function loadRecord(p, mode) {
        removeConfirmation();
        let data;
        try {
            const response = await fetch(`${RECORD_URL}?id=${encodeURIComponent(p.idPatient)}`, {
                headers: { 'Accept': 'application/json' },
            });
            data = await response.json();
        } catch (e) {
            setStatus(searchStatus, MESSAGES.loadFailure, 'erreur');
            return;
        }
        if (!data.ok) {
            setStatus(searchStatus, data.message || MESSAGES.loadFailure, 'erreur');
            return;
        }
        fillForm(data);
        idPatientInput.value = String(p.idPatient);
        modeInput.value = mode;
        searchInput.value = '';
        setStatus(ippStatus, '', null);
        setStatus(searchStatus, '', null);
        showBanner(p);
        const firstField = form.querySelector('input[name="lastname"]');
        if (firstField) {
            firstField.focus();
        }
    }

    // ---- Clavier et événements ----

    function onKeydown(event) {
        if (listbox.hidden) {
            return;
        }
        const count = results.length;
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((activeIndex + 1) % count);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((activeIndex - 1 + count) % count);
        } else if (event.key === 'Enter') {
            if (activeIndex >= 0) {
                event.preventDefault();
                select(activeIndex);
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closeList();
        }
    }

    searchInput.addEventListener('input', () => {
        // Un rattachement déjà confirmé (idPatient posé) reste en place tant que l'utilisateur ne confirme
        // pas un autre patient ou ne clique pas « Changer de patient ».
        scheduleSearch(searchInput, searchStatus);
    });
    searchInput.addEventListener('keydown', onKeydown);
    ippInput.addEventListener('input', () => {
        if (IPP_PATTERN.test(ippInput.value.trim())) {
            scheduleSearch(ippInput, ippStatus);
        } else {
            closeList();
            setStatus(ippStatus, '', null);
        }
    });
    ippInput.addEventListener('keydown', event => {
        if (event.key === 'Enter' && listbox.hidden) {
            event.preventDefault();
            search(ippInput.value, ippInput, ippStatus);
            return;
        }
        onKeydown(event);
    });
    [searchInput, ippInput].forEach(input => {
        input.addEventListener('blur', () => {
            // Laisser le mousedown d'une option s'exécuter avant de fermer.
            setTimeout(() => {
                if (!form.querySelector('.confirm-box--patient')) {
                    closeList();
                }
            }, 150);
        });
    });
})();
