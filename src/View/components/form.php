<?php

use Aura\Core\View;

?>

<form id="<?= View::e($id ?? 'form') ?>" action="<?= View::e($action) ?>" method="post" data-ajax>
    <?php if (!empty($title)) : ?>
        <h1 class="form-title"><?= View::e($title) ?></h1>
    <?php endif; ?>
    <?php if (!empty($error)) : ?>
        <p class="form-error" role="alert"><?= View::e($error) ?></p>
    <?php endif; ?>

    <?php foreach ($fields as $field) : ?>
        <?= View::render('components/field', ['field' => $field]) ?>
    <?php endforeach; ?>

    <div class="form-actions">
        <button type="submit"><?= View::e($submit ?? 'Enregistrer') ?></button>
    </div>
</form>
