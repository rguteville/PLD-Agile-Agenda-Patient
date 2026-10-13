<?php use Aura\Core\View; ?>

<?= View::render('components/form', [
    'id'     => 'admission-form',
    'title'  => "Formulaire d'admission",
    'action' => '/patients/create',
    'submit' => 'Enregistrer',
    'fields' => [
        ['name' => 'firstname',     'label' => "Nom et prénom d'usage",       'required' => true],
        ['name' => 'lastname', 'label' => 'Nom et prénom de naissance'],
        ['name' => 'ipp',          'label' => 'IP permanent'],
        ['name' => 'birthDate','label' => 'Date de naissance', 'type' => 'date'],
        ['name' => 'gender',         'label' => 'Sexe', 'type' => 'radio',
         'options' => ['H' => 'H', 'F' => 'F']],
    ],
]) ?>