<?php

use Aura\Core\View;

// Lien courant souligné pour l'utilisateur et annoncé aux lecteurs d'écran (aria-current).
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$current = static fn (string $href): string => $href === $path ? ' aria-current="page"' : '';
?>
<header class="topbar">
    <button type="button" class="menu-toggle" aria-label="Ouvrir le menu" aria-controls="mySidenav"
            aria-expanded="false">
        <?= View::render('components/icon', ['name' => 'menu']) ?>
    </button>
    <a class="brand" href="/patients">AURA</a>
    <nav aria-label="Navigation principale">
        <a class="nav-link" href="/patients"<?= $current('/patients') ?>>Patients</a>
        <a class="nav-link" href="/"<?= $current('/') ?>>Admission</a>
    </nav>
    <button type="button" id="theme-toggle" class="theme-toggle" aria-pressed="false"
            title="Basculer le mode sombre">
        <?= View::render('components/icon', ['name' => 'moon']) ?>
        <?= View::render('components/icon', ['name' => 'sun']) ?>
        <span class="sr-only">Mode sombre</span>
    </button>
</header>
