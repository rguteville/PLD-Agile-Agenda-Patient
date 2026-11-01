// Agenda patient : impression de la journée et ouverture du dialogue de validation (balisage seul,
// aucun envoi au serveur tant que la route de validation n'existe pas).
const printButton = document.getElementById('print-page');
if (printButton) {
    printButton.addEventListener('click', () => window.print());
}

const dialog = document.getElementById('validation-dialog');
if (dialog && typeof dialog.showModal === 'function') {
    const context = dialog.querySelector('[data-dialog-context]');
    const time = dialog.querySelector('#validation-time');

    // Maintenant, au format datetime-local (2026-10-14T08:00), à l'heure locale.
    function localNow() {
        const date = new Date();
        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        return date.toISOString().slice(0, 16);
    }

    document.querySelectorAll('[data-validate]').forEach(button => {
        button.addEventListener('click', () => {
            context.textContent = button.dataset.label;
            time.value = localNow();
            dialog.showModal();
        });
    });
    dialog.querySelectorAll('[data-dialog-close]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
}
