// Mise en page commune (layouts/main.php). Aucune logique métier ici.
// Chargé sur toutes les pages par layouts/main.php. Attend : .patient-banner (facultatif,
// components/patient_banner.php). Aucun appel réseau.

// Hauteur du bandeau patient collant, exposée en variable CSS : les en-têtes de tableau collants
// et le défilement vers un élément focalisé (scroll-padding-top) se placent juste en dessous.
const banner = document.querySelector('.patient-banner');
function updateBannerHeight() {
    document.documentElement.style.setProperty('--bandeau-height', banner ? `${banner.offsetHeight}px` : '0px');
}
if (banner) {
    updateBannerHeight();
    if (typeof ResizeObserver === 'function') {
        new ResizeObserver(updateBannerHeight).observe(banner);
    } else {
        window.addEventListener('resize', updateBannerHeight);
    }
}
