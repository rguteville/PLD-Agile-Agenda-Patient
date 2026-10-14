<?php

// Test PERSONNEL de la gestion du séjour (ne pas committer).
// Lancer : php test_sejour.php
// Le script crée ses propres patients de test puis les supprime à la fin.

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
$sejourService = new SejourService(new SejourDao($pdo));
$patientService = new PatientService(new PatientDao($pdo), $sejourService, new Transaction($pdo));

// Pour le nettoyage final : on retient les plus grands id avant le test
$maxPatient = (int) $pdo->query('SELECT COALESCE(MAX(CAST(id_patient AS UNSIGNED)), 0) FROM Patient')->fetchColumn();
$maxSejour = (int) $pdo->query('SELECT COALESCE(MAX(CAST(id_sejour AS UNSIGNED)), 0) FROM Sejour')->fetchColumn();

// Petits outils d'affichage
$verifier = function (bool $condition, string $libelle): void {
    echo ($condition ? '  OK    ' : '  ÉCHEC ') . $libelle . "\n";
};
$doitEtreRefuse = function (string $libelle, callable $action): void {
    try {
        $action();
        echo "  ÉCHEC {$libelle} : accepté alors qu'il devait être refusé\n";
    } catch (InvalidArgumentException $e) {
        echo "  OK    {$libelle} → refusé : {$e->getMessage()}\n";
    }
};

try {
    echo "1. Ouverture du séjour à l'inscription\n";
    $s1 = $patientService->inscrirePatient([
        'nom' => 'Testsejour', 'prenom' => 'Alice', 'date_naissance' => '1960-01-01', 'sexe' => 'F',
        'num_lit' => 'L99', 'num_box' => 'B99',
    ]);
    $verifier($s1->getIdSejour() !== null, "séjour créé : n° {$s1->getNumSejour()}");
    $verifier(strlen($s1->getNumSejour()) === 9, 'numéro de séjour sur 9 chiffres');
    $verifier($s1->getDateAdmission()->format('Y-m-d') === date('Y-m-d'), 'date de prise en charge = aujourd\'hui');
    $verifier($s1->estEnCours(), 'séjour en cours (pas de date de sortie)');
    $verifier($s1->getNumLit() === 'L99' && $s1->getNumBox() === 'B99', 'lit L99 et box B99 enregistrés');

    echo "\n2. Un lit / box ne peut accueillir qu'un patient à la fois\n";
    $nbAvant = (int) $pdo->query('SELECT COUNT(*) FROM Patient')->fetchColumn();
    $doitEtreRefuse('lit L99 déjà occupé', fn() => $patientService->inscrirePatient([
        'nom' => 'Testsejour', 'prenom' => 'Bob', 'date_naissance' => '1970-01-01', 'sexe' => 'M', 'num_lit' => 'L99',
    ]));
    $nbApres = (int) $pdo->query('SELECT COUNT(*) FROM Patient')->fetchColumn();
    $verifier($nbAvant === $nbApres, 'Bob n\'a PAS été créé (patient et séjour annulés ensemble)');

    echo "\n3. Changement de lit / box\n";
    $s2 = $patientService->inscrirePatient(['nom' => 'X', 'prenom' => 'X', 'sexe' => 'M', 'num_box' => 'B98']);
    $doitEtreRefuse('déplacer le patient X dans le lit L99 (occupé)', fn() => $sejourService->changerEmplacement(
        $s2->getIdSejour(),
        'L99',
        'B98'
    ));
    $s2 = $sejourService->changerEmplacement($s2->getIdSejour(), 'L98', 'B98');
    $verifier($s2->getNumLit() === 'L98', 'patient X déplacé dans le lit L98');

    echo "\n4. Liste des patients présents\n";
    $presents = array_map(fn($s) => $s->getIdSejour(), $sejourService->getSejoursEnCours());
    $verifier(in_array($s1->getIdSejour(), $presents, true), "Alice présente (séjour n° {$s1->getNumSejour()})");
    $verifier(in_array($s2->getIdSejour(), $presents, true), "patient X présent (séjour n° {$s2->getNumSejour()})");

    echo "\n5. Sortie du patient\n";
    $doitEtreRefuse('sortie datée avant l\'admission', fn() => $sejourService->cloturerSejour(
        $s1->getIdSejour(),
        new DateTimeImmutable('2000-01-01')
    ));
    $s1 = $sejourService->cloturerSejour($s1->getIdSejour());
    $verifier(!$s1->estEnCours(), 'sortie enregistrée le ' . $s1->getDateSortie()->format('d/m/Y à H:i'));
    $doitEtreRefuse('deuxième sortie du même séjour', fn() => $sejourService->cloturerSejour($s1->getIdSejour()));
    $presents = array_map(fn($s) => $s->getIdSejour(), $sejourService->getSejoursEnCours());
    $verifier(!in_array($s1->getIdSejour(), $presents, true), 'Alice n\'est plus dans les présents');

    echo "\n6. Retour du patient : nouveau séjour, consultation des séjours précédents\n";
    $existant = $patientService->rechercherPatientExistant([
        'nom' => 'Testsejour', 'prenom' => 'Alice', 'date_naissance' => '1960-01-01',
    ]);
    $verifier($existant !== null, 'Alice reconnue comme déjà venue dans le service');
    $s3 = $patientService->admettrePatientExistant($existant->getIdPatient(), 'L99', null);
    $verifier($s3->getNumLit() === 'L99', "lit L99 libéré par la sortie → nouveau séjour n° {$s3->getNumSejour()}");
    $doitEtreRefuse('troisième séjour alors que le second est en cours', fn() => $patientService->admettrePatientExistant(
        $existant->getIdPatient()
    ));
    echo "  Historique d'Alice :\n";
    foreach ($sejourService->getSejoursPatient($existant->getIdPatient()) as $s) {
        $etat = $s->estEnCours() ? 'en cours' : 'sortie le ' . $s->getDateSortie()->format('d/m/Y H:i');
        echo "    - séjour n° {$s->getNumSejour()} admis le {$s->getDateAdmission()->format('d/m/Y H:i')} ({$etat})\n";
    }
} finally {
    $pdo->exec("DELETE FROM Sejour WHERE CAST(id_sejour AS UNSIGNED) > $maxSejour");
    $pdo->exec("DELETE FROM Patient WHERE CAST(id_patient AS UNSIGNED) > $maxPatient");
    echo "\nNettoyage : données de test supprimées\n";
}
