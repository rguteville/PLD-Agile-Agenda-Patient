// Thème clair / sombre (équipes de nuit). Chargé dans <head> : le choix mémorisé est appliqué avant
// le premier rendu. Sans choix explicite, la page suit le réglage du système (prefers-color-scheme).
// Le choix n'est stocké que dans ce navigateur (localStorage), il n'identifie personne.
(function () {
    const KEY = 'aura-theme';
    const root = document.documentElement;

    function stored() {
        try {
            return localStorage.getItem(KEY);
        } catch (e) {
            return null;
        }
    }

    function current() {
        if (root.dataset.theme === 'dark' || root.dataset.theme === 'light') {
            return root.dataset.theme;
        }
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function refresh(button) {
        const dark = current() === 'dark';
        button.setAttribute('aria-pressed', dark ? 'true' : 'false');
        button.title = dark ? 'Passer en mode clair' : 'Passer en mode sombre';
    }

    // ?theme=light|dark dans l'URL force le thème pour cette page sans le mémoriser (captures, tests).
    const forced = new URLSearchParams(window.location.search).get('theme');
    if (forced === 'dark' || forced === 'light') {
        root.dataset.theme = forced;
    }

    const saved = stored();
    if (forced === null && (saved === 'dark' || saved === 'light')) {
        root.dataset.theme = saved;
    }

    document.addEventListener('DOMContentLoaded', () => {
        const button = document.getElementById('theme-toggle');
        if (!button) {
            return;
        }
        refresh(button);
        button.addEventListener('click', () => {
            const next = current() === 'dark' ? 'light' : 'dark';
            root.dataset.theme = next;
            try {
                localStorage.setItem(KEY, next);
            } catch (e) {
                // Stockage indisponible (navigation privée) : le thème vaut pour la page en cours.
            }
            refresh(button);
        });
    });
})();
