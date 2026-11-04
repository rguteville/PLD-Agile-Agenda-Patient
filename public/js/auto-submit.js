// Barres de navigation des agendas et des équipes : choisir une date (ou un service, une rotation, un
// soignant) affiche directement la page, sans bouton « Afficher ». Sans JavaScript, le bouton reste
// visible et le formulaire GET fonctionne seul.
// Chargé par l'agenda du patient, l'agenda du service, « Mon planning » et « Équipes ».
// Attend : form[data-auto-submit-form] et son bouton [data-submit-fallback].
// Aucun appel réseau : le formulaire GET est envoyé (navigation ordinaire).
document.querySelectorAll('form[data-auto-submit-form]').forEach(form => {
    form.querySelectorAll('[data-submit-fallback]').forEach(button => {
        button.hidden = true;
    });
    const send = () => {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    };
    form.querySelectorAll('select').forEach(control => control.addEventListener('change', send));
    // Date : Chrome signale un « change » à chaque chiffre tapé. On attend donc la fin de la saisie : une
    // seconde sans frappe, la sortie du champ ou Entrée. Le calendrier du navigateur passe par le même délai.
    form.querySelectorAll('input[type="date"]').forEach(control => {
        let timer = null;
        const later = () => {
            clearTimeout(timer);
            if (control.value !== '') {
                timer = setTimeout(send, 1000);
            }
        };
        control.addEventListener('change', later);
        control.addEventListener('blur', () => {
            clearTimeout(timer);
            if (control.value !== '' && control.value !== control.defaultValue) {
                send();
            }
        });
    });
});
