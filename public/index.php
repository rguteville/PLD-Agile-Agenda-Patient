<?php

/**
 * Contrôleur frontal : toute requête HTTP passe par ce fichier (php -S … public/index.php en local,
 * réécriture vers index.php sur la VM).
 *
 * Dans l'ordre : fuseau horaire du service, fichiers statiques servis tels quels par le serveur de
 * développement, refus des saisies qui ne sont pas de l'UTF-8 valide, câblage des dépendances (une closure
 * par écran, qui construit DAO, services et contrôleur ; la connexion à la base n'est ouverte que par les
 * routes qui en ont besoin), table des routes (chemins exacts, Aura\Core\Router), puis filet d'erreurs :
 * configuration absente (message clair) ou erreur imprévue (journalisée, page ou JSON d'erreur sans trace).
 */

declare(strict_types=1);

use Aura\Controller\CareController;
use Aura\Controller\CarePlanController;
use Aura\Controller\EpisodeOfCareController;
use Aura\Controller\PatientController;
use Aura\Controller\RecordController;
use Aura\Controller\ServiceController;
use Aura\Controller\TeamController;
use Aura\Core\ConfigurationException;
use Aura\Core\Database;
use Aura\Core\Router;
use Aura\Core\View;
use Aura\Dao\ActivityDefinitionDao;
use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\AppointmentDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\CareTeamDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\MedicationDao;
use Aura\Dao\PatientDao;
use Aura\Dao\PlanDefinitionDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\SubstanceDao;
use Aura\Dao\Transaction;
use Aura\Service\AdmissionService;
use Aura\Service\AgendaService;
use Aura\Service\AllergyIntoleranceService;
use Aura\Service\AppointmentService;
use Aura\Service\CarePlanService;
use Aura\Service\CareTeamService;
use Aura\Service\EpisodeOfCareService;
use Aura\Service\IppSearchService;
use Aura\Service\PatientDeletionService;
use Aura\Service\PatientListService;
use Aura\Service\PatientRecordService;
use Aura\Service\PatientSearchService;
use Aura\Service\PatientUpdateService;
use Aura\Service\PlanDefinitionService;
use Aura\Service\RecordEditService;
use Aura\Service\RecordSectionService;

require dirname(__DIR__) . '/vendor/autoload.php';

// Heure du service (Lyon) quel que soit le réglage de PHP : sans cela, un PHP en UTC (Homebrew, Debian
// sans date.timezone) décale d'une à deux heures l'heure d'admission, la fin de séjour et les retards
// de l'agenda par rapport à l'heure affichée par le navigateur.
date_default_timezone_set('Europe/Paris');

// Chemin demandé, sans la query string.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Fichiers statiques existants (css, js, polices) : servis directement par le serveur de développement.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . $path)) {
    return false;
}

// Une saisie qui n'est pas de l'UTF-8 valide (octet %FF) ferait échouer l'écriture en base (erreur 1366).
// Refusée ici, une fois pour toutes les routes (les corps JSON invalides sont déjà refusés par json_decode).
$validUtf8 = static function (mixed $value) use (&$validUtf8): bool {
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (!$validUtf8((string) $key) || !$validUtf8($item)) {
                return false;
            }
        }

        return true;
    }

    return !is_string($value) || mb_check_encoding($value, 'UTF-8');
};
if (!$validUtf8($_GET) || !$validUtf8($_POST)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Requête invalide : caractères non reconnus.';
    return;
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

    // Page d'accueil : formulaire d'admission, affichable même sans base (hors routeur).
    if ($path === '/') {
        (new PatientController(referenceDataSets: $admissionReferenceData()))->showAdmissionForm();
        exit;
    }

    // L'admission écrit en base (patient + séjour + allergies) ; connexion ouverte pour cette route.
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

    // Le dossier de séjour lit la base ; la connexion n'est ouverte que pour ces routes.
    // Il affiche aussi les prescriptions validées du séjour (CarePlanService).
    // Le même contrôleur sert l'ajout et le retrait d'allergies (POST) depuis le dossier.
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
                $patientRecordService,
                new Transaction($pdo),
                new AuditEventDao($pdo)
            ),
            allergyIntoleranceService: new AllergyIntoleranceService(
                new EpisodeOfCareDao($pdo),
                new AllergyIntoleranceDao($pdo),
                new SubstanceDao($pdo),
                new PatientDao($pdo)
            ),
            recordEditService: $recordEditService()
        );
    };

    // La liste des patients lit la base ; même principe que le dossier de séjour.
    $listPatients = static function (): void {
        $pdo = Database::get();
        $controller = new PatientController(
            patientListService: new PatientListService(
                new PatientDao($pdo),
                new EpisodeOfCareDao($pdo),
                new ReferenceDataDao($pdo)
            )
        );
        $controller->list();
    };

    // Prescriptions d'un séjour (liste, écran B3, validation) ; même principe que le dossier.
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
                ),
                new Transaction($pdo),
                new AuditEventDao($pdo)
            ),
            new PlanDefinitionService(new PlanDefinitionDao($pdo))
        );
    };

    // Modification d'un patient ; le même contrôleur sert le formulaire (GET) et la soumission (POST).
    $patientUpdateController = static fn (): PatientController => new PatientController(
        patientUpdateService: new PatientUpdateService(
            new PatientDao(Database::get()),
            new AdmissionService()
        )
    );
    // Suppression d'un patient ; le même contrôleur sert la confirmation (GET) et la suppression (POST).
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
    // Recherche d'un patient déjà connu par son IPP (JSON pour le formulaire d'admission).
    $searchByIpp = static function (): void {
        $pdo = Database::get();
        $controller = new PatientController(
            ippSearchService: new IppSearchService(new PatientDao($pdo), new AllergyIntoleranceDao($pdo))
        );
        $controller->searchByIpp();
    };

    // Agenda : même service pour l'agenda d'un séjour et celui du service, avec
    // l'identité des patients et l'auteur des validations (historique).
    $agendaService = static function (PDO $pdo): AgendaService {
        // Noms des praticiens pour la carte de prescription (« Prescrit par », « Validation »).
        $names = [];
        foreach ((new ReferenceDataDao($pdo))->practitioners() as $id => $practitioner) {
            $role = $practitioner->getRole();
            $names[$id] = $practitioner->getFullName() . ($role !== null ? ' (' . $role . ')' : '');
        }

        return new AgendaService(
            new CarePlanDao($pdo),
            new AppointmentDao($pdo),
            new ActivityDefinitionDao($pdo),
            new EpisodeOfCareDao($pdo),
            new MedicationDao($pdo),
            new PatientDao($pdo),
            new AuditEventDao($pdo),
            new AllergyIntoleranceDao($pdo),
            $names
        );
    };
    // Valider un soin : tous les praticiens peuvent le faire, affichés « Prénom Nom (rôle) ».
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

    // Agenda d'un séjour, bandeau identité + allergies en tête (RG2).
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

    // Équipes de rotation et « Mon planning » par soignant.
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

    // Valider un soin, le marquer non effectué, ou y ajouter une note.
    $careAction = static function () use ($appointmentService): void {
        (new CareController($appointmentService(Database::get())))->act();
    };

    // Table des routes : méthode + chemin exact (identifiants en query string ou dans le corps du POST).
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
    // Modification d'une partie entière du dossier, fin de séjour et décès, validées par un médecin
    // (authentification factice : le mot de passe n'est pas vérifié).
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
                new ReferenceDataDao($pdo),
                appointmentDao: new AppointmentDao($pdo)
            )
        );
    };
    $router->post('/sejour/partie/modifier', static fn () => $recordController()->updatePart());
    // Édition en ligne d'un champ : même contrôleur que les parties, pour l'authentification factice.
    $router->post('/sejour/champ/modifier', static fn () => $recordController()->update());
    $router->post('/sejour/cloturer', static fn () => $recordController()->endStay());
    $router->post('/sejour/deces', static fn () => $recordController()->declareDeath());
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
} catch (Throwable $e) {
    // Filet de sécurité : une erreur imprévue (base, type, règle) est
    // journalisée pour l'équipe ; l'utilisateur reçoit une page ou un JSON d'erreur, jamais la trace (chemins,
    // SQL). Les erreurs prévues sont traitées avant, par les contrôleurs, avec un message précis.
    error_log('AURA : ' . $e::class . ' : ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
    $message = 'Une erreur inattendue est survenue : rien n\'a été enregistré si l\'action n\'a pas abouti. '
        . 'Rechargez la page puis réessayez.';
    if (headers_sent()) {
        echo '<p role="alert">' . View::e($message) . '</p>';
    } elseif (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        View::error(500, $message);
    }
}
