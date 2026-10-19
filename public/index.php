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
use Aura\Dao\Transaction;
use Aura\Service\AdmissionService;
use Aura\Service\DossierPatientService;
use Aura\Service\ListePatientsService;
use Aura\Service\SejourService;

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

    // AURA-10.4 : l'admission écrit en base (patient + séjour) ; connexion ouverte pour cette route.
    $admettrePatient = static function (): void {
        $pdo = Database::get();
        $controller = new PatientController(new AdmissionService(
            null,
            new PatientDao($pdo),
            new SejourService(new SejourDao($pdo)),
            new Transaction($pdo)
        ));
        $controller->create();
    };

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

    // AURA-12.1 : la liste des patients lit la base ; même principe que le dossier de séjour.
    $listerPatients = static function (): void {
        $pdo = Database::get();
        $controller = new PatientController(
            listePatientsService: new ListePatientsService(new PatientDao($pdo), new SejourDao($pdo))
        );
        $controller->list();
    };

    //AURA-24 : l'agenda d'un patient
    // Le Router traite les requetes HTTP
    $router = new Router();
    $router->get('/patients', $listerPatients);
    $router->post('/patients/create', $admettrePatient);
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
