<?php

use Aura\Core\View;

/**
 * Gabarit commun de toutes les pages, rendu par View::page() (et View::error()) : en-tête HTML, barre du
 * haut (components/topbar), contenu de la page dans <main>, puis les scripts. theme.js est chargé dans
 * <head>, layout.js sur toutes les pages, et les scripts propres à la page viennent du paramètre
 * $scripts de View::page().
 *
 * @var string $title titre de l'onglet (« AURA » par défaut dans View::page())
 * @var string $content HTML déjà rendu de la page (pages/…), inséré tel quel
 * @var list<string> $scripts chemins des scripts propres à la page (/js/…), [] par défaut
 */
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title><?= View::e($title) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <?php /* Chargé dans <head> pour appliquer le thème choisi avant le premier rendu (pas de flash). */ ?>
    <script src="/js/theme.js"></script>
</head>

<body>
    <a class="skip-link" href="#main">Aller au contenu</a>
    <?= View::render('components/topbar') ?>

    <main id="main" tabindex="-1">
        <?= $content ?>
    </main>

    <script src="/js/layout.js"></script>
    <?php foreach ($scripts as $script) : ?>
        <script src="<?= View::e($script) ?>"></script>
    <?php endforeach; ?>
</body>

</html>
