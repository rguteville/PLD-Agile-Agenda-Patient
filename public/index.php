<?php

declare(strict_types=1);

use Aura\Dao\ActivityDefinitionDao;

require dirname(__DIR__) . '/vendor/autoload.php';

use Aura\Controller\CarePlanController;
use Aura\Controller\PatientController;
use Aura\Controller\SejourController;
use Aura\Core\ConfigurationException;
use Aura\Core\Database;
use Aura\Core\Router;
use Aura\Core\View;

use Aura\Dao\AllergieDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\SejourDao;
use Aura\Dao\SubstanceDao;
use Aura\Dao\AppointmentDao;
use Aura\Dao\Transaction;

use Aura\Service\AdmissionService;
use Aura\Service\AllergyIntoleranceService;
use Aura\Service\CarePlanService;
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

    // AURA-14 : le dossier de séjour lit la base ; la connexion n'est ouverte que pour ces routes.
    // Il affiche aussi les prescriptions validées du séjour (CarePlanService).
    // AURA-13.2 : le même contrôleur sert l'ajout et le retrait d'allergies (POST) depuis le dossier.
    $controleurSejour = static function (): SejourController {
        $pdo = Database::get();
        $dossierPatientService = new DossierPatientService(
            new SejourDao($pdo),
            new PatientDao($pdo),
            new AllergieDao($pdo),
            new SubstanceDao($pdo),
            new DateTimeImmutable('today')
        );

        return new SejourController(
            dossierPatientService: $dossierPatientService,
            carePlanService: new CarePlanService(
                new CarePlanDao($pdo),
                new ReferenceDataDao($pdo),
                $dossierPatientService
            ),
            allergyIntoleranceService: new AllergyIntoleranceService(
                new SejourDao($pdo),
                new AllergieDao($pdo),
                new SubstanceDao($pdo)
            )
        );
    };

    // AURA-12.1 : la liste des patients lit la base ; même principe que le dossier de séjour.
    $listerPatients = static function (): void {
        $pdo = Database::get();
        $controller = new PatientController(
            listePatientsService: new ListePatientsService(new PatientDao($pdo), new SejourDao($pdo))
        );
        $controller->list();
    };

    // Epic 4 : prescriptions d'un séjour (liste, écran B3, validation) ; même principe que le dossier.
    $carePlans = static function (): CarePlanController {
        $pdo = Database::get();
        return new CarePlanController(new CarePlanService(
            new CarePlanDao($pdo),
            new ReferenceDataDao($pdo),
            new DossierPatientService(
                new SejourDao($pdo),
                new PatientDao($pdo),
                new AllergieDao($pdo),
                new SubstanceDao($pdo),
                new DateTimeImmutable('today')
            )
        ));
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

    // AURA-24 : agenda d'un séjour, bandeau identité + allergies en tête (RG2).
    $getAgenda = static function (): void {
        $pdo = Database::get();
        $controller = new SejourController(
            dossierPatientService: new DossierPatientService(
                new SejourDao($pdo),
                new PatientDao($pdo),
                new AllergieDao($pdo),
                new SubstanceDao($pdo),
                new DateTimeImmutable('today')
            ),
            agendaService: new AgendaService(
                new CarePlanDao($pdo),
                new AppointmentDao($pdo),
                new ActivityDefinitionDao($pdo)
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
    $router->get('/sejour', static fn () => $controleurSejour()->show());
    $router->post('/sejour/allergies/ajouter', static fn () => $controleurSejour()->addAllergy());
    $router->post('/sejour/allergies/retirer', static fn () => $controleurSejour()->removeAllergy());
    $router->get('/care-plans', static fn () => $carePlans()->list());
    $router->get('/care-plans/new', static fn () => $carePlans()->newForm());
    $router->post('/care-plans/create', static fn () => $carePlans()->create());
    $router->post('/care-plans/validate', static fn () => $carePlans()->validate());
    $router->post('/care-plans/stop', static fn () => $carePlans()->stop());
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
