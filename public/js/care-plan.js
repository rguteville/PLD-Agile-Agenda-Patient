// AURA-20 / AURA-21 : écran B3 « Nouvelles prescriptions » (src/View/pages/careplan/form.php).
// Listes en cascade 1. → 2. → 3., fiche de paramètres (figure 9), puis « OK » ajoute la ligne à
// l'ordonnance en cours (« Prescriptions non sauvegardées »). L'envoi est fait par form.js et le
// serveur revérifie tout (CarePlanService::checkInput) : les contrôles ici évitent seulement
// d'apprendre une erreur au moment d'enregistrer.

const carePlanForm = document.getElementById('care-plan-form');

if (carePlanForm) {
    // { careTypes: { idActivityDefinition: nom }, medications: { DCI: { idMedication: forme } } }
    const lists = JSON.parse(carePlanForm.dataset.lists);
    const $ = selector => carePlanForm.querySelector(selector);

    const category = $('#category');
    const item = $('#item');
    const dosageForm = $('#dosage-form');
    const message = $('#b3-message');
    const warning = $('#b3-warning');
    const medicationParams = $('#b3-medication-params');
    const careParams = $('#b3-care-params');
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
        } else {
            fill(item, {});
            if (kind === 'GROUP') {
                showMessage('Aucun groupe de prescriptions (protocole) n\'est encore défini.');
            }
        }
    });

    // ── Liste « 2. » : médicament → ses formes en « 3. » ; soin → fiche du soin
    item.addEventListener('change', () => {
        showMessage('');
        if (categoryKind() === 'MEDICATION') {
            closeParams();
            fill(dosageForm, lists.medications[item.value] ?? {});
        } else if (categoryKind() === 'CARE' && item.value !== '') {
            openParams(careParams);
            $('#care-name').value = chosenLabel(item);
            $('#care-start').value = localNow();
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
            return 'La date de début est obligatoire.';
        }
        if (fields.endDate !== '' && fields.frequencyType === 'ONCE') {
            return 'Une prescription « Une fois » n\'a pas de date d\'arrêt.';
        }
        if (fields.endDate !== '' && fields.endDate < fields.startDate) {
            return 'L\'arrêt doit être après le début.';
        }
        return '';
    }

    // « du 20/10/2026 08:00 au 27/10/2026 08:00 »
    const period = fields => ` du ${readableDate(fields.startDate)}`
        + (fields.endDate !== '' ? ` au ${readableDate(fields.endDate)}` : '');

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

        let label = `[${fields.route}] ${chosenLabel(item)} (${chosenLabel(dosageForm)}) ${fields.dose} (${rhythm(fields)})`;
        if (fields.nurseInstructions !== '') {
            label += ` -> ${fields.nurseInstructions}`;
        }
        label += period(fields);

        return { category: chosenLabel(category), label, fields, key: `MEDICATION|${fields.idMedication}` };
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

        let label = `${chosenLabel(item)} (${rhythm(fields)})${period(fields)}`;
        if (fields.timeConstraint !== '') {
            label += ` -> ${fields.timeConstraint}`;
        }

        return { category: chosenLabel(category), label, fields, key: Object.values(fields).join('|') };
    }

    // ── « OK » : ajoute la ligne à l'ordonnance en cours de saisie
    function add(line, params) {
        if (line.error) {
            showMessage(line.error);
            return;
        }
        if ([...hiddenLines.children].some(block => block.dataset.key === line.key)) {
            showMessage('Cette prescription est déjà dans l\'ordonnance.');
            return;
        }

        // Champs cachés lines[n][…] : c'est eux que form.js envoie au serveur.
        const n = counter++;
        const block = document.createElement('div');
        block.dataset.key = line.key;
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
        });
        row.insertCell().appendChild(remove);

        showMessage('');
        params.hidden = true;
        item.value = '';
        fill(dosageForm, {});
    }

    $('#med-ok').addEventListener('click', () => add(medicationLine(), medicationParams));
    $('#care-ok').addEventListener('click', () => add(careLine(), careParams));
    carePlanForm.querySelectorAll('.b3-cancel').forEach(button => button.addEventListener('click', () => {
        closeParams();
        item.value = '';
        fill(dosageForm, {});
        showMessage('');
    }));

    // RG5 : prescripteur non médecin → l'ordonnance devra être validée par un médecin.
    const prescriber = $('#idPrescriber');
    prescriber.addEventListener('change', () => {
        warning.hidden = prescriber.value === '' || prescriber.selectedOptions[0]?.dataset.doctor === '1';
    });
}
