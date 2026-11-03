// Champs de date saisis en JJ/MM/AAAA (data-date-input) : les barres obliques sont insérées pendant la
// frappe, seuls les chiffres sont gardés. Le serveur convertit et valide (Aura\Core\DateInput, services).
// Chargé par l'admission (GET /), la modification d'un patient et le dossier du séjour.
// Attend : input[data-date-input] (components/field.php, type date ; champs créés par inline-edit.js).
// Aucun appel réseau.

(function () {
    // « 14031958 » → « 14/03/1958 » : barres après le jour et le mois, 8 chiffres au plus.
    function format(digits) {
        const d = digits.slice(0, 8);
        if (d.length <= 2) {
            return d;
        }
        if (d.length <= 4) {
            return `${d.slice(0, 2)}/${d.slice(2)}`;
        }
        return `${d.slice(0, 2)}/${d.slice(2, 4)}/${d.slice(4)}`;
    }

    document.addEventListener('input', event => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.matches('[data-date-input]')) {
            return;
        }
        // Effacement : on laisse l'utilisateur supprimer une barre sans la réinsérer aussitôt.
        if (event.inputType && event.inputType.startsWith('delete')) {
            return;
        }
        input.value = format(input.value.replace(/\D/g, ''));
    });
})();
