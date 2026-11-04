// AURA-20 / AURA-21 : écran B3 « Nouvelles prescriptions » (src/View/pages/careplan/form.php).
// Listes en cascade Catégorie → Médicament / Soin / Protocole → Forme galénique, fiche de paramètres
// (figure 9), puis « OK » ajoute la ligne à l'ordonnance en cours (« Prescriptions non sauvegardées »).
// L'envoi est fait par form.js et le serveur revérifie tout (CarePlanService::checkInput, prescribe) :
// les contrôles ici évitent seulement d'apprendre une erreur au moment d'enregistrer.
// RG2 : alerte allergie dès le choix d'un médicament qui contient un allergène du patient, et case
// « Je prescris malgré l'allergie » tant que l'ordonnance en contient un (le serveur refuse
// l'ordonnance sans elle).
//
// Chargé par CarePlanController (GET /care-plans/new?idEpisodeOfCare=…, et l'écran réaffiché après un
// refus). Attend : #care-plan-form (data-lists, JSON), #category (options data-kind), #item,
// #dosage-form, les fiches #b3-medication-params, #b3-care-params, #b3-group-params, les zones
// #b3-message, #b3-allergy, #b3-notice, #b3-warning, #b3-lines (champs cachés), #b3-cart,
// #b3-allergy-confirm, #allergyOverride, #mode et #idPrescriber (options data-doctor).
// Appel réseau : GET /care-plans/protocol-lines?idPlanDefinition=…&startDate=AAAA-MM-JJTHH:MM, en JSON :
// {ok: true, lines} ou 422 {ok: false, errors}. Lecture seule, sans jeton CSRF ; l'enregistrement de
// l'ordonnance passe par form.js (champ csrf).

const carePlanForm = document.getElementById('care-plan-form');

if (carePlanForm) {
    // { careTypes: { idActivityDefinition: nom }, medications: { DCI: { idMedication: forme } },
    //   activeMedications: { idMedication: true }, planDefinitions: { idPlanDefinition: « titre (pathologie) » },
    //   medicationAllergens: { idMedication: allergène du patient } }
    const lists = JSON.parse(carePlanForm.dataset.lists);
    const allergens = lists.medicationAllergens ?? {};
    const $ = selector => carePlanForm.querySelector(selector);

    const category = $('#category');
    const item = $('#item');
    const itemLabel = $('#item-label');
    const dosageForm = $('#dosage-form');
    const message = $('#b3-message');
    const allergyAlert = $('#b3-allergy');
    const allergyConfirm = document.getElementById('b3-allergy-confirm');
    const allergyList = document.getElementById('b3-allergy-list');
    const allergyOverride = document.getElementById('allergyOverride');
    const warning = $('#b3-warning');
    const medicationParams = $('#b3-medication-params');
    const careParams = $('#b3-care-params');
    const groupParams = $('#b3-group-params');
    const hiddenLines = $('#b3-lines');
    const cart = document.getElementById('b3-cart');
    let counter = 0;

    // Libellé de la deuxième liste selon la catégorie choisie.
    const ITEM_LABELS = { MEDICATION: 'Médicament (DCI)', CARE: 'Soin', GROUP: 'Protocole' };

    // Remplace les options d'une liste ; liste désactivée si elle est vide.
    function fill(select, options) {
        const empty = Object.keys(options).length === 0;
        select.replaceChildren(new Option(empty ? '' : 'Choisir', ''));
        Object.entries(options).forEach(([value, label]) => select.add(new Option(label, value)));
        select.disabled = Object.keys(options).length === 0;
    }

    // Message d'erreur de saisie (#b3-message), masqué si le texte est vide.
    function showMessage(text) {
        message.textContent = text;
        message.hidden = text === '';
    }

    // Alerte allergie (#b3-allergy), masquée si le texte est vide.
    function showAllergy(text) {
        allergyAlert.textContent = text;
        allergyAlert.hidden = text === '';
    }

    const chosenLabel = select => select.selectedOptions[0]?.textContent.trim() ?? '';
    const categoryKind = () => category.selectedOptions[0]?.dataset.kind ?? '';

    // Maintenant, au format d'un champ datetime-local (2026-10-20T08:00), à l'heure locale.
    function localNow() {
        const date = new Date();
        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        return date.toISOString().slice(0, 16);
    }

    // ── Dates des fiches médicament et soin : jour et heure dans deux champs (#…-date, #…-time), car
    // un champ datetime-local rempli sans l'heure vaut « vide » pour le navigateur (date perdue sans
    // prévenir).
    function setDateTime(id, value) {
        const [day, time] = value.split('T');
        $(`#${id}-date`).value = day ?? '';
        $(`#${id}-time`).value = time ?? '';
    }

    // Lit « AAAA-MM-JJTHH:MM ». Sans heure : endOfDay (« 23:59 ») pour une fin, erreur pour un début.
    // Renvoie { value } (value '' si les deux champs sont vides) ou { error }.
    function readDateTime(id, what, endOfDay = null) {
        const day = $(`#${id}-date`);
        const time = $(`#${id}-time`);
        if (day.validity.badInput || time.validity.badInput) {
            return { error: `${what} : date ou heure incomplète.` };
        }
        if (day.value === '' && time.value === '') {
            return { value: '' };
        }
        if (day.value === '') {
            return { error: `${what} : indiquez aussi le jour.` };
        }
        if (time.value === '') {
            if (endOfDay === null) {
                return { error: `${what} : indiquez aussi l'heure.` };
            }
            time.value = endOfDay;
        }
        return { value: `${day.value}T${time.value}` };
    }

    // « Une fois » : une seule date, « Le », pas de date de fin (ligne masquée et vidée). Sinon « Du » et « Au ».
    function applyOnce(prefix) {
        const once = $(`#${prefix}-frequency`).value === 'ONCE';
        $(`#${prefix}-start-label`).textContent = once ? 'Le' : 'Du';
        carePlanForm.querySelectorAll(`[data-row="${prefix}-end"]`).forEach(element => { element.hidden = once; });
        if (once) {
            setDateTime(`${prefix}-end`, '');
        }
    }

    // 2026-10-20T08:00 → 20/10/2026 08:00
    function readableDate(value) {
        const [day, time] = value.split('T');
        const [year, month, dayOfMonth] = day.split('-');
        return `${dayOfMonth}/${month}/${year} ${time}`;
    }

    // Vide une fiche de paramètres et masque ses blocs « heures » et « jours ».
    function clearParams(params) {
        params.querySelectorAll('input:not(:disabled)').forEach(input => { input.value = ''; });
        params.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
        params.querySelectorAll('[id$="hours-block"], [id$="days-block"]').forEach(block => { block.hidden = true; });
    }

    // Masque les trois fiches (médicament, soin, protocole).
    function closeParams() {
        medicationParams.hidden = true;
        careParams.hidden = true;
        groupParams.hidden = true;
    }

    // Ouvre une fiche vide, titrée par la catégorie choisie.
    function openParams(params) {
        closeParams();
        clearParams(params);
        params.querySelector('.b3-params-category').textContent = chosenLabel(category);
        params.hidden = false;
    }

    // ── Liste « Catégorie » : décide du contenu (et du libellé) de la deuxième liste et de « Forme galénique »
    category.addEventListener('change', () => {
        closeParams();
        fill(dosageForm, {});
        showMessage('');
        showAllergy('');
        const kind = categoryKind();
        itemLabel.textContent = ITEM_LABELS[kind] ?? 'Prescription';
        if (kind === 'MEDICATION') {
            const inns = {};
            Object.keys(lists.medications).forEach(inn => { inns[inn] = inn; });
            fill(item, inns);
        } else if (kind === 'CARE') {
            fill(item, lists.careTypes);
        } else if (kind === 'GROUP') {
            fill(item, lists.planDefinitions);
            if (Object.keys(lists.planDefinitions).length === 0) {
                showMessage('Aucun protocole n\'est encore défini.');
            }
        } else {
            fill(item, {});
        }
    });

    // ── Deuxième liste : médicament (DCI) → ses formes ; soin ou protocole → sa fiche
    item.addEventListener('change', () => {
        showMessage('');
        showAllergy('');
        if (categoryKind() === 'MEDICATION') {
            closeParams();
            const forms = lists.medications[item.value] ?? {};
            fill(dosageForm, forms);
            // Alerte dès le choix du médicament, si une de ses formes contient un allergène du patient.
            const found = [...new Set(Object.keys(forms).filter(id => allergens[id]).map(id => allergens[id]))];
            if (found.length > 0) {
                showAllergy(`Allergie : le patient est allergique à ${found.join(', ')}, contenu dans `
                    + `${chosenLabel(item)}. Prescription possible seulement avec confirmation explicite.`);
            }
        } else if (categoryKind() === 'CARE' && item.value !== '') {
            openParams(careParams);
            $('#care-name').value = chosenLabel(item);
            setDateTime('care-start', localNow());
            applyOnce('care');
        } else if (categoryKind() === 'GROUP' && item.value !== '') {
            openParams(groupParams);
            $('#group-name').value = chosenLabel(item);
            $('#group-start').value = localNow();
        } else {
            closeParams();
        }
    });

    // ── Liste « Forme galénique » : forme choisie → fiche de paramètres de la figure 9
    dosageForm.addEventListener('change', () => {
        const allergen = allergens[dosageForm.value];
        showAllergy(allergen
            ? `Allergie : le patient est allergique à ${allergen}, contenu dans ${chosenLabel(item)} `
                + `(${chosenLabel(dosageForm)}). Prescription possible seulement avec confirmation explicite.`
            : '');
        if (dosageForm.value === '') {
            closeParams();
            return;
        }
        openParams(medicationParams);
        $('#med-inn').value = chosenLabel(item);
        $('#med-form').value = chosenLabel(dosageForm);
        setDateTime('med-start', localNow());
        applyOnce('med');
    });

    // « Toutes les … heures » / « Tous les … jours » : affiche le nombre à saisir
    ['med', 'care'].forEach(prefix => {
        const select = $(`#${prefix}-frequency`);
        select.addEventListener('change', () => {
            $(`#${prefix}-hours-block`).hidden = select.value !== 'SCHEDULED';
            $(`#${prefix}-days-block`).hidden = select.value !== 'DAYS';
            applyOnce(prefix);
        });
    });

    // Nombre entier, sans signe ni virgule : « 6 », « 48 ».
    const validNumber = value => /^[1-9][0-9]*$/.test(value);

    // Champs de fréquence d'une fiche : heures ou jours selon le type choisi.
    const frequencyFields = (prefix, frequencyType) => ({
        frequencyType,
        frequencyHours: frequencyType === 'SCHEDULED' ? $(`#${prefix}-hours`).value.trim() : '',
        frequencyDays: frequencyType === 'DAYS' ? $(`#${prefix}-days`).value.trim() : '',
    });

    // Rythme affiché dans le panier : « /6h », « tous les jours », « tous les 2 j », « une fois ».
    function rhythm(fields) {
        if (fields.frequencyType === 'SCHEDULED') {
            return `/${fields.frequencyHours}h`;
        }
        if (fields.frequencyType === 'DAYS') {
            return fields.frequencyDays === '1' ? 'tous les jours' : `tous les ${fields.frequencyDays} j`;
        }
        return 'une fois';
    }

    // RG3 : contrôles communs aux deux fiches ; renvoie le message d'erreur, ou ''.
    function scheduleError(fields) {
        if (fields.frequencyType === 'SCHEDULED' && !validNumber(fields.frequencyHours)) {
            return 'Indiquez toutes les combien d\'heures.';
        }
        if (fields.frequencyType === 'DAYS' && !validNumber(fields.frequencyDays)) {
            return 'Indiquez tous les combien de jours.';
        }
        if (fields.startDate === '') {
            return fields.frequencyType === 'ONCE'
                ? 'La date (« le ») est obligatoire.'
                : 'La date de début (« du ») est obligatoire.';
        }
        if (fields.endDate !== '' && fields.frequencyType === 'ONCE') {
            return 'Une prescription « Une fois » n\'a pas de date de fin.';
        }
        if (fields.endDate !== '' && fields.endDate < fields.startDate) {
            return 'La date de fin (« au ») doit être après la date de début (« du »).';
        }
        return '';
    }

    // « du 20/10/2026 08:00 au 27/10/2026 08:00 », « du …, sans date de fin », ou « le … » (une fois)
    const period = fields => (fields.frequencyType === 'ONCE'
        ? ` le ${readableDate(fields.startDate)}`
        : ` du ${readableDate(fields.startDate)}`
            + (fields.endDate !== '' ? ` au ${readableDate(fields.endDate)}` : ', sans date de fin'));

    // Dates d'une fiche : { startDate, endDate } ou { error }.
    function readPeriod(prefix) {
        const once = $(`#${prefix}-frequency`).value === 'ONCE';
        const start = readDateTime(`${prefix}-start`, once ? 'Date' : 'Date de début');
        if (start.error) {
            return start;
        }
        const end = once ? { value: '' } : readDateTime(`${prefix}-end`, 'Date de fin', '23:59');
        if (end.error) {
            return end;
        }
        return { startDate: start.value, endDate: end.value };
    }

    // ── Lecture des fiches : renvoie la ligne à ajouter, ou { error }.
    // Les clés de « fields » sont les noms envoyés au serveur (CarePlanService::LINE_FIELDS).
    function medicationLine() {
        const frequencyType = $('#med-frequency').value;
        const dates = readPeriod('med');
        if (dates.error) {
            return { error: dates.error };
        }
        const fields = {
            kind: 'MEDICATION',
            idMedication: dosageForm.value,
            route: $('#med-route').value,
            dose: $('#med-dose').value.trim(),
            important: $('#med-important').value.trim(),
            nurseInstructions: $('#med-nurseInstructions').value.trim(),
            maxDose: $('#med-maxDose').value.trim(),
            dilutionVolume: $('#med-dilutionVolume').value.trim(),
            solvent: $('#med-solvent').value.trim(),
            administrationDuration: $('#med-administrationDuration').value.trim(),
            antibioticReason: $('#med-antibioticReason').value.trim(),
            germ: $('#med-germ').value.trim(),
            renalAdjustment: $('#med-renalAdjustment').value.trim(),
            adjustmentCriterion: $('#med-adjustmentCriterion').value.trim(),
            offGhsReason: $('#med-offGhsReason').value.trim(),
            ...frequencyFields('med', frequencyType),
            startDate: dates.startDate,
            endDate: dates.endDate,
        };

        if (frequencyType === '') {
            return { error: 'La fréquence est obligatoire.' };
        }
        if (fields.route === '') {
            return { error: 'La voie d\'administration est obligatoire.' };
        }
        if (fields.dose === '') {
            return { error: 'La dose est obligatoire.' };
        }
        const error = scheduleError(fields);
        if (error !== '') {
            return { error };
        }

        return medicationCartLine(chosenLabel(category), fields, chosenLabel(item), chosenLabel(dosageForm));
    }

    // Ligne de panier d'un médicament : « [IV] Paracétamol (Solution injectable) 1 g (/6h) … ».
    function medicationCartLine(categoryLabel, fields, inn, form) {
        let label = `[${fields.route}] ${inn} (${form}) ${fields.dose} (${rhythm(fields)})`;
        if (fields.nurseInstructions !== '') {
            label += ` -> ${fields.nurseInstructions}`;
        }
        label += period(fields);

        // Clé = la ligne complète : seule une ligne strictement identique est un doublon.
        return { category: categoryLabel, label, fields, key: Object.values(fields).join('|') };
    }

    // Ligne de panier d'un soin : « Prise des constantes (/4h) du … -> à jeun ».
    function careCartLine(categoryLabel, fields, name) {
        let label = `${name} (${rhythm(fields)})${period(fields)}`;
        if (fields.timeConstraint !== '') {
            label += ` -> ${fields.timeConstraint}`;
        }

        return { category: categoryLabel, label, fields, key: Object.values(fields).join('|') };
    }

    // Lecture de la fiche d'un soin : renvoie la ligne à ajouter, ou { error }.
    function careLine() {
        const frequencyType = $('#care-frequency').value;
        const dates = readPeriod('care');
        if (dates.error) {
            return { error: dates.error };
        }
        const fields = {
            kind: 'CARE',
            idActivityDefinition: item.value,
            ...frequencyFields('care', frequencyType),
            startDate: dates.startDate,
            endDate: dates.endDate,
            timeConstraint: $('#care-timeConstraint').value.trim(),
        };

        const error = scheduleError(fields);
        if (error !== '') {
            return { error };
        }

        return careCartLine(chosenLabel(category), fields, chosenLabel(item));
    }

    const inCart = line => [...hiddenLines.children].some(block => block.dataset.key === line.key);
    const notice = document.getElementById('b3-notice');

    // Avertissement non bloquant (#b3-notice : doublon possible), masqué si le texte est vide.
    function showNotice(text) {
        notice.textContent = text;
        notice.hidden = text === '';
    }

    // Même médicament déjà prescrit dans le séjour ou déjà dans l'ordonnance : on prévient, on n'interdit
    // pas (nouvelle dose, autre voie, relais…). Le prescripteur reste juge.
    function medicationNotice(line) {
        if (line.fields.kind !== 'MEDICATION') {
            return '';
        }
        const id = line.fields.idMedication;
        const inOrder = [...hiddenLines.children].some(block => block.dataset.medication === id);
        if (lists.activeMedications && lists.activeMedications[id]) {
            return 'Attention : ce médicament est déjà prescrit et en cours pour ce séjour. Nouvelle prescription '
                + 'ajoutée : vérifiez qu\'il ne s\'agit pas d\'un doublon.';
        }
        if (inOrder) {
            return 'Attention : ce médicament figure déjà dans l\'ordonnance. Nouvelle ligne ajoutée : vérifiez '
                + 'qu\'il ne s\'agit pas d\'un doublon.';
        }
        return '';
    }

    // ── « OK » : ajoute la ligne à l'ordonnance en cours de saisie
    function add(line, params) {
        if (line.error) {
            showMessage(line.error);
            return;
        }
        if (inCart(line)) {
            showMessage('Cette ligne, strictement identique, est déjà dans l\'ordonnance.');
            return;
        }
        showNotice(medicationNotice(line));
        addToCart(line);

        showMessage('');
        showAllergy('');
        params.hidden = true;
        item.value = '';
        fill(dosageForm, {});
    }

    // Champs cachés lines[n][…] (envoyés au serveur) et ligne visible du panier.
    function addToCart(line) {

        // Champs cachés lines[n][…] : c'est eux que form.js envoie au serveur.
        const n = counter++;
        const block = document.createElement('div');
        block.dataset.key = line.key;
        const allergen = line.fields.kind === 'MEDICATION' ? allergens[line.fields.idMedication] : undefined;
        if (line.fields.kind === 'MEDICATION') {
            block.dataset.medication = line.fields.idMedication;
        }
        if (allergen) {
            block.dataset.allergen = allergen;
        }
        Object.entries(line.fields).forEach(([field, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `lines[${n}][${field}]`;
            input.value = value;
            block.appendChild(input);
        });
        hiddenLines.appendChild(block);

        // Ligne visible du panier, avec son bouton « Retirer »
        const row = cart.insertRow();
        row.insertCell().textContent = line.category;
        const labelCell = row.insertCell();
        if (allergen) {
            row.classList.add('b3-cart-allergy');
            const flag = document.createElement('strong');
            flag.className = 'allergy-flag';
            flag.textContent = `Allergie (${allergen}) : `;
            labelCell.appendChild(flag);
        }
        labelCell.appendChild(document.createTextNode(line.label));
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'b3-remove';
        remove.textContent = '✕';
        remove.title = 'Retirer';
        remove.addEventListener('click', () => {
            row.remove();
            block.remove();
            showNotice('');
            refreshAllergyConfirm();
        });
        row.insertCell().appendChild(remove);
        refreshAllergyConfirm();
    }

    // Case « Je prescris malgré l'allergie » : visible tant qu'une ligne de l'ordonnance contient un
    // allergène du patient. Sa valeur est la liste des médicaments allergènes (CarePlanService::
    // allergySignature()) ; elle se décoche dès que cette liste change : une confirmation ne vaut que pour
    // ce qu'on voyait en la cochant, le serveur le vérifie.
    function refreshAllergyConfirm() {
        const blocks = [...hiddenLines.children].filter(block => block.dataset.allergen);
        const signature = [...new Set(blocks.map(block => block.dataset.medication))].sort().join(',');
        if (signature !== allergyOverride.value) {
            allergyOverride.checked = false;
            allergyOverride.value = signature;
        }
        allergyList.replaceChildren(...blocks.map(block => {
            const li = document.createElement('li');
            const [inn, form] = medicationNames[block.dataset.medication] ?? [block.dataset.medication, ''];
            li.textContent = `${inn}${form ? ` (${form})` : ''} : allergie à ${block.dataset.allergen}`;
            return li;
        }));
        allergyConfirm.hidden = blocks.length === 0;
        if (blocks.length === 0) {
            allergyOverride.checked = false;
        }
    }

    // idMedication → [DCI, forme], pour le libellé des lignes d'un protocole.
    const medicationNames = {};
    Object.entries(lists.medications).forEach(([inn, forms]) => {
        Object.entries(forms).forEach(([idMedication, form]) => { medicationNames[idMedication] = [inn, form]; });
    });

    // ── « OK » d'un protocole : le serveur calcule les lignes (PlanDefinitionService), toutes
    // ajoutées au panier ; celles déjà présentes ne sont pas dupliquées.
    async function addGroup() {
        const startDate = $('#group-start').value;
        if ($('#group-start').validity.badInput) {
            showMessage('Début du protocole : date ou heure incomplète.');
            return;
        }
        if (startDate === '') {
            showMessage('La date de début est obligatoire.');
            return;
        }
        const query = new URLSearchParams({ idPlanDefinition: item.value, startDate });
        let body;
        try {
            const response = await fetch(`/care-plans/protocol-lines?${query}`, {
                headers: { 'Accept': 'application/json' },
            });
            body = await response.json();
        } catch (e) {
            showMessage('Le protocole n\'a pas pu être chargé. Réessayez.');
            return;
        }
        if (!body.ok) {
            showMessage(Object.values(body.errors ?? {}).join(' ') || 'Ce protocole ne peut pas être appliqué.');
            return;
        }

        const protocol = chosenLabel(item);
        let skipped = 0;
        const notices = new Set();
        body.lines.forEach(fields => {
            const line = fields.kind === 'MEDICATION'
                ? medicationCartLine(protocol, fields, ...(medicationNames[fields.idMedication] ?? [fields.idMedication, '']))
                : careCartLine(protocol, fields, lists.careTypes[fields.idActivityDefinition] ?? fields.idActivityDefinition);
            if (inCart(line)) {
                skipped++;
            } else {
                // Même avertissement qu'une saisie à la main : médicament déjà en cours ou déjà dans l'ordonnance.
                if (medicationNotice(line) !== '') {
                    notices.add((medicationNames[fields.idMedication] ?? [fields.idMedication])[0]);
                }
                addToCart(line);
            }
        });
        showNotice(notices.size > 0
            ? `Attention : le protocole contient ${[...notices].join(', ')}, déjà prescrit(s) pour ce séjour ou déjà `
                + 'dans l\'ordonnance. Vérifiez qu\'il ne s\'agit pas d\'un doublon.'
            : '');

        closeParams();
        item.value = '';
        showMessage(skipped > 0
            ? `${skipped} prescription(s) du protocole déjà dans l'ordonnance : non ajoutée(s) une seconde fois.`
            : '');
    }

    $('#med-ok').addEventListener('click', () => add(medicationLine(), medicationParams));
    $('#care-ok').addEventListener('click', () => add(careLine(), careParams));
    $('#group-ok').addEventListener('click', addGroup);
    carePlanForm.querySelectorAll('.b3-cancel').forEach(button => button.addEventListener('click', () => {
        showNotice('');
        showAllergy('');
        closeParams();
        item.value = '';
        fill(dosageForm, {});
        showMessage('');
    }));

    // RG5 : prescripteur non médecin → l'ordonnance devra être validée par un médecin.
    const prescriber = $('#idPrescriber');
    const mode = $('#mode');
    const updateWarning = () => {
        warning.hidden = prescriber.value === '' || prescriber.selectedOptions[0]?.dataset.doctor === '1';
    };
    prescriber.addEventListener('change', updateWarning);

    // RG5 : une prescription médicale ne peut venir que d'un médecin, la liste n'en propose pas d'autre.
    function filterPrescribers() {
        const doctorsOnly = mode.value === 'MEDICAL';
        Array.from(prescriber.options).forEach(option => {
            const excluded = doctorsOnly && option.value !== '' && option.dataset.doctor !== '1';
            option.hidden = excluded;
            option.disabled = excluded;
        });
        if (prescriber.selectedOptions[0]?.disabled) {
            prescriber.value = '';
        }
        updateWarning();
    }
    mode.addEventListener('change', filterPrescribers);
    filterPrescribers();
}
