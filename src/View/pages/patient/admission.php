<?php

use Aura\Core\View;

// Après un refus serveur (AURA-10.2), le contrôleur fournit les valeurs saisies
// et les erreurs indexées par nom de champ ; à l'affichage initial, rien n'est fourni.
$valeurs = $valeurs ?? [];
$erreurs = $erreurs ?? [];
$erreurFormulaire = $erreurFormulaire ?? '';

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
    ],
];

foreach ($champs as &$champ) {
    $champ['value'] = $valeurs[$champ['name']] ?? '';
    $champ['error'] = $erreurs[$champ['name']] ?? '';
}
unset($champ);

?>

<?= View::render('components/form', [
    'id' => 'admission-form',
    'title' => "Formulaire d'admission",
    'action' => '/patients/create',
    'submit' => 'Enregistrer',
    'fields' => $champs,
    'error' => $erreurFormulaire,
]);
