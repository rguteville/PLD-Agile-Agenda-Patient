// Menu figé du dossier patient (src/View/pages/episode/record.php) : un lien vers une sous-catégorie
// du dossier complet ouvre sa section repliée avant d'y défiler ; la partie visible à l'écran est
// signalée dans le menu (aria-current="location"). Sans JavaScript, les ancres fonctionnent seules.
// Chargé par le dossier du séjour (GET /sejour). Attend : .record-menu (components/episode_menu.php),
// ses liens a[href^="#"] et a[data-open-details] vers des <details>. Aucun appel réseau.

(function () {
    const menu = document.querySelector('.record-menu');
    if (!menu) {
        return;
    }

    // Ouvre la section <details> visée par l'ancre et la renvoie (null si absente).
    function openTarget(hash) {
        const target = hash ? document.getElementById(hash.slice(1)) : null;
        if (target && target.tagName === 'DETAILS') {
            target.open = true;
        }
        return target;
    }

    menu.querySelectorAll('a[data-open-details]').forEach(link => {
        link.addEventListener('click', () => openTarget(link.hash));
    });
    // Arrivée directe sur une ancre (lien partagé, rechargement).
    openTarget(window.location.hash);

    // Partie courante : le dernier titre passé sous le haut de l'écran.
    const links = [...menu.querySelectorAll('a[href^="#"]')];
    const targets = links.map(link => document.getElementById(link.hash.slice(1))).filter(Boolean);
    if (typeof IntersectionObserver !== 'function' || targets.length === 0) {
        return;
    }
    const visible = new Set();
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                visible.add(entry.target);
            } else {
                visible.delete(entry.target);
            }
        });
        const current = targets.find(target => visible.has(target));
        links.forEach(link => {
            if (current && link.hash === `#${current.id}`) {
                link.setAttribute('aria-current', 'location');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }, { rootMargin: '-20% 0px -60% 0px' });
    targets.forEach(target => observer.observe(target));
})();
