// Barres de navigation des agendas et des équipes : choisir une date (ou un service, une vacation, un
// soignant) affiche directement la page, sans bouton « Afficher » (retours du 2026-11-03). Sans
// JavaScript, le bouton reste visible et le formulaire GET fonctionne comme avant.
// Chargé par l'agenda du patient, l'agenda du service, « Mon planning » et « Équipes ».
// Attend : form[data-auto-submit-form] et son bouton [data-submit-fallback].
// Aucun appel réseau : le formulaire GET est envoyé (navigation ordinaire).
document.querySelectorAll('form[data-auto-submit-form]').forEach(form => {
    form.querySelectorAll('[data-submit-fallback]').forEach(button => {
        button.hidden = true;
    });
    form.querySelectorAll('select, input[type="date"]').forEach(control => {
        control.addEventListener('change', () => {
            // Une date en cours de saisie au clavier (champ vide ou incomplet) n'envoie rien.
            if (control.type === 'date' && control.value === '') {
                return;
            }
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    });
});
