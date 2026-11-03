// Demande confirmation avant d'envoyer un formulaire marqué data-confirm (AURA-13.2 :
// retrait d'une allergie dans le dossier ; AURA-30 : retrait d'une équipe). Sans JavaScript, le
// formulaire est envoyé directement : le contrôle et la décision restent côté serveur.
// Chargé par le dossier du séjour (GET /sejour) et « Équipes » (GET /equipes).
// Attend : form[data-confirm] (le texte de la question). Aucun appel réseau.
document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
