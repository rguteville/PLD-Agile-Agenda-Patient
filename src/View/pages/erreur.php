<?php

use Aura\Core\View;

/**
 * @var int $code
 * @var string $message
 */
?>
<section class="erreur" role="alert">
    <h1 class="form-title">Erreur <?= View::e($code) ?></h1>
    <p><?= View::e($message) ?></p>
    <p><a href="/">Retour à l'accueil</a></p>
</section>
