<?php

use Aura\Core\View;

/*
 * Barre du haut, commune à toutes les pages (incluse par layouts/main.php) : liens Patients, Admission,
 * Agenda service, Équipes, Mon planning, et bouton du thème sombre #theme-toggle (public/js/theme.js).
 * Aucune variable : la page courante est lue dans $_SERVER['REQUEST_URI'] ; son lien est souligné pour
 * l'utilisateur et annoncé aux lecteurs d'écran (aria-current).
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$current = static fn (string $href): string => $href === $path ? ' aria-current="page"' : '';
?>
<header class="topbar">
    <a class="brand" href="/patients">AURA</a>
    <nav aria-label="Navigation principale">
        <a class="nav-link" href="/patients"<?= $current('/patients') ?>>Patients</a>
        <a class="nav-link" href="/"<?= $current('/') ?>>Admission</a>
        <a class="nav-link" href="/agenda-service"<?= $current('/agenda-service') ?>>Agenda service</a>
        <a class="nav-link" href="/equipes"<?= $current('/equipes') ?>>Équipes</a>
        <a class="nav-link" href="/mon-planning"<?= $current('/mon-planning') ?>>Mon planning</a>
    </nav>
    <button type="button" id="theme-toggle" class="theme-toggle" aria-pressed="false"
            title="Basculer le mode sombre">
        <?= View::render('components/icon', ['name' => 'moon']) ?>
        <?= View::render('components/icon', ['name' => 'sun']) ?>
        <span class="sr-only">Mode sombre</span>
    </button>
</header>
