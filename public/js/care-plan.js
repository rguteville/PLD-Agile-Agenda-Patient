// AURA-20 / AURA-21 : écran B3 « Nouvelles prescriptions » (src/View/pages/careplan/form.php).
// Listes en cascade 1. → 2. → 3., fiche de paramètres (figure 9), puis « OK » ajoute la ligne à
// l'ordonnance en cours (« Prescriptions non sauvegardées »). L'envoi est fait par form.js et le
// serveur revérifie tout (CarePlanService::checkInput) : les contrôles ici évitent seulement
// d'apprendre une erreur au moment d'enregistrer.

const carePlanForm = document.getElementById('care-plan-form');

if (carePlanForm) {
    // { careTypes: { idActivityDefinition: nom }, medications: { DCI: { idMedication: forme } },
    //   planDefinitions: { idPlanDefinition: « titre (pathologie) » } }
    const lists = JSON.parse(carePlanForm.dataset.lists);
    const $ = selector => carePlanForm.querySelector(selector);

    const category = $('#category');
    const item = $('#item');
    const dosageForm = $('#dosage-form');
    const message = $('#b3-message');
    const warning = $('#b3-warning');
    const medicationParams = $('#b3-medication-params');
    const careParams = $('#b3-care-params');
    const groupParams = $('#b3-group-params');
    const hiddenLines = $('#b3-lines');
    const cart = document.getElementById('b3-cart');
    let counter = 0;

    function fill(select, options) {
        select.replaceChildren(new Option('', ''));
        Object.entries(options).forEach(([value, label]) => select.add(new Option(label, value)));
        select.disabled = Object.keys(options).length === 0;
    }

    function showMessage(text) {
        message.textContent = text;
        message.hidden = text === '';
    }

    const chosenLabel = select => select.selectedOptions[0]?.textContent.trim() ?? '';
    const categoryKind = () => category.selectedOptions[0]?.dataset.kind ?? '';

    // Maintenant, au format d'un champ datetime-local (2026-10-20T08:00), à l'heure locale.
    function localNow() {
        const date = new Date();
        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        return date.toISOString().slice(0, 16);
    }

    // 2026-10-20T08:00 → 20/10/2026 08:00
    function readableDate(value) {
        const [day, time] = value.split('T');
        const [year, month, dayOfMonth] = day.split('-');
        return `${dayOfMonth}/${month}/${year} ${time}`;
    }

    function clearParams(params) {
        params.querySelectorAll('input:not(:disabled)').forEach(input => { input.value = ''; });
        params.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
        params.querySelectorAll('[id$="hours-block"], [id$="days-block"]').forEach(block => { block.hidden = true; });
    }

    function closeParams() {
        medicationParams.hidden = true;
        careParams.hidden = true;
        groupParams.hidden = true;
    }

    function openParams(params) {
        closeParams();
        clearParams(params);
        params.querySelector('.b3-params-category').textContent = chosenLabel(category);
        params.hidden = false;
    }

    // ── Liste « 1. » : la catégorie décide du contenu de « 2. » et « 3. »
    category.addEventListener('change', () => {
        closeParams();
        fill(dosageForm, {});
        showMessage('');
        const kind = categoryKind();
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

    // ── Liste « 2. » : médicament → ses formes en « 3. » ; soin ou protocole → sa fiche
    item.addEventListener('change', () => {
        showMessage('');
        if (categoryKind() === 'MEDICATION') {
            closeParams();
            fill(dosageForm, lists.medications[item.value] ?? {});
        } else if (categoryKind() === 'CARE' && item.value !== '') {
            openParams(careParams);
            $('#care-name').value = chosenLabel(item);
            $('#care-start').value = localNow();
        } else if (categoryKind() === 'GROUP' && item.value !== '') {
            openParams(groupParams);
            $('#group-name').value = chosenLabel(item);
            $('#group-start').value = localNow();
        } else {
            closeParams();
        }
    });

    // ── Liste « 3. » : forme choisie → fiche de paramètres de la figure 9
    dosageForm.addEventListener('change', () => {
        if (dosageForm.value === '') {
            closeParams();
            return;
        }
        openParams(medicationParams);
        $('#med-inn').value = chosenLabel(item);
        $('#med-form').value = chosenLabel(dosageForm);
        $('#med-start').value = localNow();
    });

    // « Toutes les … heures » / « Tous les … jours » : affiche le nombre à saisir
    ['med', 'care'].forEach(prefix => {
        const select = $(`#${prefix}-frequency`);
        select.addEventListener('change', () => {
            $(`#${prefix}-hours-block`).hidden = select.value !== 'SCHEDULED';
            $(`#${prefix}-days-block`).hidden = select.value !== 'DAYS';
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
            return 'La date de début (« du ») est obligatoire.';
        }
        if (fields.endDate !== '' && fields.frequencyType === 'ONCE') {
            return 'Une prescription « Une fois » n\'a pas de date de fin.';
        }
        if (fields.endDate !== '' && fields.endDate < fields.startDate) {
            return 'La date de fin (« au ») doit être après la date de début (« du »).';
        }
        return '';
    }

    // « du 20/10/2026 08:00 au 27/10/2026 08:00 », ou « du …, sans date de fin »
    const period = fields => ` du ${readableDate(fields.startDate)}`
        + (fields.endDate !== '' ? ` au ${readableDate(fields.endDate)}` : ', sans date de fin');

    // ── Lecture des fiches : renvoie la ligne à ajouter, ou { error }.
    // Les clés de « fields » sont les noms envoyés au serveur (CarePlanService::LINE_FIELDS).
    function medicationLine() {
        const frequencyType = $('#med-frequency').value;
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
            startDate: $('#med-start').value,
            endDate: $('#med-end').value,
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

    function careLine() {
        const frequencyType = $('#care-frequency').value;
        const fields = {
            kind: 'CARE',
            idActivityDefinition: item.value,
            ...frequencyFields('care', frequencyType),
            startDate: $('#care-start').value,
            endDate: $('#care-end').value,
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
        if (line.fields.kind === 'MEDICATION') {
            block.dataset.medication = line.fields.idMedication;
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
        row.insertCell().textContent = line.label;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'b3-remove';
        remove.textContent = '✕';
        remove.title = 'Retirer';
        remove.addEventListener('click', () => {
            row.remove();
            block.remove();
            showNotice('');
        });
        row.insertCell().appendChild(remove);
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
