<?php

use Aura\Core\View;
use Aura\Service\AdmissionService;

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
    // Mesures à l'admission (AURA-10.1), enregistrées sur le séjour. Bornes = colonnes DECIMAL(5,2) ;
    // le contrôle serveur (AdmissionService::valider()) reste la garantie.
    [
        'name' => 'taille',
        'label' => 'Taille (cm)',
        'type' => 'number',
        'attributes' => ['min' => '0.01', 'max' => '999.99', 'step' => '0.01', 'inputmode' => 'decimal'],
    ],
    [
        'name' => 'poids',
        'label' => 'Poids (kg)',
        'type' => 'number',
        'attributes' => ['min' => '0.01', 'max' => '999.99', 'step' => '0.01', 'inputmode' => 'decimal'],
    ],
    [
        'name' => 'precautions',
        'label' => 'Précautions',
        'type' => 'textarea',
        'attributes' => [
            'rows' => '3',
            'maxlength' => (string) AdmissionService::PRECAUTIONS_LONGUEUR_MAX,
            'placeholder' => 'Isolement, risque de chute…',
        ],
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
    'submit' => 'Admettre',
    'fields' => $champs,
    'error' => $erreurFormulaire,
]);
