<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aura\Controller\PatientController;
use Aura\Controller\SejourController;
use Aura\Core\ConfigurationException;
use Aura\Core\Database;
use Aura\Core\Router;
use Aura\Core\View;
use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Dao\SejourDao;
use Aura\Service\DossierPatientService;

// Récupération de l'url
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Fichiers statiques existants (css, js, images)
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . $path)) {
    return false;
}

// La connexion à la base (Aura\Core\Database) est paresseuse : seules les pages
// qui l'utilisent peuvent lever ConfigurationException, les autres fonctionnent
// sans config/config.php.
try {
    //Page par défaut
    if ($path === '/') {
        View::page('patient/admission', [], 'AURA - Admission', ['/js/form.js']);
        exit;
    }

    // Initialisation des Controllers
    $patientController = new PatientController();

    // AURA-14 : le dossier de séjour lit la base ; la connexion n'est ouverte que pour cette route.
    $afficherSejour = static function (): void {
        $pdo = Database::get();
        $controller = new SejourController(new DossierPatientService(
            new SejourDao($pdo),
            new PatientDao($pdo),
            new AllergieDao($pdo),
            new DateTimeImmutable('today')
        ));
        $controller->show();
    };

    // Le Router traite les requetes HTTP
    $router = new Router();
    $router->get('/patients', [$patientController, 'list']);
    $router->post('/patients/create', [$patientController, 'create']);
    $router->get('/sejour', $afficherSejour);
    $router->notFound(static fn () => View::erreur(404, 'Page introuvable.'));

    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (ConfigurationException $e) {
    // Configuration absente ou base injoignable : message clair, sans identifiants.
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $e->getMessage();
}
