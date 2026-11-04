<?php

use Aura\Core\View;

/**
 * Formulaire de modification d'un patient. Seuls les champs d'identité sont
 * modifiables ici : taille, poids et précautions appartiennent au séjour.
 * Rendue par PatientController::edit() (GET /patients/modifier?id=…) et, en 422 après un refus, par
 * PatientController::update() (POST /patients/modifier?id=…). Script : date-input.js seulement ; form.js
 * n'est pas chargé, le formulaire data-ajax part donc en POST classique.
 *
 * @var int $idPatient fourni par le contrôleur, mais la vue le relit dans $_GET['id'] (chaîne ou null)
 * @var array<string, string> $values indexées par nom de champ (pré-remplies ou ressaisies), [] par défaut
 * @var array<string, string> $errors indexées par nom de champ, [] par défaut
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
    'csrfToken' => $csrfToken ?? null,
    'fields' => $formFields,
]);?>

<p class="page-actions">
    <a href="/patients">Annuler</a>
    <a class="patient-edit__delete" href="/patients/supprimer?id=<?= View::e($idPatient) ?>">Supprimer le patient</a>
</p>
