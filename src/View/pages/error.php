<?php

use Aura\Core\View;

/**
 * @var int $code
 * @var string $message
 */
?>
<section class="error" role="alert">
    <h1 class="form-title">
        <?= View::render('components/icon', ['name' => 'circle-alert']) ?>
        Erreur <?= View::e($code) ?>
    </h1>
    <p><?= View::e($message) ?></p>
    <p><a class="button button--secondary" href="/patients">Retour à la liste des patients</a></p>
</section>
