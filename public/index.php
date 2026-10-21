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
use Aura\Dao\CarePlanDao;
use Aura\Dao\AppointmentDao;
use Aura\Dao\Transaction;

use Aura\Service\AdmissionService;
use Aura\Service\DossierPatientService;
use Aura\Service\ListePatientsService;
use Aura\Service\ModificationPatientService;
use Aura\Service\RechercheIppService;
use Aura\Service\SejourService;
use Aura\Service\AgendaService;

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
        View::page('patient/admission', [], 'AURA - Admission', PatientController::SCRIPTS_ADMISSION);
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
        $controller = new SejourController(
            dossierPatientService: new DossierPatientService(
                new SejourDao($pdo),
                new PatientDao($pdo),
                new AllergieDao($pdo),
                new DateTimeImmutable('today')
            )
        );
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

    // AURA-12.2 : modification d'un patient ; le même contrôleur sert le formulaire (GET) et la soumission (POST).
    $controleurModification = static fn (): PatientController => new PatientController(
        modificationPatientService: new ModificationPatientService(
            new PatientDao(Database::get()),
            new AdmissionService()
        )
    );
    // AURA-15 : recherche d'un patient déjà connu par son IPP (JSON pour le formulaire d'admission).
    $rechercherParIpp = static function (): void {
        $pdo = Database::get();
        $controller = new PatientController(
            rechercheIppService: new RechercheIppService(new PatientDao($pdo), new AllergieDao($pdo))
        );
        $controller->rechercherParIpp();
    };

    // AURA-24 : récupérer l'agenda d'un patient
    $getAgenda = static function (): void {
        error_log("Appel index.php");
        $pdo = Database::get();
        $controller = new SejourController(
            agendaService: new AgendaService(
                new CarePlanDao($pdo),
                new AppointmentDao($pdo),
            ),
            sejourService: new SejourService(
                new SejourDao($pdo),
            ),
        );
        $controller->getAgenda();
    };

    // Le Router traite les requetes HTTP
    $router = new Router();
    $router->get('/patients', $listerPatients);
    $router->post('/patients/create', $admettrePatient);
    $router->get('/patients/modifier', static fn () => $controleurModification()->edit());
    $router->post('/patients/modifier', static fn () => $controleurModification()->update());
    $router->get('/patients/recherche-ipp', $rechercherParIpp);
    $router->get('/sejour', $afficherSejour);
    $router->get('/agenda-patient', $getAgenda);

    $router->notFound(static fn() => View::erreur(404, 'Page introuvable.'));

    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (ConfigurationException $e) {
    // Configuration absente ou base injoignable : message clair, sans identifiants.
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $e->getMessage();
}
