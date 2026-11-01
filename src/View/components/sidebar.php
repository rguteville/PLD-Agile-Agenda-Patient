<?php

use Aura\Core\View;

?>
<nav id="mySidenav" class="sidenav" aria-label="Menu">
    <button type="button" class="closebtn" aria-label="Fermer le menu">
        <?= View::render('components/icon', ['name' => 'x']) ?>
    </button>
    <a href="/patients">Patients</a>
    <a href="/">Admission</a>
</nav>
