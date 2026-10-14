<?php

// Page de test PERSONNELLE du back (inscription + séjour). Ne pas committer.
// Lancer : php -S localhost:8000   puis ouvrir http://localhost:8000/test_formulaire.php

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
$service = new PatientService(new PatientDao($pdo), $sejourService, new Transaction($pdo));

$h = fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$saisie = [];
$message = null;
$erreur = null;
$existant = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'inscrire') {
            $saisie = $_POST;
            if (isset($_POST['sous_x'])) {
                $saisie['nom'] = 'X';
                $saisie['prenom'] = 'X';
            }
            // Étape 1 : le patient a-t-il déjà fait un séjour dans le service ?
            $existant = $service->rechercherPatientExistant($saisie);
            if ($existant === null) {
                // Étape 2b : nouveau dossier + séjour
                $sejour = $service->inscrirePatient($saisie);
                $message = "Nouveau patient inscrit (id {$sejour->getIdPatient()}), séjour n° {$sejour->getNumSejour()}"
                    . " ouvert le {$sejour->getDateAdmission()->format('d/m/Y à H:i')}";
                $saisie = [];
            }
        } elseif ($action === 'admettre') {
            // Étape 2a : le personnel a validé le lien avec le dossier existant
            $sejour = $service->admettrePatientExistant(
                (int) $_POST['id_patient'],
                $_POST['num_lit'] ?? null,
                $_POST['num_box'] ?? null
            );
            $message = "Nouveau séjour n° {$sejour->getNumSejour()} ouvert pour le patient {$sejour->getIdPatient()}";
        } elseif ($action === 'sortie') {
            $sejour = $sejourService->cloturerSejour((int) $_POST['id_sejour']);
            $message = "Sortie enregistrée pour le séjour n° {$sejour->getNumSejour()}";
        } elseif ($action === 'emplacement') {
            $sejour = $sejourService->changerEmplacement(
                (int) $_POST['id_sejour'],
                $_POST['num_lit'] ?? null,
                $_POST['num_box'] ?? null
            );
            $message = "Séjour n° {$sejour->getNumSejour()} : lit {$sejour->getNumLit()}, box {$sejour->getNumBox()}";
        }
    } catch (InvalidArgumentException $e) {
        $erreur = $e->getMessage();
    }
}

$v = fn(string $champ): string => $h($saisie[$champ] ?? '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Test back – inscription et séjour</title>
    <style>
        body { font-family: sans-serif; max-width: 1000px; margin: 2em auto; }
        label { display: inline-block; width: 160px; }
        .ok { color: green; } .ko { color: red; }
        .alerte { background: #fff3cd; padding: 1em; border: 1px solid #e0c060; }
        table { border-collapse: collapse; width: 100%; } td, th { border: 1px solid #ccc; padding: 4px; }
        .x { background: #eef; }
        input.court { width: 60px; }
    </style>
</head>
<body>
<h1>Test : inscription d'un patient et séjour</h1>

<?php if ($message) : ?><p class="ok"><?= $h($message) ?></p><?php endif; ?>
<?php if ($erreur) : ?><p class="ko">Refusé : <?= $h($erreur) ?></p><?php endif; ?>

<?php if ($existant !== null) : ?>
    <div class="alerte">
        <strong>Patient déjà venu dans le service :</strong>
        <?= $h($existant->getNom()) ?> <?= $h($existant->getPrenom()) ?>,
        né(e) le <?= $existant->getDateNaissance()?->format('d/m/Y') ?>, IPP <?= $h($existant->getIpp()) ?>
        <ul>
            <?php foreach ($service->getSejoursPatient($existant->getIdPatient()) as $s) : ?>
                <li>Séjour n° <?= $h($s->getNumSejour()) ?> du <?= $s->getDateAdmission()->format('d/m/Y H:i') ?>
                    (<?= $s->getDateSortie() ? 'sorti le ' . $s->getDateSortie()->format('d/m/Y H:i') : 'en cours' ?>)</li>
            <?php endforeach; ?>
        </ul>
        Lier la prise en charge à ce dossier ?
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="admettre">
            <input type="hidden" name="id_patient" value="<?= $existant->getIdPatient() ?>">
            <input type="hidden" name="num_lit" value="<?= $v('num_lit') ?>">
            <input type="hidden" name="num_box" value="<?= $v('num_box') ?>">
            <button>Oui, ouvrir un nouveau séjour</button>
        </form>
        <a href="test_formulaire.php">Annuler</a>
    </div>
<?php endif; ?>

<h2>Formulaire d'admission</h2>
<form method="post">
    <input type="hidden" name="action" value="inscrire">
    <p><label>Patient sous X</label><input type="checkbox" name="sous_x"> identité inconnue (nom/prénom ignorés)</p>
    <p><label>Nom *</label><input name="nom" value="<?= $v('nom') ?>"></p>
    <p><label>Prénom *</label><input name="prenom" value="<?= $v('prenom') ?>"></p>
    <p><label>Date de naissance *</label><input type="date" name="date_naissance" value="<?= $v('date_naissance') ?>">
        (facultative sous X)</p>
    <p><label>Sexe *</label>
        <select name="sexe">
            <option value="M" <?= ($saisie['sexe'] ?? '') === 'M' ? 'selected' : '' ?>>M</option>
            <option value="F" <?= ($saisie['sexe'] ?? '') === 'F' ? 'selected' : '' ?>>F</option>
        </select></p>
    <p><label>IPP</label><input name="ipp" value="<?= $v('ipp') ?>"> (6 à 9 chiffres, interdit sous X)</p>
    <p><label>Téléphone</label><input name="telephone" value="<?= $v('telephone') ?>"></p>
    <p><label>Email</label><input name="email" value="<?= $v('email') ?>"></p>
    <p><label>Lit / Box</label>
        <input class="court" name="num_lit" value="<?= $v('num_lit') ?>" placeholder="lit">
        <input class="court" name="num_box" value="<?= $v('num_box') ?>" placeholder="box"> (facultatifs)</p>
    <button>Inscrire</button>
</form>
<p><small>Les contrôles du navigateur sont volontairement absents : c'est le back qui doit refuser les erreurs.</small></p>

<h2>Patients présents dans le service</h2>
<table>
    <tr><th>N° séjour</th><th>Patient</th><th>Admis le</th><th>Lit / Box</th><th>Actions</th></tr>
    <?php foreach ($sejourService->getSejoursEnCours() as $s) : ?>
        <?php $p = $service->getPatient($s->getIdPatient()); ?>
        <tr class="<?= $p->estAnonyme() ? 'x' : '' ?>">
            <td><?= $h($s->getNumSejour()) ?></td>
            <td><?= $h($p->getNom()) ?> <?= $h($p->getPrenom()) ?> (<?= $h($p->getSexe()) ?>)</td>
            <td><?= $s->getDateAdmission()->format('d/m/Y H:i') ?></td>
            <td>
                <form method="post">
                    <input type="hidden" name="action" value="emplacement">
                    <input type="hidden" name="id_sejour" value="<?= $s->getIdSejour() ?>">
                    <input class="court" name="num_lit" value="<?= $h($s->getNumLit()) ?>" placeholder="lit">
                    <input class="court" name="num_box" value="<?= $h($s->getNumBox()) ?>" placeholder="box">
                    <button>Changer</button>
                </form>
            </td>
            <td>
                <form method="post">
                    <input type="hidden" name="action" value="sortie">
                    <input type="hidden" name="id_sejour" value="<?= $s->getIdSejour() ?>">
                    <button>Sortie</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Tous les patients et leurs séjours</h2>
<table>
    <tr><th>Id</th><th>Patient</th><th>Sexe</th><th>Naissance</th><th>IPP</th><th>Séjours</th></tr>
    <?php foreach ($service->getAllPatients() as $p) : ?>
        <tr class="<?= $p->estAnonyme() ? 'x' : '' ?>">
            <td><?= $p->getIdPatient() ?></td>
            <td><?= $h($p->getNom()) ?> <?= $h($p->getPrenom()) ?></td>
            <td><?= $h($p->getSexe()) ?></td>
            <td><?= $p->getDateNaissance()?->format('d/m/Y') ?? '?' ?></td>
            <td><?= $h($p->getIpp()) ?></td>
            <td>
                <?php foreach ($service->getSejoursPatient($p->getIdPatient()) as $s) : ?>
                    n° <?= $h($s->getNumSejour()) ?> du <?= $s->getDateAdmission()->format('d/m/Y H:i') ?>
                    <?= $s->estEnCours() ? '(en cours)' : '→ ' . $s->getDateSortie()->format('d/m/Y H:i') ?><br>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
</body>
</html>
