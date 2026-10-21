<?php

use Aura\Core\View;

/**
 * Formulaire de modification d'un patient (AURA-12.2). Seuls les champs d'identité sont
 * modifiables ici : taille, poids et précautions appartiennent au séjour.
 *
 * @var int $idPatient
 * @var array<string, string> $valeurs indexées par nom de champ (pré-remplies ou ressaisies)
 * @var array<string, string> $erreurs indexées par nom de champ, [] si aucune
 */
$idPatient = $_GET['id'] ?? null;
$valeurs = $valeurs ?? [];
$erreurs = $erreurs ?? [];

$champs = [
    ['name' => 'lastname', 'label' => 'Nom', 'required' => true],
    ['name' => 'firstname', 'label' => 'Prénom', 'required' => true],
    ['name' => 'ipp', 'label' => 'IPP (identifiant permanent du patient)'],
    ['name' => 'birthDate', 'label' => 'Date de naissance', 'type' => 'date'],
    [
        'name' => 'gender',
        'label' => 'Sexe',
        'type' => 'radio',
        'options' => ['M' => 'M', 'F' => 'F']
    ]
];

foreach ($champs as &$champ) {
    $champ['value'] = $valeurs[$champ['name']] ?? '';
    $champ['error'] = $erreurs[$champ['name']] ?? '';
}
unset($champ);

?>

<?= View::render('components/form', [
    'id' => 'modification-form',
    'title' => 'Modifier le patient',
    'action' => '/patients/modifier?id=' . $idPatient,
    'submit' => 'Enregistrer',
    'fields' => $champs,
]);?>

<p><a href="/patients">Annuler</a></p>
