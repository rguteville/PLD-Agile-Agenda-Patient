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
    $afficherAgenda = static function (): void {
        $idSejour = isset($_GET['idSejour']) ? (int) $_GET['idSejour'] : 1;

        // Données fictives format message sami
        $donneesFictives = [
            'idSejour' => $idSejour,
            'careplans' => [
                [
                    'idCarePlan'       => 1,
                    'idEpisodeOfCare' => $idSejour,
                    'idPrescriber'    => 10,
                    'mode'            => 'standard',
                    'recordedAt'      => '2026-10-18 07:00:00',
                    'idValidator'     => 12,
                    'validatedAt'     => '2026-10-18 07:30:00',
                    
                    // Demandes d'actes / soins
                    'serviceRequests' => [
                        [
                            'idServiceRequest'     => 101,
                            'idCarePlan'           => 1,
                            'idActivityDefinition' => 'Lavement nez',
                            'startDate'            => '2026-10-18',
                            'endDate'              => '2026-10-20',
                            'frequencyHours'       => 24,
                            'timeConstraint'       => 'Matin',
                            'idStoppedBy'          => null,
                            'stoppedAt'            => null,
                            'appointments'         => [
                                [
                                    'idAppointment'       => 1,
                                    'idServiceRequest'    => 101,
                                    'idMedicationRequest' => null,
                                    'plannedTime'         => '2026-10-18 08:00:00',
                                    'status'              => 'completed',
                                    'timeOfCompletion'    => '2026-10-18 08:05:00',
                                    'timeRecorded'        => '2026-10-18 08:06:00',
                                    'comment'             => 'Bien toléré par le patient'
                                ]
                            ]
                        ],
                        [
                            'idServiceRequest'     => 101,
                            'idCarePlan'           => 1,
                            'idActivityDefinition' => 'Changement de rampe/tubulure ART',
                            'startDate'            => '2026-10-19',
                            'endDate'              => '2026-10-20',
                            'frequencyHours'       => 24,
                            'timeConstraint'       => 'Matin',
                            'idStoppedBy'          => null,
                            'stoppedAt'            => null,
                            'appointments'         => [
                                [
                                    'idAppointment'       => 1,
                                    'idServiceRequest'    => 101,
                                    'idMedicationRequest' => null,
                                    'plannedTime'         => '2026-10-19 08:00:00',
                                    'status'              => 'completed',
                                    'timeOfCompletion'    => null,
                                    'timeRecorded'        => null,
                                    'comment'             => null
                                ]
                            ]
                        ]
                    ],

                    // Demandes de médicaments
                    'medicationRequest' => [
                        [
                            'idMedicationRequest'  => 201,
                            'idCarePlan'           => 1,
                            'idActivityDefinition' => 'Prise d’antidouleur',
                            'idMedication'         => 'Doliprane 1g',
                            'dose'                 => '1 comprimé',
                            'route'                => 'Orale',
                            'startDate'            => '2026-10-18',
                            'endDate'              => '2026-10-18',
                            'frequencyHours'       => 12,
                            'timeConstraint'       => null,
                            'idStoppedBy'          => null,
                            'stoppedAt'            => null,
                            'appointments'         => [
                                [
                                    'idAppointment'       => 2,
                                    'idServiceRequest'    => null,
                                    'idMedicationRequest' => 201,
                                    'plannedTime'         => '2026-10-18 12:00:00',
                                    'status'              => 'planned',
                                    'timeOfCompletion'    => null,
                                    'timeRecorded'        => null,
                                    'comment'             => 'À prendre pendant le repas'
                                ],
                                [
                                    'idAppointment'       => 3,
                                    'idServiceRequest'    => null,
                                    'idMedicationRequest' => 201,
                                    'plannedTime'         => '2026-10-18 20:00:00',
                                    'status'              => 'planned',
                                    'timeOfCompletion'    => null,
                                    'timeRecorded'        => null,
                                    'comment'             => null
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        View::page('sejour/agenda-patient', $donneesFictives, 'AURA - Agenda Patient');
    };
    // Le Router traite les requetes HTTP
    $router = new Router();
    $router->get('/patients', $listerPatients);
    $router->post('/patients/create', $admettrePatient);
    $router->get('/sejour', $afficherSejour);
    $router->get('/agenda-patient', $afficherAgenda);
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
