// Recherche d'un patient existant à l'admission : un seul champ en tête (« IPP, nom ou prénom »).
// Recherche par IPP (AURA-15, prioritaire) ou par nom / prénom, par un seul chemin de code : le
// serveur (/patients/recherche?q=…) reconnaît un IPP (6 à 9 chiffres) et renvoie alors 0 ou 1 résultat.
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
//
// Chargé par l'admission (GET /, PatientController::SCRIPTS_ADMISSION). Attend : #admission-form, ses
// champs patientSearch, idPatient et searchMode (cachés), #patient-search-status, #patient-suggestions,
// #identity (titre « Identité »). Émet aura:allergies-filled (allergy-picker.js).
// Appels réseau, en lecture seule (GET, Accept: application/json, sans jeton CSRF) :
// - GET /patients/recherche?q=… : {ok, mode, results, total, truncated, homonyms} ; 422 si moins de
//   2 caractères ({ok: false, errors}) ;
// - GET /patients/fiche?id=… : {ok, patient, allergies, allergyLabels, allergyCriticalities} ;
//   400 ou 404 ({ok: false, message}).

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
        none: 'Aucun patient trouvé : poursuivez la saisie pour créer un nouveau dossier.',
        ippUnknown: 'IPP inconnu, saisie manuelle.',
        tooShort: 'Saisissez au moins 2 caractères.',
        failure: 'La recherche a échoué. Veuillez réessayer.',
        refine: 'Plus de 20 résultats : affinez votre recherche.',
        homonyms: 'Plusieurs patients portent ce nom : vérifiez la date de naissance et l\'IPP avant de sélectionner.',
        results: n => `${n} résultat${n > 1 ? 's' : ''}`,
        cardTitle: 'Patient existant',
        detach: 'Ce n\'est pas ce patient',
        noAllergy: 'Aucune allergie connue',
        loadFailure: 'Impossible de charger la fiche du patient. Réessayez.',
    };

    let timer = null;
    let results = [];
    let activeIndex = -1;
    let activeInput = searchInput;
    let requestId = 0;

    // Message sous le champ de recherche (zone aria-live) ; kind : searching, found, unknown, error.
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

    // Détails d'une suggestion : naissance et âge, sexe, IPP, nom de naissance, dernier séjour.
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

    // Vide et ferme la liste de suggestions (aria-expanded="false").
    function closeList() {
        listbox.hidden = true;
        listbox.textContent = '';
        results = [];
        activeIndex = -1;
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
    }

    // Option active au clavier (aria-activedescendant) ; index hors liste : aucune.
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

    // Affiche les suggestions, avec l'avertissement d'homonymes et l'invitation à affiner (plus de 20).
    function renderResults(data, statusTarget, isIpp) {
        closeList();
        results = data.results || [];
        if (results.length === 0) {
            setStatus(statusTarget, isIpp ? MESSAGES.ippUnknown : MESSAGES.none, 'unknown');
            return;
        }
        setStatus(statusTarget, MESSAGES.results(results.length), 'found');
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
            details.textContent = ', ' + describe(p);
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

    // Interroge GET /patients/recherche ; une réponse arrivée après une saisie plus récente est ignorée.
    async function search(query, input, statusTarget) {
        const q = query.trim();
        activeInput = input;
        if (q.length < MIN_LENGTH) {
            closeList();
            setStatus(statusTarget, q.length === 0 ? '' : MESSAGES.tooShort, 'unknown');
            return;
        }
        const isIpp = IPP_PATTERN.test(q);
        setStatus(statusTarget, MESSAGES.searching, 'searching');
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
                setStatus(statusTarget, MESSAGES.failure, 'error');
            }
            return;
        }
        if (current !== requestId) {
            return; // une saisie plus récente a pris le relais
        }
        if (!data.ok) {
            closeList();
            setStatus(statusTarget, (data.errors && Object.values(data.errors)[0]) || MESSAGES.failure, 'error');
            return;
        }
        renderResults(data, statusTarget, isIpp);
    }

    // Relance la recherche DEBOUNCE_MS après la dernière frappe.
    function scheduleSearch(input, statusTarget) {
        clearTimeout(timer);
        timer = setTimeout(() => search(input.value, input, statusTarget), DEBOUNCE_MS);
    }

    // ---- Sélection : chargement direct de la fiche ----
    // Pas de confirmation intermédiaire : la carte affichée après la sélection montre l'identité
    // complète, et « Ce n'est pas ce patient » annule le rattachement.

    // Sélection d'une suggestion (clic ou Entrée) : charge sa fiche ; mode « ipp » ou « name ».
    function select(index) {
        const p = results[index];
        if (!p) {
            return;
        }
        const mode = IPP_PATTERN.test(activeInput.value.trim()) ? 'ipp' : 'name';
        closeList();
        loadRecord(p, mode);
    }

    // Remplit un champ visible du formulaire et prévient les autres scripts (événement input).
    function setFieldValue(name, value) {
        const control = form.querySelector(`[name="${CSS.escape(name)}"]`);
        if (!control || control.type === 'hidden') {
            return;
        }
        control.value = value || '';
        control.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Pré-remplit le formulaire avec la fiche : identité, détails, allergies cochées et leur criticité.
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

    // ---- Carte du patient existant ----
    // L'identité d'un patient retrouvé s'affiche en lecture seule, comme le bandeau : elle ne se corrige
    // pas depuis l'admission mais dans le dossier, où la modification est tracée. « Ce n'est pas ce
    // patient » détache.

    // Le groupe sanguin d'un patient connu est sur sa carte, en lecture seule.
    const IDENTITY_FIELDS = ['lastname', 'firstname', 'birthDate', 'gender', 'ipp', 'bloodType'];

    // Lignes .form-row des champs d'identité, masquées tant que la carte est affichée.
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

    // Niveau d'affichage d'une criticité (même règle que AllergyIntolerance::criticalityLevel()).
    function level(criticality) {
        if (criticality === 'Élevée') {
            return 'high';
        }
        return criticality === 'Faible' ? 'low' : 'unknown';
    }

    // Retire la carte du patient sélectionné.
    function removeCard() {
        form.querySelectorAll('.patient-card').forEach(el => el.remove());
    }

    // Carte en lecture seule du patient sélectionné : identité, groupe sanguin, IPP, dernier séjour,
    // allergies, alerte de décès, bouton « Ce n'est pas ce patient ».
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
            ? `, né(e) le ${p.birthDate}${p.age !== null ? ` (${p.age} ans)` : ''}`
            : ', date de naissance inconnue';
        name.append(birth);
        // Groupe sanguin du patient connu, comme dans le bandeau.
        const bloodType = (patientRecord.patient || {}).bloodType;
        if (bloodType) {
            name.append(`, groupe ${bloodType}`);
        }
        // Identitovigilance : un patient déclaré décédé ne devrait pas être réadmis ; on le signale sans
        // bloquer (erreur d'identité possible), le serveur et le dossier gardent la trace du décès.
        const deceased = (patientRecord.patient || {}).deceased === '1';

        const ids = document.createElement('p');
        ids.className = 'patient-card__ids';
        ids.textContent = `IPP ${p.ipp || 'sans IPP'}, `
            + (p.lastEpisodeOfCare ? `dernier séjour le ${p.lastEpisodeOfCare}` : 'aucun séjour');

        const allergies = document.createElement('div');
        allergies.className = 'patient-card__allergies';
        const labels = patientRecord.allergyLabels || [];
        const ids2 = patientRecord.allergies || [];
        const crit = patientRecord.allergyCriticalities || {};
        if (labels.length === 0) {
            const none = document.createElement('span');
            none.className = 'allergy-tag allergy-tag--none';
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
                chip.className = `allergy-tag allergy-tag--${level(c)}`;
                chip.textContent = `${label} (${c || 'criticité non évaluée'})`;
                allergies.appendChild(chip);
            });
        }

        const actions = document.createElement('p');
        actions.className = 'patient-card__actions';
        const detach = document.createElement('button');
        detach.type = 'button';
        detach.className = 'button button--secondary';
        detach.textContent = MESSAGES.detach;
        detach.addEventListener('click', resetPatient);
        actions.appendChild(detach);

        const parts = [title, name, ids, allergies, actions];
        if (deceased) {
            const warning = document.createElement('p');
            warning.className = 'form-error';
            warning.setAttribute('role', 'alert');
            warning.textContent = 'Patient déclaré décédé dans son dossier : vérifiez l\'identité avant d\'admettre.';
            parts.splice(2, 0, warning);
        }
        card.append(...parts);
        const heading = document.getElementById('identity');
        (heading ? heading.parentElement : listbox).insertAdjacentElement('afterend', card);
        showIdentityFields(false);
        // Groupe sanguin inconnu dans le dossier : il peut être complété ici (le serveur refuse de remplacer
        // un groupe déjà connu).
        if (!bloodType) {
            const bloodRow = form.querySelector('[name="bloodType"]')?.closest('.form-row');
            if (bloodRow) {
                bloodRow.hidden = false;
            }
        }
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

    // Charge la fiche (GET /patients/fiche), pré-remplit le formulaire, rattache le patient (idPatient)
    // et affiche sa carte.
    async function loadRecord(p, mode) {
        let data;
        try {
            const response = await fetch(`${RECORD_URL}?id=${encodeURIComponent(p.idPatient)}`, {
                headers: { 'Accept': 'application/json' },
            });
            data = await response.json();
        } catch (e) {
            setStatus(searchStatus, MESSAGES.loadFailure, 'error');
            return;
        }
        if (!data.ok) {
            setStatus(searchStatus, data.message || MESSAGES.loadFailure, 'error');
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

    // Flèches haut et bas : option active ; Entrée : sélection ; Échap : fermeture de la liste.
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
