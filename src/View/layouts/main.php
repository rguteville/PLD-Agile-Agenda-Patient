<?php

use Aura\Core\View;

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::e($title) ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>
    <?= View::render('components/topbar') ?>
    <?= View::render('components/sidebar') ?>

    <main id="main">
        <?= $content ?>
    </main>

    <script src="/js/layout.js"></script>
    <?php foreach ($scripts as $script) : ?>
        <script src="<?= View::e($script) ?>"></script>
    <?php endforeach; ?>
</body>

</html>
