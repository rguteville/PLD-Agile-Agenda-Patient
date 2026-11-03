// Recherche d'un patient existant à l'admission (PR « admission-recherche-patient ») : un seul champ
// en tête (« IPP, nom ou prénom », retours du 2026-11-02), qui reconnaît un IPP ; recherche par IPP
// (AURA-15, prioritaire) ou par nom / prénom. Un seul chemin de code pour les deux modes :
// le serveur (/patients/recherche?q=…) reconnaît un IPP (6 à 9 chiffres) et renvoie 0 ou 1 résultat.
//
// Identitovigilance : rien n'est sélectionné automatiquement. Les résultats affichent nom, prénom,
// nom de naissance, date de naissance et âge, sexe, IPP, dernier séjour ; un avertissement
// apparaît en cas d'homonymes ; la sélection (clic ou Entrée, jamais automatique) charge la fiche
// (/patients/fiche?id=…), affiche l'identité dans une carte en lecture seule et pré-remplit le
// formulaire. Le champ caché idPatient rattache ensuite le séjour au patient existant (contrôlé
// côté serveur) ; « Ce n'est pas ce patient » annule ce rattachement.
//
// Accessibilité : combobox (role, aria-expanded, aria-activedescendant), listbox, flèches / Entrée /
// Échap, nombre de résultats annoncé en aria-live.

(function () {
    const form = document.getElementById('admission-form');
    if (!form) {
        return;
    }
    const searchInput = form.querySelector('input[name="patientSearch"]');
    const searchStatus = document.getElementById('patient-search-status');
    const listbox = document.getElementById('patient-suggestions');
    const idPatientInput = form.querySelector('input[name="idPatient"]');
    const modeInput = form.querySelector('input[name="searchMode"]');
    if (!searchInput || !listbox || !idPatientInput || !modeInput) {
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
        cardTitle: 'Patient existant',
        correct: 'Corriger l\'identité',
        detach: 'Ce n\'est pas ce patient',
        noAllergy: 'Aucune allergie connue',
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
        parts.push(p.birthDate ? `né(e) le ${p.birthDate}${p.age !== null ? ` (${p.age} ans)` : ''}` : 'date de naissance inconnue');
        parts.push(p.sex ? `sexe ${p.sex}` : 'sexe non renseigné');
        parts.push(p.ipp ? `IPP ${p.ipp}` : 'sans IPP');
        if (p.birthLastName) {
            parts.push(`né(e) ${p.birthLastName}`);
        }
        parts.push(p.lastEpisodeOfCare ? `dernier séjour le ${p.lastEpisodeOfCare}` : 'aucun séjour');
        return parts.join(', ');
    }

    function closeList() {
        listbox.hidden = true;
        listbox.textContent = '';
        results = [];
        activeIndex = -1;
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
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
        results = data.results || [];
        if (results.length === 0) {
            setStatus(statusTarget, isIpp ? MESSAGES.ippUnknown : MESSAGES.none, 'inconnu');
            return;
        }
        setStatus(statusTarget, MESSAGES.results(results.length), 'trouve');
        if (data.homonyms) {
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
            name.textContent = `${p.lastName} ${p.firstName}`;
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
        if (data.truncated) {
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
            setStatus(statusTarget, (data.errors && Object.values(data.errors)[0]) || MESSAGES.failure, 'erreur');
            return;
        }
        renderResults(data, statusTarget, isIpp);
    }

    function scheduleSearch(input, statusTarget) {
        clearTimeout(timer);
        timer = setTimeout(() => search(input.value, input, statusTarget), DEBOUNCE_MS);
    }

    // ---- Sélection : chargement direct de la fiche ----
    // Pas de confirmation intermédiaire (retours du 2026-11-02) : la carte affichée après la sélection
    // montre l'identité complète, et « Ce n'est pas ce patient » annule le rattachement.

    function select(index) {
        const p = results[index];
        if (!p) {
            return;
        }
        const mode = IPP_PATTERN.test(activeInput.value.trim()) ? 'ipp' : 'name';
        closeList();
        loadRecord(p, mode);
    }

    function setFieldValue(name, value) {
        const control = form.querySelector(`[name="${CSS.escape(name)}"]`);
        if (!control || control.type === 'hidden') {
            return;
        }
        control.value = value || '';
        control.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function fillForm(patientRecord) {
        Object.keys(patientRecord.patient).forEach(name => {
            if (name === 'gender') {
                form.querySelectorAll('input[type="radio"][name="gender"]').forEach(radio => {
                    radio.checked = radio.value === patientRecord.patient.gender;
                });
                return;
            }
            setFieldValue(name, patientRecord.patient[name]);
        });
        const allergies = patientRecord.allergies || [];
        const criticalities = patientRecord.allergyCriticalities || {};
        form.querySelectorAll('input[type="checkbox"][name="allergies[]"]').forEach(box => {
            box.checked = allergies.includes(box.value);
            const select = form.querySelector(`select[name="allergyCriticality[${CSS.escape(box.value)}]"]`);
            if (select) {
                select.value = box.checked ? (criticalities[box.value] || '') : '';
            }
        });
        form.dispatchEvent(new CustomEvent('aura:allergies-filled'));
    }

    // ---- Carte du patient existant (retours du 2026-11-02) ----
    // L'identité d'un patient retrouvé s'affiche en lecture seule, comme le bandeau : on ne la modifie
    // plus par mégarde. « Corriger l'identité » rouvre les champs ; « Ce n'est pas ce patient » détache.

    const IDENTITY_FIELDS = ['lastname', 'firstname', 'birthDate', 'gender', 'ipp'];

    function identityRows() {
        return IDENTITY_FIELDS
            .map(name => form.querySelector(`[name="${CSS.escape(name)}"]`))
            .filter(Boolean)
            .map(control => control.closest('.form-row'))
            .filter(Boolean);
    }

    function showIdentityFields(visible) {
        identityRows().forEach(row => { row.hidden = !visible; });
    }

    // Niveau d'affichage d'une criticité (même règle que Allergie::criticalityLevel()).
    function level(criticality) {
        if (criticality === 'Élevée') {
            return 'high';
        }
        return criticality === 'Faible' ? 'low' : 'unknown';
    }

    function removeCard() {
        form.querySelectorAll('.patient-card').forEach(el => el.remove());
    }

    function showCard(p, patientRecord) {
        removeCard();
        const card = document.createElement('section');
        card.className = 'patient-card';
        card.setAttribute('aria-label', MESSAGES.cardTitle);

        const title = document.createElement('p');
        title.className = 'patient-card__title';
        title.textContent = MESSAGES.cardTitle;

        const name = document.createElement('p');
        name.className = 'patient-card__name';
        const strong = document.createElement('strong');
        strong.textContent = `${p.lastName.toUpperCase()} ${p.firstName}`;
        name.appendChild(strong);
        name.append(p.sex ? ` (${p.sex})` : '');
        const birth = p.birthDate
            ? ` · né(e) le ${p.birthDate}${p.age !== null ? ` (${p.age} ans)` : ''}`
            : ' · date de naissance inconnue';
        name.append(birth);

        const ids = document.createElement('p');
        ids.className = 'patient-card__ids';
        ids.textContent = `IPP ${p.ipp || 'sans IPP'} · `
            + (p.lastEpisodeOfCare ? `dernier séjour le ${p.lastEpisodeOfCare}` : 'aucun séjour');

        const allergies = document.createElement('div');
        allergies.className = 'patient-card__allergies';
        const labels = patientRecord.allergyLabels || [];
        const ids2 = patientRecord.allergies || [];
        const crit = patientRecord.allergyCriticalities || {};
        if (labels.length === 0) {
            const none = document.createElement('span');
            none.className = 'allergie allergie--none';
            none.textContent = MESSAGES.noAllergy;
            allergies.appendChild(none);
        } else {
            const head = document.createElement('span');
            head.className = 'patient-card__allergies-label';
            head.textContent = 'Allergies :';
            allergies.appendChild(head);
            labels.forEach((label, i) => {
                const chip = document.createElement('span');
                const c = crit[ids2[i]] || null;
                chip.className = `allergie allergie--${level(c)}`;
                chip.textContent = `${label} (${c || 'criticité non évaluée'})`;
                allergies.appendChild(chip);
            });
        }

        const actions = document.createElement('p');
        actions.className = 'patient-card__actions';
        const correct = document.createElement('button');
        correct.type = 'button';
        correct.className = 'button button--secondary';
        correct.textContent = MESSAGES.correct;
        correct.addEventListener('click', () => {
            showIdentityFields(true);
            correct.remove();
            const first = form.querySelector('input[name="lastname"]');
            if (first) {
                first.focus();
            }
        });
        const detach = document.createElement('button');
        detach.type = 'button';
        detach.className = 'button button--secondary';
        detach.textContent = MESSAGES.detach;
        detach.addEventListener('click', resetPatient);
        actions.appendChild(correct);
        actions.appendChild(detach);

        card.append(title, name, ids, allergies, actions);
        const heading = document.getElementById('identity');
        (heading ? heading.parentElement : listbox).insertAdjacentElement('afterend', card);
        showIdentityFields(false);
    }

    // « Ce n'est pas ce patient » : vide tout le formulaire, y compris le rattachement.
    function resetPatient() {
        form.reset();
        idPatientInput.value = '';
        modeInput.value = '';
        removeCard();
        showIdentityFields(true);
        closeList();
        setStatus(searchStatus, '', null);
        form.querySelectorAll('input, textarea, select').forEach(control => {
            control.dispatchEvent(new Event('input', { bubbles: true }));
        });
        searchInput.focus();
    }

    async function loadRecord(p, mode) {
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
        setStatus(searchStatus, '', null);
        showCard(p, data);
        // Suite de la saisie : le motif d'admission (l'identité est dans la carte).
        const next = form.querySelector('[name="admissionReason"]');
        if (next) {
            next.focus();
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

    // Recherche automatique quand la frappe s'arrête (300 ms) : un IPP (6 à 9 chiffres) ou au moins
    // 2 lettres du nom ou du prénom ; le serveur distingue les deux.
    searchInput.addEventListener('input', () => scheduleSearch(searchInput, searchStatus));
    searchInput.addEventListener('keydown', onKeydown);
    searchInput.addEventListener('blur', () => {
        // Laisser le mousedown d'une option s'exécuter avant de fermer.
        setTimeout(closeList, 150);
    });
})();
