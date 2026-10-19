

const form = document.getElementById('prescription-form');

if (form) {
    const listes = JSON.parse(form.dataset.listes);
    const $ = selecteur => form.querySelector(selecteur);

    const type = $('#type');
    const element = $('#element');
    const forme = $('#forme');
    const message = $('#b3-message');
    const avertissement = $('#b3-avertissement');
    const paramMedicament = $('#b3-param-medicament');
    const paramSoin = $('#b3-param-soin');
    const champsLignes = $('#b3-lignes');
    const panier = document.getElementById('b3-panier');
    let compteur = 0;

    // Champs facultatifs de la figure 9 enregistrés en base (id du champ sans « med- » = nom envoyé).
    // Les autres champs de la figure sont grisés : pas de colonne dans le schéma.
    const CHAMPS_FACULTATIFS = ['important', 'nurseInstructions'];

    function remplir(select, options) {
        select.replaceChildren(new Option('', ''));
        Object.entries(options).forEach(([valeur, libelle]) => select.add(new Option(libelle, valeur)));
        select.disabled = Object.keys(options).length === 0;
    }

    function afficherMessage(texte) {
        message.textContent = texte;
        message.hidden = texte === '';
    }

    const libelleChoisi = select => select.selectedOptions[0]?.textContent.trim() ?? '';
    const natureCategorie = () => type.selectedOptions[0]?.dataset.kind ?? '';

    function maintenantLocal() {
        const d = new Date();
        d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
        return d.toISOString().slice(0, 16);
    }

    function dateLisible(valeur) {
        const [jour, heure] = valeur.split('T');
        const [a, m, j] = jour.split('-');
        return `${j}/${m}/${a} ${heure}`;
    }

    function viderFiche(fiche) {
        fiche.querySelectorAll('input:not([type="hidden"]):not(:disabled)').forEach(input => { input.value = ''; });
        fiche.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
        fiche.querySelectorAll('[id$="heures-bloc"]').forEach(bloc => { bloc.hidden = true; });
    }

    function fermerFiches() {
        paramMedicament.hidden = true;
        paramSoin.hidden = true;
    }

    function ouvrirFiche(fiche) {
        fermerFiches();
        viderFiche(fiche);
        fiche.querySelector('.b3-param-categorie').textContent = libelleChoisi(type);
        fiche.hidden = false;
    }

    // ── Liste « 1. » : la catégorie décide du contenu de « 2. » et « 3. »
    type.addEventListener('change', () => {
        fermerFiches();
        remplir(forme, {});
        afficherMessage('');
        const nature = natureCategorie();
        if (nature === 'MEDICATION') {
            const dci = {};
            Object.entries(listes.medications).forEach(([cle, m]) => { dci[cle] = m.label; });
            remplir(element, dci);
        } else if (nature === 'CARE') {
            remplir(element, listes.careTypes);
        } else {
            remplir(element, {});
            if (nature === 'GROUP') {
                afficherMessage('Aucun groupe de prescriptions (protocole) n\'est encore défini.');
            }
        }
    });

    // ── Liste « 2. » : médicament → formes en « 3. » ; soin → fiche du soin
    element.addEventListener('change', () => {
        afficherMessage('');
        if (natureCategorie() === 'MEDICATION') {
            fermerFiches();
            const molecule = listes.medications[element.value];
            remplir(forme, molecule ? molecule.forms : {});
        } else if (natureCategorie() === 'CARE' && element.value !== '') {
            ouvrirFiche(paramSoin);
            $('#soin-nom').value = libelleChoisi(element);
            $('#soin-debut').value = maintenantLocal();
        } else {
            fermerFiches();
        }
    });

    // ── Liste « 3. » : forme choisie → fiche de paramètres de la figure 9
    forme.addEventListener('change', () => {
        if (forme.value === '') {
            fermerFiches();
            return;
        }
        ouvrirFiche(paramMedicament);
        $('#med-dci').value = libelleChoisi(element);
        $('#med-formulation').value = libelleChoisi(forme);
        $('#med-debut').value = maintenantLocal();
    });

    // « Toutes les … heures » : affiche le nombre d'heures
    [['#med-frequence', '#med-heures-bloc'], ['#soin-frequence', '#soin-heures-bloc']].forEach(([sel, bloc]) => {
        $(sel).addEventListener('change', () => { $(bloc).hidden = $(sel).value !== 'SCHEDULED'; });
    });

    function heuresValides(valeur) {
        const heures = Number(valeur);
        return Number.isInteger(heures) && heures >= 1;
    }

    // ── Lecture des fiches : renvoie la ligne à ajouter, ou { erreur }
    function ligneMedicament() {
        const frequence = $('#med-frequence').value;
        const champs = {
            kind: 'medication',
            categoryId: type.value,
            id: forme.value,
            frequencyType: frequence,
            frequencyHours: frequence === 'SCHEDULED' ? $('#med-heures').value.trim() : '',
            route: $('#med-voie').value,
            dose: $('#med-dose').value.trim(),
            start: $('#med-debut').value,
            end: $('#med-fin').value,
        };
        CHAMPS_FACULTATIFS.forEach(nom => { champs[nom] = $(`#med-${nom}`).value.trim(); });

        if (frequence === '') {
            return { erreur: 'La fréquence est obligatoire.' };
        }
        if (frequence === 'SCHEDULED' && !heuresValides(champs.frequencyHours)) {
            return { erreur: 'Indiquez toutes les combien d\'heures.' };
        }
        if (champs.route === '') {
            return { erreur: 'La voie d\'administration est obligatoire.' };
        }
        if (champs.dose === '') {
            return { erreur: 'La dose est obligatoire.' };
        }
        if (champs.start === '') {
            return { erreur: 'La date de début est obligatoire.' };
        }
        if (champs.end !== '' && champs.end < champs.start) {
            return { erreur: 'L\'arrêt doit être après le début.' };
        }

        const rythme = frequence === 'SCHEDULED' ? `/${champs.frequencyHours}h` : libelleChoisi($('#med-frequence'));
        let libelle = `[${champs.route}] ${libelleChoisi(element)} (${libelleChoisi(forme)}) ${champs.dose} (${rythme})`;
        if (champs.nurseInstructions !== '') {
            libelle += ` -> ${champs.nurseInstructions}`;
        }
        libelle += ` du ${dateLisible(champs.start)}` + (champs.end !== '' ? ` au ${dateLisible(champs.end)}` : '');

        return { categorie: libelleChoisi(type), libelle, champs, cle: `medication|${champs.id}` };
    }

    function ligneSoin() {
        const frequence = $('#soin-frequence').value;
        const champs = {
            kind: 'care',
            categoryId: type.value,
            id: element.value,
            frequencyHours: frequence === 'SCHEDULED' ? $('#soin-heures').value.trim() : '',
            start: $('#soin-debut').value,
            end: $('#soin-fin').value,
            timeConstraint: $('#soin-contrainte').value.trim(),
        };

        if (frequence === 'SCHEDULED' && !heuresValides(champs.frequencyHours)) {
            return { erreur: 'Indiquez toutes les combien d\'heures.' };
        }
        if (champs.start === '') {
            return { erreur: 'La date de début est obligatoire.' };
        }
        if (champs.end !== '' && champs.end < champs.start) {
            return { erreur: 'L\'arrêt doit être après le début.' };
        }

        let libelle = libelleChoisi(element)
            + (frequence === 'SCHEDULED' ? ` (/${champs.frequencyHours}h)` : ' (une fois)')
            + ` du ${dateLisible(champs.start)}` + (champs.end !== '' ? ` au ${dateLisible(champs.end)}` : '');
        if (champs.timeConstraint !== '') {
            libelle += ` -> ${champs.timeConstraint}`;
        }

        return { categorie: libelleChoisi(type), libelle, champs, cle: Object.values(champs).join('|') };
    }

    // ── « OK » : ajoute la ligne à l'ordonnance en cours de saisie
    function ajouter(ligne, fiche) {
        if (ligne.erreur) {
            afficherMessage(ligne.erreur);
            return;
        }
        if ([...champsLignes.children].some(bloc => bloc.dataset.cle === ligne.cle)) {
            afficherMessage('Cette prescription est déjà dans l\'ordonnance.');
            return;
        }

        const n = compteur++;
        const bloc = document.createElement('div');
        bloc.dataset.cle = ligne.cle;
        Object.entries(ligne.champs).forEach(([champ, valeur]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `lines[${n}][${champ}]`;
            input.value = valeur;
            bloc.appendChild(input);
        });
        champsLignes.appendChild(bloc);

        const tr = panier.insertRow();
        tr.insertCell().textContent = ligne.categorie;
        tr.insertCell().textContent = ligne.libelle;
        const retirer = document.createElement('button');
        retirer.type = 'button';
        retirer.className = 'b3-retirer';
        retirer.textContent = '✕';
        retirer.title = 'Retirer';
        retirer.addEventListener('click', () => {
            tr.remove();
            bloc.remove();
        });
        tr.insertCell().appendChild(retirer);

        afficherMessage('');
        fiche.hidden = true;
        element.value = '';
        remplir(forme, {});
    }

    $('#med-ok').addEventListener('click', () => ajouter(ligneMedicament(), paramMedicament));
    $('#soin-ok').addEventListener('click', () => ajouter(ligneSoin(), paramSoin));
    form.querySelectorAll('.b3-annuler').forEach(bouton => bouton.addEventListener('click', () => {
        fermerFiches();
        element.value = '';
        remplir(forme, {});
        afficherMessage('');
    }));

    // RG5 : prescripteur non médecin → l'ordonnance devra être validée par un médecin.
    const prescripteur = $('#prescriber');
    prescripteur.addEventListener('change', () => {
        avertissement.hidden = prescripteur.value === ''
            || prescripteur.selectedOptions[0]?.dataset.doctor === '1';
    });
}
