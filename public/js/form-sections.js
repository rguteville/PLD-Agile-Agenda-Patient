// Sections repliables des formulaires longs (<details data-form-section>, components/form.php) :
// - l'en-tête affiche « n sur m renseignés », recalculé à chaque saisie ;
// - une section dont un champ est en erreur (contrôle HTML natif ou refus serveur via form.js)
//   s'ouvre pour que l'erreur soit visible ; le premier champ fautif reçoit le focus.
// Le serveur reste la garantie : ce script n'empêche ni n'autorise aucun envoi.
// Chargé par l'admission (GET /). Écoute aura:form-errors (form.js). Aucun appel réseau.

(function () {
    const sections = document.querySelectorAll('details[data-form-section]');
    if (sections.length === 0) {
        return;
    }

    // Champ renseigné : case cochée, ou valeur non vide.
    function isFilled(control) {
        if (control.type === 'checkbox' || control.type === 'radio') {
            return control.checked;
        }
        return control.value.trim() !== '';
    }

    // Un groupe de cases (allergies[]) compte pour un seul champ, renseigné si une case est cochée.
    function countFilled(section) {
        const seen = new Set();
        let filled = 0;
        section.querySelectorAll('input, textarea, select').forEach(control => {
            const key = control.name.replace(/\[\]$/, '');
            if (seen.has(key)) {
                return;
            }
            const group = section.querySelectorAll(`[name="${CSS.escape(control.name)}"]`);
            const groupFilled = Array.from(group).some(isFilled);
            seen.add(key);
            if (groupFilled) {
                filled += 1;
            }
        });
        return filled;
    }

    // Met à jour « n sur m renseignés » dans l'en-tête de la section.
    function updateCount(section) {
        const counter = section.querySelector('[data-section-count]');
        if (!counter) {
            return;
        }
        const total = Number(counter.dataset.total) || 0;
        const filled = countFilled(section);
        counter.textContent = `${filled} sur ${total} renseigné${filled > 1 ? 's' : ''}`;
        section.classList.toggle('form-section--filled', filled > 0);
    }

    // Ouvre chaque section qui contient une erreur et donne le focus au premier champ fautif.
    function openSectionsWithErrors(form) {
        let first = null;
        sections.forEach(section => {
            const invalid = section.querySelector('.has-error, [aria-invalid="true"], :invalid');
            if (invalid) {
                section.open = true;
                first = first || invalid;
            }
        });
        if (first) {
            const control = first.matches('input, textarea, select') ? first : first.querySelector('input, textarea, select');
            if (control) {
                control.focus();
            }
        }
    }

    sections.forEach(section => {
        updateCount(section);
        section.addEventListener('input', () => updateCount(section));
        section.addEventListener('change', () => updateCount(section));
    });

    const form = sections[0].closest('form');
    if (form) {
        // Contrôle HTML natif (required, min, max…) : l'événement « invalid » ne remonte pas, on l'écoute en capture.
        form.addEventListener('invalid', () => openSectionsWithErrors(form), true);
        // Refus serveur affiché par form.js.
        form.addEventListener('aura:form-errors', () => openSectionsWithErrors(form));
        openSectionsWithErrors(form);
    }
})();
