// AURA-15 : recherche d'un patient déjà connu par son IPP et pré-remplissage du
// formulaire d'admission (identité en lecture seule + allergies connues).
// Le serveur reste la garantie : à la validation, AdmissionService réutilise le patient
// qui porte cet IPP, quelles que soient les valeurs d'identité envoyées (pas de doublon).

(function () {
    const form = document.getElementById('admission-form');
    if (!form) {
        return;
    }
    const ippInput = form.querySelector('input[name="ipp"]');
    const searchButton = document.getElementById('ipp-rechercher');
    const status = document.getElementById('ipp-statut');
    if (!ippInput || !searchButton || !status) {
        return;
    }

    const SEARCH_URL = '/patients/recherche-ipp';
    const TEXT_FIELDS = ['lastname', 'firstname', 'birthDate'];
    const GENDER_FIELD = 'gender';
    const MESSAGES = {
        searching: 'Recherche en cours…',
        unknown: 'IPP inconnu, saisie manuelle.',
        found: 'Patient déjà connu du service : identité reprise de son dossier.',
        failure: 'La recherche a échoué. Veuillez réessayer.',
        noAllergy: 'Aucune allergie connue',
        allergies: 'Allergies connues :',
        edit: "Modifier l'identité",
    };

    let lastSearchedIpp = null;
    let prefilled = false;

    function textField(name) {
        return form.querySelector(`input[name="${CSS.escape(name)}"]`);
    }

    function genderRadios() {
        return form.querySelectorAll(`input[type="radio"][name="${CSS.escape(GENDER_FIELD)}"]`);
    }

    function setLocked(locked) {
        TEXT_FIELDS.forEach(name => {
            const input = textField(name);
            if (input) {
                input.readOnly = locked;
                input.classList.toggle('is-locked', locked);
            }
        });
        // Un bouton radio n'a pas d'attribut readonly : seuls les choix non retenus sont
        // désactivés, le choix retenu reste envoyé avec le formulaire.
        genderRadios().forEach(radio => {
            radio.disabled = locked && !radio.checked;
        });
        form.classList.toggle('patient-connu', locked);
    }

    function fillIdentity(patient) {
        TEXT_FIELDS.forEach(name => {
            const input = textField(name);
            if (input) {
                input.value = patient[name] || '';
            }
        });
        genderRadios().forEach(radio => {
            radio.checked = radio.value === patient.gender;
        });
        prefilled = true;
    }

    // N'efface que ce que la recherche a rempli : une saisie manuelle n'est jamais perdue.
    function clearPrefilledIdentity() {
        if (!prefilled) {
            return;
        }
        TEXT_FIELDS.forEach(name => {
            const input = textField(name);
            if (input) {
                input.value = '';
            }
        });
        genderRadios().forEach(radio => {
            radio.checked = false;
        });
        prefilled = false;
    }

    function showStatus(kind, message) {
        status.textContent = '';
        status.className = 'field-status';
        if (kind) {
            status.classList.add(kind);
        }
        if (message) {
            const p = document.createElement('p');
            p.textContent = message;
            status.appendChild(p);
        }
    }

    function showFound(patient, allergies) {
        showStatus('trouve', MESSAGES.found);

        const block = document.createElement('div');
        if (allergies.length === 0) {
            block.className = 'aucune-allergie';
            block.textContent = MESSAGES.noAllergy;
        } else {
            block.className = 'allergies';
            const label = document.createElement('span');
            label.className = 'alerte';
            label.textContent = MESSAGES.allergies;
            const list = document.createElement('ul');
            allergies.forEach(allergy => {
                const item = document.createElement('li');
                item.textContent = allergy.criticite
                    ? `${allergy.libelle} (${allergy.criticite})`
                    : allergy.libelle;
                list.appendChild(item);
            });
            block.appendChild(label);
            block.appendChild(list);
        }
        status.appendChild(block);

        if (patient.lienModification) {
            const link = document.createElement('a');
            link.href = patient.lienModification;
            link.textContent = MESSAGES.edit;
            const p = document.createElement('p');
            p.appendChild(link);
            status.appendChild(p);
        }
    }

    function unlockAndShow(kind, message) {
        clearPrefilledIdentity();
        setLocked(false);
        showStatus(kind, message);
    }

    function reset() {
        lastSearchedIpp = null;
        unlockAndShow(null, '');
    }

    async function search() {
        const ipp = ippInput.value.trim();
        if (ipp === '') {
            reset();
            return;
        }
        if (ipp === lastSearchedIpp) {
            return;
        }
        lastSearchedIpp = ipp;
        showStatus('en-cours', MESSAGES.searching);

        let data;
        try {
            const response = await fetch(`${SEARCH_URL}?ipp=${encodeURIComponent(ipp)}`, {
                headers: { 'Accept': 'application/json' },
            });
            data = await response.json();
        } catch (e) {
            lastSearchedIpp = null;
            unlockAndShow('erreur', MESSAGES.failure);
            return;
        }

        // La saisie a changé pendant la requête : ce résultat ne la concerne plus.
        if (ippInput.value.trim() !== ipp) {
            return;
        }
        if (!data.ok) {
            unlockAndShow('erreur', (data.erreurs && data.erreurs.ipp) || MESSAGES.failure);
            return;
        }
        if (!data.trouve) {
            unlockAndShow('inconnu', data.message || MESSAGES.unknown);
            return;
        }

        const patient = data.patient || {};
        fillIdentity(patient);
        setLocked(true);
        showFound(patient, data.allergies || []);
    }

    searchButton.addEventListener('click', search);
    ippInput.addEventListener('blur', search);
    ippInput.addEventListener('keydown', event => {
        // Entrée dans le champ IPP lance la recherche, pas l'admission.
        if (event.key === 'Enter') {
            event.preventDefault();
            search();
        }
    });
    ippInput.addEventListener('input', () => {
        if (ippInput.value.trim() !== lastSearchedIpp) {
            reset();
        }
    });
})();
