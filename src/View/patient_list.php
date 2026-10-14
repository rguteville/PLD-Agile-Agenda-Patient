<?php
/** @var \Aura\Model\Patient[] $patients */
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA - Patients</title>
</head>

<body>
    <h1>Patients</h1>
    <p><a href="index.php?action=inscription">Inscrire un patient</a></p>
    <table border="1">
        <tr><th>ID</th><th>IPP</th><th>Nom</th><th>Prénom</th><th>Naissance</th><th>Sexe</th></tr>
        <?php foreach ($patients as $p): ?>
            <tr>
                <td><?= $p->getIdPatient() ?></td>
                <td><?= htmlspecialchars((string) $p->getIpp()) ?></td>
                <td><?= htmlspecialchars($p->getNom()) ?></td>
                <td><?= htmlspecialchars($p->getPrenom()) ?></td>
                <td><?= $p->getDateNaissance()->format('d/m/Y') ?></td>
                <td><?= htmlspecialchars($p->getSexe()) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</body>

</html>
