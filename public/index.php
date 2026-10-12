<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';


// Point d'entrée unique de l'application : le routeur MVC viendra ici.

use Aura\Controller\PatientController;
use Aura\Core\Router;

$patientController = new PatientController();

$router = new Router();
$router->get('/patients', [$patientController, 'list']);
$router->post('/patients/create', [$patientController, 'create']);


$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
