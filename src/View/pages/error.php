<?php

use Aura\Core\View;

/**
 * Page d'erreur applicative (400, 403, 404…), rendue par View::error() depuis les contrôleurs et le
 * routeur (404 « Page introuvable. », public/index.php). Lien de retour vers la liste des patients.
 *
 * @var int $code code HTTP déjà envoyé
 * @var string $message explication lisible par le soignant
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
