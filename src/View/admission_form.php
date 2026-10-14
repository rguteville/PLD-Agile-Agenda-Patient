<?php
/** @var string|null $erreur */
/** @var int|null $idCree */
/** @var array<string, mixed> $saisie */
$v = fn(string $champ): string => htmlspecialchars((string) ($saisie[$champ] ?? ''), ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA - Inscription patient</title>
</head>

<body>
    <h1>Inscription d'un patient</h1>

    <?php if ($erreur !== null): ?>
        <p style="color: red;"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>
    <?php if ($idCree !== null): ?>
        <p style="color: green;">Patient inscrit (identifiant <?= $idCree ?>).</p>
    <?php endif; ?>

    <form action="index.php?action=inscription" method="post">
        <label>Nom *</label>
        <input type="text" name="nom" maxlength="50" required value="<?= $v('nom') ?>"><br>
        <label>Prénom *</label>
        <input type="text" name="prenom" maxlength="50" required value="<?= $v('prenom') ?>"><br>
        <label>Date de naissance *</label>
        <input type="date" name="date_naissance" required value="<?= $v('date_naissance') ?>"><br>
        <label>Sexe *</label>
        <select name="sexe" required>
            <option value="M" <?= ($saisie['sexe'] ?? '') === 'M' ? 'selected' : '' ?>>M</option>
            <option value="F" <?= ($saisie['sexe'] ?? '') === 'F' ? 'selected' : '' ?>>F</option>
        </select><br>
        <label>IPP</label>
        <input type="text" name="ipp" pattern="\d{6,9}" value="<?= $v('ipp') ?>"><br>
        <label>Téléphone</label>
        <input type="tel" name="telephone" pattern="\d{10}" value="<?= $v('telephone') ?>"><br>
        <label>Email</label>
        <input type="email" name="email" maxlength="100" value="<?= $v('email') ?>"><br>
        <button type="submit">Inscrire</button>
    </form>

    <p><a href="index.php">Liste des patients</a></p>
</body>

</html>
