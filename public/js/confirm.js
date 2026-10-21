// Demande confirmation avant d'envoyer un formulaire marqué data-confirm (AURA-13.2 :
// retrait d'une allergie). Sans JavaScript, le formulaire est envoyé directement :
// le contrôle et la décision restent côté serveur.
document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
