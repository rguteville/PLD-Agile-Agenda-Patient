<?php

use Aura\Core\View;

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
