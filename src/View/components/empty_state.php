<?php

use Aura\Core\View;

/**
 * État vide explicite : un titre, une phrase, une icône. Un écran sans donnée doit dire pourquoi
 * (aucun patient, aucune prescription, rien ce jour) plutôt que rester blanc.
 *
 * @var string $title
 * @var string|null $text
 * @var string|null $icon nom Lucide, « inbox » par défaut
 */
$text = $text ?? null;
?>
<div class="empty-state">
    <?= View::render('components/icon', ['name' => $icon ?? 'inbox', 'class' => 'icon--lg']) ?>
    <p class="empty-title"><?= View::e($title) ?></p>
    <?php if ($text !== null) : ?>
        <p><?= View::e($text) ?></p>
    <?php endif; ?>
</div>
