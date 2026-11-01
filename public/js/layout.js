// Menu latéral escamotable (bouton ☰ de la barre supérieure). Aucune logique métier ici.
const sidenav = document.getElementById('mySidenav');
const menuToggle = document.querySelector('.topbar .menu-toggle');

function openNav() {
    if (!sidenav) {
        return;
    }
    sidenav.style.width = '250px';
    document.getElementById('main').style.marginLeft = '250px';
    if (menuToggle) {
        menuToggle.setAttribute('aria-expanded', 'true');
    }
}

function closeNav() {
    if (!sidenav) {
        return;
    }
    sidenav.style.width = '0';
    document.getElementById('main').style.marginLeft = '0';
    if (menuToggle) {
        menuToggle.setAttribute('aria-expanded', 'false');
        menuToggle.focus();
    }
}

if (menuToggle) {
    menuToggle.addEventListener('click', openNav);
}
document.querySelectorAll('#mySidenav .closebtn').forEach(button => button.addEventListener('click', closeNav));
// Échap ferme le menu, comme n'importe quel panneau.
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && sidenav && sidenav.style.width === '250px') {
        closeNav();
    }
});
