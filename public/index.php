<?php

declare(strict_types=1);

use Aura\Dao\ActivityDefinitionDao;
use Aura\Dao\MedicationDao;

require dirname(__DIR__) . '/vendor/autoload.php';

// Heure du service (Lyon) quel que soit le réglage de PHP : sans cela, un PHP en UTC (Homebrew, Debian
// sans date.timezone) décale d'une à deux heures l'heure d'admission, la fin de séjour et les retards
// de l'agenda par rapport à l'heure affichée par le navigateur.
date_default_timezone_set('Europe/Paris');

use Aura\Controller\CarePlanController;
use Aura\Controller\CareController;
use Aura\Controller\PatientController;
use Aura\Controller\RecordController;
use Aura\Controller\EpisodeOfCareController;
use Aura\Controller\ServiceController;
use Aura\Controller\TeamController;
use Aura\Core\ConfigurationException;
use Aura\Core\Database;
use Aura\Core\Router;
use Aura\Core\View;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\CareTeamDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\PatientDao;
use Aura\Dao\PlanDefinitionDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\SubstanceDao;
use Aura\Dao\AppointmentDao;
use Aura\Dao\Transaction;

use Aura\Service\AdmissionService;
use Aura\Service\AllergyIntoleranceService;
use Aura\Service\CarePlanService;
use Aura\Service\PatientDeletionService;
use Aura\Service\PatientRecordService;
use Aura\Service\PatientListService;
use Aura\Service\PatientUpdateService;
use Aura\Service\PatientSearchService;
use Aura\Service\PlanDefinitionService;
use Aura\Service\IppSearchService;
use Aura\Service\RecordEditService;
use Aura\Service\RecordSectionService;
use Aura\Service\EpisodeOfCareService;
use Aura\Service\AgendaService;
use Aura\Service\AppointmentService;
use Aura\Service\CareTeamService;

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
    // Listes de l'admission lues en base (service hospitalier, médecins référents, allergènes du référentiel).
    // Sans config.php ni base, la page d'accueil reste affichable : listes vides, le reste du
    // formulaire fonctionne (la saisie sera refusée à l'envoi, qui a besoin de la base).
    $admissionReferenceData = static function (): array {
        try {
            $pdo = Database::get();

            return [
                'services' => (new ReferenceDataDao($pdo))->healthcareServices(),
                'doctors' => (new ReferenceDataDao($pdo))->doctors(),
                'substances' => PatientRecordService::availableSubstances((new SubstanceDao($pdo))->findAll(), []),
            ];
        } catch (ConfigurationException | \PDOException) {
            // Base absente, injoignable ou pas encore migrée : l'accueil reste affichable sans les listes.
            return [];
        }
    };

    //Page par défaut : formulaire d'admission
    if ($path === '/') {
        (new PatientController(referenceDataSets: $admissionReferenceData()))->showAdmissionForm();
        exit;
    }

    // AURA-10.4 : l'admission écrit en base (patient + séjour + allergies) ; connexion ouverte pour cette route.
    $admitPatient = static function () use ($admissionReferenceData): void {
        $pdo = Database::get();
        $controller = new PatientController(
            new AdmissionService(
                null,
                new PatientDao($pdo),
                new EpisodeOfCareService(new EpisodeOfCareDao($pdo)),
                new Transaction($pdo),
                new AllergyIntoleranceDao($pdo),
                new AuditEventDao($pdo),
                // Patient existant sélectionné : ses champs modifiés sont enregistrés et tracés un par un.
                new RecordEditService(
                    new PatientDao($pdo),
                    new EpisodeOfCareDao($pdo),
                    new AuditEventDao($pdo),
                    new Transaction($pdo),
                    new ReferenceDataDao($pdo)
                )
            ),
            referenceDataSets: $admissionReferenceData()
        );
        $controller->create();
    };

    // Recherche d'un patient existant à l'admission (IPP ou nom / prénom) et fiche à la sélection.
    $patientSearchController = static function (): PatientController {
        $pdo = Database::get();

        return new PatientController(
            patientSearchService: new PatientSearchService(new PatientDao($pdo), new AllergyIntoleranceDao($pdo))
        );
    };

    // AURA-14 : le dossier de séjour lit la base ; la connexion n'est ouverte que pour ces routes.
    // Il affiche aussi les prescriptions validées du séjour (CarePlanService).
    // AURA-13.2 : le même contrôleur sert l'ajout et le retrait d'allergies (POST) depuis le dossier.
    // Édition champ par champ du dossier (liste blanche, validation, concurrence optimiste, historique AuditEvent).
    $recordEditService = static function (): RecordEditService {
        $pdo = Database::get();

        return new RecordEditService(
            new PatientDao($pdo),
            new EpisodeOfCareDao($pdo),
            new AuditEventDao($pdo),
            new Transaction($pdo),
            new ReferenceDataDao($pdo)
        );
    };

    $episodeOfCareController = static function () use ($recordEditService): EpisodeOfCareController {
        $pdo = Database::get();
        $patientRecordService = new PatientRecordService(
            new EpisodeOfCareDao($pdo),
            new PatientDao($pdo),
            new AllergyIntoleranceDao($pdo),
            new SubstanceDao($pdo),
            new DateTimeImmutable('today'),
            new ReferenceDataDao($pdo)
        );

        return new EpisodeOfCareController(
            patientRecordService: $patientRecordService,
            carePlanService: new CarePlanService(
                new CarePlanDao($pdo),
                new ReferenceDataDao($pdo),
                $patientRecordService
            ),
            allergyIntoleranceService: new AllergyIntoleranceService(
                new EpisodeOfCareDao($pdo),
                new AllergyIntoleranceDao($pdo),
                new SubstanceDao($pdo)
            ),
            recordEditService: $recordEditService()
        );
    };

    // AURA-12.1 : la liste des patients lit la base ; même principe que le dossier de séjour.
    $listPatients = static function (): void {
        $pdo = Database::get();
        $controller = new PatientController(
            patientListService: new PatientListService(new PatientDao($pdo), new EpisodeOfCareDao($pdo))
        );
        $controller->list();
    };

    // Epic 4 : prescriptions d'un séjour (liste, écran B3, validation) ; même principe que le dossier.
    $carePlans = static function (): CarePlanController {
        $pdo = Database::get();
        return new CarePlanController(
            new CarePlanService(
                new CarePlanDao($pdo),
                new ReferenceDataDao($pdo),
                new PatientRecordService(
                    new EpisodeOfCareDao($pdo),
                    new PatientDao($pdo),
                    new AllergyIntoleranceDao($pdo),
                    new SubstanceDao($pdo),
                    new DateTimeImmutable('today'),
                    new ReferenceDataDao($pdo)
                )
            ),
            new PlanDefinitionService(new PlanDefinitionDao($pdo))
        );
    };

    // AURA-12.2 : modification d'un patient ; le même contrôleur sert le formulaire (GET) et la soumission (POST).
    $patientUpdateController = static fn (): PatientController => new PatientController(
        patientUpdateService: new PatientUpdateService(
            new PatientDao(Database::get()),
            new AdmissionService()
        )
    );
    // AURA-12.3 : suppression d'un patient ; le même contrôleur sert la confirmation (GET) et la suppression (POST).
    $patientDeletionController = static function (): PatientController {
        $pdo = Database::get();

        return new PatientController(
            patientDeletionService: new PatientDeletionService(
                new PatientDao($pdo),
                new EpisodeOfCareDao($pdo),
                new AppointmentDao($pdo),
                new AuditEventDao($pdo),
                new Transaction($pdo)
            )
        );
    };
    // AURA-15 : recherche d'un patient déjà connu par son IPP (JSON pour le formulaire d'admission).
    $searchByIpp = static function (): void {
        $pdo = Database::get();
        $controller = new PatientController(
            ippSearchService: new IppSearchService(new PatientDao($pdo), new AllergyIntoleranceDao($pdo))
        );
        $controller->searchByIpp();
    };

    // Agenda (AURA-24 à 29) : même service pour l'agenda d'un séjour et celui du service, avec
    // l'identité des patients et l'auteur des validations (historique).
    $agendaService = static fn (PDO $pdo): AgendaService => new AgendaService(
        new CarePlanDao($pdo),
        new AppointmentDao($pdo),
        new ActivityDefinitionDao($pdo),
        new EpisodeOfCareDao($pdo),
        new MedicationDao($pdo),
        new PatientDao($pdo),
        new AuditEventDao($pdo),
        new AllergyIntoleranceDao($pdo)
    );
    // Valider un soin (AURA-26) : tous les praticiens peuvent le faire, affichés « Prénom Nom (rôle) ».
    $appointmentService = static function (PDO $pdo): AppointmentService {
        $performers = [];
        $roles = [];
        foreach ((new ReferenceDataDao($pdo))->practitioners() as $id => $practitioner) {
            $role = $practitioner->getRole();
            $performers[$id] = $practitioner->getFullName() . ($role !== null ? ' (' . $role . ')' : '');
            $roles[$id] = $role;
        }

        return new AppointmentService(
            new AppointmentDao($pdo),
            new AuditEventDao($pdo),
            new Transaction($pdo),
            $performers,
            $roles
        );
    };

    // AURA-24 : agenda d'un séjour, bandeau identité + allergies en tête (RG2).
    $getAgenda = static function () use ($agendaService, $appointmentService): void {
        $pdo = Database::get();
        $controller = new EpisodeOfCareController(
            patientRecordService: new PatientRecordService(
                new EpisodeOfCareDao($pdo),
                new PatientDao($pdo),
                new AllergyIntoleranceDao($pdo),
                new SubstanceDao($pdo),
                new DateTimeImmutable('today')
            ),
            agendaService: $agendaService($pdo),
            episodeOfCareService: new EpisodeOfCareService(
                new EpisodeOfCareDao($pdo),
            ),
            appointmentService: $appointmentService($pdo),
        );
        $controller->getAgenda();
    };

    $getAgendaService = static function () use ($agendaService, $appointmentService): void {
        $pdo = Database::get();
        $controller = new ServiceController(
            $agendaService($pdo),
            (new ReferenceDataDao($pdo))->healthcareServices(),
            $appointmentService($pdo)
        );
        $controller->getAgenda();
    };

    // AURA-30 / AURA-31 : équipes de vacation et « Mon planning » par soignant.
    $teamController = static function () use ($agendaService, $appointmentService): TeamController {
        $pdo = Database::get();
        $appointments = $appointmentService($pdo);

        return new TeamController(
            new CareTeamService(new CareTeamDao($pdo), new Transaction($pdo)),
            new CareTeamDao($pdo),
            $agendaService($pdo),
            (new ReferenceDataDao($pdo))->healthcareServices(),
            $appointments->performers(),
            $appointments
        );
    };

    // AURA-26 / AURA-28 : valider un soin, le marquer non effectué, ou y ajouter une note.
    $careAction = static function () use ($appointmentService): void {
        (new CareController($appointmentService(Database::get())))->act();
    };

    // Le Router traite les requetes HTTP
    $router = new Router();
    $router->get('/patients', $listPatients);
    $router->post('/patients/create', $admitPatient);
    $router->get('/patients/modifier', static fn () => $patientUpdateController()->edit());
    $router->post('/patients/modifier', static fn () => $patientUpdateController()->update());
    $router->get('/patients/supprimer', static fn () => $patientDeletionController()->confirmDeletion());
    $router->post('/patients/supprimer', static fn () => $patientDeletionController()->delete());
    $router->get('/patients/recherche-ipp', $searchByIpp);
    $router->get('/patients/recherche', static fn () => $patientSearchController()->search());
    $router->get('/patients/fiche', static fn () => $patientSearchController()->record());
    $router->get('/sejour', static fn () => $episodeOfCareController()->show());
    $router->post('/sejour/allergies/ajouter', static fn () => $episodeOfCareController()->addAllergy());
    $router->post('/sejour/allergies/retirer', static fn () => $episodeOfCareController()->removeAllergy());
    $router->post('/sejour/champ/modifier', static fn () => (new RecordController($recordEditService()))->update());
    // Retours du 2026-11-02 : modification d'une partie entière du dossier et fin de séjour, validées par
    // un médecin (authentification factice en attendant AURA-18).
    $recordController = static function () use ($recordEditService): RecordController {
        $pdo = Database::get();

        return new RecordController(
            $recordEditService(),
            new RecordSectionService(
                new PatientDao($pdo),
                new EpisodeOfCareDao($pdo),
                new EpisodeOfCareService(new EpisodeOfCareDao($pdo)),
                $recordEditService(),
                new AuditEventDao($pdo),
                new Transaction($pdo),
                new ReferenceDataDao($pdo)
            )
        );
    };
    $router->post('/sejour/partie/modifier', static fn () => $recordController()->updatePart());
    $router->post('/sejour/cloturer', static fn () => $recordController()->endStay());
    $router->get('/care-plans', static fn () => $carePlans()->list());
    $router->get('/care-plans/new', static fn () => $carePlans()->newForm());
    $router->post('/care-plans/create', static fn () => $carePlans()->create());
    $router->post('/care-plans/validate', static fn () => $carePlans()->validate());
    $router->post('/care-plans/stop', static fn () => $carePlans()->stop());
    $router->get('/care-plans/protocol-lines', static fn () => $carePlans()->protocolLines());
    $router->get('/agenda-patient', $getAgenda);
    $router->post('/agenda-patient/soin', $careAction);
    $router->get('/equipes', static fn () => $teamController()->teams());
    $router->post('/equipes/creer', static fn () => $teamController()->create());
    $router->post('/equipes/retirer', static fn () => $teamController()->remove());
    $router->get('/mon-planning', static fn () => $teamController()->planning());
    $router->get('/agenda-service', $getAgendaService);


    $router->notFound(static fn() => View::error(404, 'Page introuvable.'));

    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (ConfigurationException $e) {
    // Configuration absente ou base injoignable : message clair, sans identifiants.
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $e->getMessage();
}
