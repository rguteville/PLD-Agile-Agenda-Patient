// Liste des allergies de l'admission (components/allergy_picker.php) : filtre par libellé (sans tenir
// compte des accents ni de la casse) et compteur d'allergènes cochés, annoncé aux lecteurs d'écran.
// La criticité de chaque allergène coché s'affiche par CSS ; ce script ne fait que du confort :
// le serveur valide les identifiants et les criticités (AdmissionService::valider()).

(function () {
    document.querySelectorAll('[data-allergy-picker]').forEach(picker => {
        const filter = picker.querySelector('[data-allergy-filter]');
        const count = picker.querySelector('[data-allergy-count]');
        const empty = picker.querySelector('[data-allergy-empty]');
        const options = [...picker.querySelectorAll('.allergy-option')];

        const fold = text => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

        function updateCount() {
            const n = picker.querySelectorAll('input[type="checkbox"]:checked').length;
            const plural = n > 1 ? 's' : '';
            count.textContent = `${n} allergène${plural} coché${plural}`;
        }

        function applyFilter() {
            const query = fold(filter.value);
            let visible = 0;
            options.forEach(option => {
                const box = option.querySelector('input[type="checkbox"]');
                // Un allergène coché reste toujours visible : on ne cache jamais une information saisie.
                const show = query === '' || box.checked || fold(option.dataset.allergyLabel || '').includes(query);
                option.hidden = !show;
                visible += show ? 1 : 0;
            });
            empty.hidden = visible > 0;
        }

        picker.addEventListener('change', event => {
            if (event.target.matches('input[type="checkbox"]')) {
                updateCount();
                // Un allergène décoché perd sa criticité : rien d'incohérent n'est envoyé.
                if (!event.target.checked) {
                    const select = event.target.closest('.allergy-option').querySelector('select');
                    select.value = '';
                } else {
                    event.target.closest('.allergy-option').querySelector('select').focus();
                }
            }
        });
        filter.addEventListener('input', applyFilter);
        // Entrée dans le filtre ne doit pas envoyer le formulaire d'admission.
        filter.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
        // Le formulaire peut être rempli par la recherche de patient : on se resynchronise.
        picker.closest('form')?.addEventListener('aura:allergies-filled', () => {
            updateCount();
            applyFilter();
        });
        updateCount();
    });
})();
