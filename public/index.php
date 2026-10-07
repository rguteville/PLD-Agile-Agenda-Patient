<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aura\Controller\PatientController;
// Point d'entrée unique de l'application : le routeur MVC viendra ici.
echo 'AURA';
$controller = new PatientController();
$controller->list();
