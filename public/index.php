<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aura\Controller\PatientController;
use Aura\Core\Router;
use Aura\Core\View;

// Récupération de l'url
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Fichiers statiques existants (css, js, images)
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . $path)) {
    return false;
}

//Page par défaut
if ($path === '/') {
    View::page('patient/admission', [], 'AURA - Admission', ['/js/form.js']);
    exit;
}

// Initialisation des Controllers
$patientController = new PatientController();

// Le Router traite les requetes HTTP
$router = new Router();
$router->get('/patients', [$patientController, 'list']);
$router->post('/patients/create', [$patientController, 'create']);


$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
