<?php

use Aura\Core\View;

/**
 * Formulaire de modification d'un patient (AURA-12.2). Seuls les champs d'identité sont
 * modifiables ici : taille, poids et précautions appartiennent au séjour.
 *
 * @var int $idPatient
 * @var array<string, string> $values indexées par nom de champ (pré-remplies ou ressaisies)
 * @var array<string, string> $errors indexées par nom de champ, [] si aucune
 */
$idPatient = $_GET['id'] ?? null;
$values = $values ?? [];
$errors = $errors ?? [];

$formFields = [
    ['name' => 'lastname', 'label' => 'Nom', 'required' => true, 'placeholder' => 'DUPONT'],
    ['name' => 'firstname', 'label' => 'Prénom', 'required' => true, 'placeholder' => 'Marie'],
    ['name' => 'ipp', 'label' => 'IPP (identifiant permanent du patient)', 'placeholder' => '100000123'],
    ['name' => 'birthDate', 'label' => 'Date de naissance', 'type' => 'date'],
    [
        'name' => 'gender',
        'label' => 'Sexe',
        'type' => 'radio',
        'options' => ['M' => 'M', 'F' => 'F']
    ]
];

foreach ($formFields as &$formField) {
    $formField['value'] = $values[$formField['name']] ?? '';
    $formField['error'] = $errors[$formField['name']] ?? '';
}
unset($formField);

?>

<?= View::render('components/form', [
    'id' => 'modification-form',
    'title' => 'Modifier le patient',
    'action' => '/patients/modifier?id=' . $idPatient,
    'submit' => 'Enregistrer',
    'fields' => $formFields,
]);?>

<p><a href="/patients">Annuler</a></p>
