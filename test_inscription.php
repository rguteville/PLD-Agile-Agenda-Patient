<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

use Aura\Dao\PatientDao;
use Aura\Dao\SejourDao;
use Aura\Dao\Transaction;
use Aura\Service\PatientService;
use Aura\Service\SejourService;

$db = (require __DIR__ . '/config/config.php')['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
    $db['user'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$service = new PatientService(new PatientDao($pdo), new SejourService(new SejourDao($pdo)), new Transaction($pdo));

$formulaire = [
    'nom' => 'Durand', 'prenom' => 'Paul', 'date_naissance' => '1975-09-30',
    'sexe' => 'M', 'ipp' => '112233', 'telephone' => '0601020304', 'email' => 'paul@test.fr',
];

// 1. Le patient a-t-il déjà fait un séjour dans le service ?
$existant = $service->rechercherPatientExistant($formulaire);

if ($existant !== null) {
    // 2a. Oui : le personnel valide le lien, on ouvre un nouveau séjour sur son dossier
    echo "Patient déjà venu : {$existant->getNom()} {$existant->getPrenom()} (id {$existant->getIdPatient()})\n";
    foreach ($service->getSejoursPatient($existant->getIdPatient()) as $s) {
        $sortie = $s->getDateSortie()?->format('d/m/Y') ?? 'en cours';
        echo "  - séjour n° {$s->getNumSejour()} du {$s->getDateAdmission()->format('d/m/Y')} ({$sortie})\n";
    }
    try {
        $sejour = $service->admettrePatientExistant($existant->getIdPatient());
        echo "Nouveau séjour n° {$sejour->getNumSejour()}\n";
    } catch (InvalidArgumentException $e) {
        echo "Refusé : {$e->getMessage()}\n";
    }
} else {
    // 2b. Non : nouveau dossier patient + séjour
    $sejour = $service->inscrirePatient($formulaire);
    echo "Patient inscrit (id {$sejour->getIdPatient()}), séjour n° {$sejour->getNumSejour()}"
        . " ouvert le {$sejour->getDateAdmission()->format('d/m/Y H:i')}\n";
}

// 3. Patient anonyme « sous X » : pas d'IPP, date de naissance facultative
$x = $service->inscrirePatient(['nom' => 'X', 'prenom' => 'X', 'sexe' => 'F']);
echo "Patient sous X inscrit (id {$x->getIdPatient()}), séjour n° {$x->getNumSejour()}\n";
