<?php

use Aura\Core\View;
use Aura\Domain\AllergyIntolerance;
use Aura\Domain\RecordFields;
use Aura\Service\AdmissionService;

/**
 * Formulaire d'admission (AURA-10, AURA-15) étendu aux champs du SI réel (PR « admission-champs-complets »).
 * L'essentiel reste visible en haut (identité, IPP, motif, service, allergies) ; le reste est réparti en
 * sections repliées par défaut (RecordFields::SECTIONS), toutes facultatives.
 * Patient connu sélectionné : son groupe sanguin déjà renseigné n'est pas modifiable ici (donnée vitale,
 * validée par un médecin dans le dossier) ; inconnu, il peut être complété.
 * Rendue par PatientController::showAdmissionForm() (GET /) et, sans JavaScript, réaffichée en 422 par
 * PatientController::create() (POST /patients/create). Scripts : form.js, admission-search.js,
 * form-sections.js, allergy-picker.js, date-input.js.
 *
 * @var array<string, mixed> $values valeurs saisies après un refus serveur, [] par défaut
 * @var array<string, string> $errors erreurs indexées par nom de champ, [] par défaut
 * @var string $formError message hors champ (admission refusée), '' par défaut
 * @var array<string, array<string, string>> $referenceDataSets 'services', 'doctors' et 'substances' (vides sans
 *      base), [] par défaut
 */
$values = $values ?? [];
$errors = $errors ?? [];
$formError = $formError ?? '';
$referenceDataSets = $referenceDataSets ?? [];
$services = $referenceDataSets['services'] ?? [];
$doctors = $referenceDataSets['doctors'] ?? [];
$substances = $referenceDataSets['substances'] ?? [];

// Champ du catalogue → champ du composant field (même nom des deux côtés).
$catalogField = static function (string $name, array $spec): array {
    $formField = ['name' => $name, 'label' => $spec['label'], 'type' => $spec['type']];
    if (isset($spec['help'])) {
        $formField['help'] = $spec['help'];
    }
    if (isset($spec['placeholder'])) {
        $formField['placeholder'] = $spec['placeholder'];
    }
    if ($spec['type'] === 'select') {
        $formField['options'] = $spec['options'];
        $formField['empty'] = 'Non renseigné';
    } elseif ($spec['type'] === 'textarea') {
        $formField['attributes'] = ['rows' => '3', 'maxlength' => (string) $spec['max']];
    } elseif (isset($spec['max'])) {
        $formField['attributes'] = ['maxlength' => (string) $spec['max']];
    }

    return $formField;
};
// Champs du catalogue appartenant à une section, patient puis séjour.
$sectionFields = static function (string $section) use ($catalogField): array {
    $formFields = [];
    foreach ([RecordFields::admissionPatientFields(), RecordFields::EPISODE] as $catalogue) {
        foreach ($catalogue as $name => $spec) {
            if ($spec['section'] === $section) {
                $formFields[] = $catalogField($name, $spec);
            }
        }
    }

    return $formFields;
};

$search = [
    // Recherche d'un patient connu (identitovigilance) : un seul champ en tête, qui reconnaît un IPP
    // (6 à 9 chiffres) ou des mots du nom et du prénom (ordre libre). Recherche automatique à l'arrêt de
    // la frappe ; la sélection demande une confirmation, puis l'identité s'affiche en carte en lecture
    // seule et la fiche pré-remplit le formulaire (public/js/admission-search.js).
    [
        'name' => 'patientSearch',
        'label' => 'Patient connu ? IPP, nom ou prénom',
        'status' => 'patient-search-status',
        'listbox' => 'patient-suggestions',
        'placeholder' => '100000001 ou simpson marge',
        'attributes' => [
            'autocomplete' => 'off', 'maxlength' => '100',
            'role' => 'combobox', 'aria-autocomplete' => 'list', 'aria-expanded' => 'false',
            'aria-controls' => 'patient-suggestions',
        ],
    ],
    ['name' => 'idPatient', 'type' => 'hidden'],
    ['name' => 'searchMode', 'type' => 'hidden'],
];

// Colonne de gauche : identité, admission et allergies ; à droite, les sections complémentaires.
$essentials = [
    ['name' => 'identity', 'label' => 'Identité', 'type' => 'heading'],
    ['name' => 'lastname', 'label' => 'Nom', 'required' => true, 'placeholder' => 'DUPONT'],
    ['name' => 'firstname', 'label' => 'Prénom', 'required' => true, 'placeholder' => 'Marie'],
    ['name' => 'birthDate', 'label' => 'Date de naissance', 'type' => 'date'],
    [
        'name' => 'ipp',
        'label' => 'IPP (identifiant permanent du patient)',
        'help' => 'Nouveau patient : laissez vide, un IPP est attribué à l\'admission.',
        'placeholder' => '100000123',
        'attributes' => ['inputmode' => 'numeric', 'autocomplete' => 'off'],
    ],
    [
        'name' => 'gender',
        'label' => 'Sexe',
        'type' => 'radio',
        'options' => ['M' => 'M', 'F' => 'F']
    ],
    $catalogField('bloodType', RecordFields::PATIENT['bloodType']),
    $catalogField('admissionReason', RecordFields::EPISODE['admissionReason']),
    [
        'name' => 'idHealthcareService',
        'label' => 'Service hospitalier',
        'type' => 'select',
        'options' => $services,
        'empty' => $services === [] ? 'Référentiel indisponible' : 'Non renseigné',
    ],
    // Médecin référent (responsable de soin, retours des médecins du 2026-11-03) : le médecin de jour qui
    // admet le patient. Facultatif : admis pendant une garde, le patient reste « à désigner » et un médecin
    // de jour se désigne ensuite depuis le dossier. Le serveur vérifie que c'est un médecin de la liste.
    [
        'name' => 'idReferringPractitioner',
        'label' => 'Médecin référent',
        'type' => 'select',
        'options' => $doctors,
        'empty' => 'À désigner',
        'help' => 'Le médecin de jour qui prend le patient en charge. Admission pendant une garde : laissez '
            . '« À désigner », un médecin de jour le reprendra depuis le dossier du séjour.',
    ],
    [
        'name' => 'allergies',
        'label' => 'Allergies connues (référentiel)',
        'type' => 'allergy-picker',
        'options' => $substances,
        'criticalities' => is_array($values['allergyCriticality'] ?? null) ? $values['allergyCriticality'] : [],
        'criticalityOptions' => AllergyIntolerance::CRITICALITIES,
        'emptyMessage' => 'Référentiel des allergènes indisponible : les allergies pourront être saisies '
            . 'depuis le dossier du séjour.',
        'help' => 'Cochez chaque allergène connu et indiquez sa criticité. Un patient déjà connu conserve '
            . 'ses allergies enregistrées.',
    ],
    $catalogField('allergyNote', RecordFields::PATIENT['allergyNote']),
];

// Mesures à l'admission (AURA-10.1), enregistrées sur le séjour. Bornes = colonnes DECIMAL(5,2) ;
// le contrôle serveur (AdmissionService::validate()) reste la garantie.
$measures = [
    [
        'name' => 'height',
        'label' => 'Taille (cm)',
        'type' => 'number',
        'placeholder' => '172',
        'attributes' => ['min' => '0.01', 'max' => '999.99', 'step' => '0.01', 'inputmode' => 'decimal'],
    ],
    [
        'name' => 'weight',
        'label' => 'Poids à l\'admission (kg)',
        'type' => 'number',
        'placeholder' => '68,5',
        'attributes' => ['min' => '0.01', 'max' => '999.99', 'step' => '0.01', 'inputmode' => 'decimal'],
    ],
];
$precautions = [
    'name' => 'precautions',
    'label' => 'Précautions',
    'type' => 'textarea',
    'attributes' => [
        'rows' => '3',
        'maxlength' => (string) AdmissionService::PRECAUTIONS_MAX_LENGTH,
        'placeholder' => 'Isolement, risque de chute…',
    ],
];

$sections = [
    ['id' => 'history', 'title' => RecordFields::SECTIONS['history'], 'fields' => $sectionFields('history')],
    ['id' => 'disease', 'title' => RecordFields::SECTIONS['disease'], 'fields' => $sectionFields('disease')],
    ['id' => 'scores', 'title' => RecordFields::SECTIONS['scores'], 'fields' => $sectionFields('scores')],
    [
        'id' => 'measures',
        'title' => RecordFields::SECTIONS['measures'],
        'fields' => [...$measures, ...$sectionFields('measures')],
    ],
    [
        'id' => 'admin',
        'title' => RecordFields::SECTIONS['admin'],
        'fields' => [...$sectionFields('admin'), $precautions],
    ],
];

// Valeurs ressaisies, erreurs par champ et compteur « n sur m renseignés » de chaque section.
$fill = static function (array $formFields) use ($values, $errors): array {
    foreach ($formFields as &$formField) {
        $formField['value'] = $values[$formField['name']]
            ?? (in_array($formField['type'] ?? 'text', ['checkbox-group', 'allergy-picker'], true) ? [] : '');
        $formField['error'] = $errors[$formField['name']] ?? '';
    }
    unset($formField);

    return $formFields;
};
$search = $fill($search);
$essentials = $fill($essentials);
foreach ($sections as &$section) {
    $section['fields'] = $fill($section['fields']);
    $section['total'] = count($section['fields']);
    $section['filled'] = count(array_filter(
        $section['fields'],
        static fn (array $f): bool => $f['value'] !== '' && $f['value'] !== []
    ));
}
unset($section);

?>

<?= View::render('components/form', [
    'id' => 'admission-form',
    'title' => "Formulaire d'admission",
    'action' => '/patients/create',
    'submit' => 'Admettre',
    'csrfToken' => $csrfToken ?? null,
    'lead' => $search,
    'fields' => $essentials,
    'sections' => $sections,
    'error' => $formError,
]);
