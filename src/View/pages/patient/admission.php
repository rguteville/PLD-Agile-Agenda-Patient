<?php

use Aura\Core\View;
use Aura\Domain\Allergie;
use Aura\Domain\RecordFields;
use Aura\Service\AdmissionService;

/**
 * Formulaire d'admission (AURA-10, AURA-15) étendu aux champs du SI réel (PR « admission-champs-complets »).
 * L'essentiel reste visible en haut (identité, IPP, motif, service, allergies) ; le reste est réparti en
 * sections repliées par défaut (RecordFields::SECTIONS), toutes facultatives.
 *
 * @var array<string, mixed> $valeurs valeurs saisies après un refus serveur (vide à l'affichage initial)
 * @var array<string, string> $erreurs erreurs indexées par nom de champ
 * @var string $erreurFormulaire message hors champ (admission refusée)
 * @var array<string, array<string, string>> $referentiels 'services' et 'substances' (vides sans base)
 */
$valeurs = $valeurs ?? [];
$erreurs = $erreurs ?? [];
$erreurFormulaire = $erreurFormulaire ?? '';
$referentiels = $referentiels ?? [];
$services = $referentiels['services'] ?? [];
$substances = $referentiels['substances'] ?? [];

// Champ du catalogue → champ du composant field (même nom des deux côtés).
$champCatalogue = static function (string $name, array $spec): array {
    $champ = ['name' => $name, 'label' => $spec['label'], 'type' => $spec['type']];
    if (isset($spec['help'])) {
        $champ['help'] = $spec['help'];
    }
    if ($spec['type'] === 'select') {
        $champ['options'] = $spec['options'];
        $champ['empty'] = 'Non renseigné';
    } elseif ($spec['type'] === 'textarea') {
        $champ['attributes'] = ['rows' => '3', 'maxlength' => (string) $spec['max']];
    } elseif (isset($spec['max'])) {
        $champ['attributes'] = ['maxlength' => (string) $spec['max']];
    }

    return $champ;
};
// Champs du catalogue appartenant à une section, patient puis séjour.
$champsSection = static function (string $section) use ($champCatalogue): array {
    $champs = [];
    foreach ([RecordFields::PATIENT, RecordFields::EPISODE] as $catalogue) {
        foreach ($catalogue as $name => $spec) {
            if ($spec['section'] === $section) {
                $champs[] = $champCatalogue($name, $spec);
            }
        }
    }

    return $champs;
};

$essentiels = [
    // Recherche d'un patient existant (identitovigilance) : par IPP (AURA-15, prioritaire) ou par
    // nom / prénom (un seul champ : l'ordre des mots est libre, « dupont jean » = « jean dupont »).
    // Les suggestions s'affichent dans la liste sous les deux champs ; la sélection demande une
    // confirmation, puis la fiche pré-remplit le formulaire (public/js/admission-search.js).
    [
        'name' => 'recherche-patient',
        'label' => 'Rechercher un patient existant',
        'type' => 'heading',
        'help' => 'Un patient déjà venu dans le service est rattaché à son dossier au lieu d\'être recréé. '
            . 'Vérifiez la date de naissance et l\'IPP avant de sélectionner un homonyme.',
    ],
    [
        'name' => 'ipp',
        'label' => 'IPP (identifiant permanent du patient)',
        'button' => ['id' => 'ipp-rechercher', 'label' => 'Rechercher'],
        'status' => 'ipp-statut',
        'attributes' => [
            'inputmode' => 'numeric', 'autocomplete' => 'off',
            'role' => 'combobox', 'aria-autocomplete' => 'list', 'aria-expanded' => 'false',
            'aria-controls' => 'patient-suggestions',
        ],
    ],
    [
        'name' => 'patientSearch',
        'label' => 'Nom ou prénom',
        'help' => 'Au moins 2 lettres ; casse, accents, tirets et ordre des mots indifférents.',
        'status' => 'patient-search-status',
        'listbox' => 'patient-suggestions',
        'attributes' => [
            'autocomplete' => 'off', 'maxlength' => '100',
            'role' => 'combobox', 'aria-autocomplete' => 'list', 'aria-expanded' => 'false',
            'aria-controls' => 'patient-suggestions',
        ],
    ],
    ['name' => 'idPatient', 'type' => 'hidden'],
    ['name' => 'searchMode', 'type' => 'hidden'],
    ['name' => 'identite', 'label' => 'Identité', 'type' => 'heading'],
    ['name' => 'lastname', 'label' => 'Nom', 'required' => true],
    ['name' => 'firstname', 'label' => 'Prénom', 'required' => true],
    ['name' => 'birthDate', 'label' => 'Date de naissance', 'type' => 'date'],
    [
        'name' => 'gender',
        'label' => 'Sexe',
        'type' => 'radio',
        'options' => ['M' => 'M', 'F' => 'F']
    ],
    $champCatalogue('admissionReason', RecordFields::EPISODE['admissionReason']),
    [
        'name' => 'idHealthcareService',
        'label' => 'Service hospitalier',
        'type' => 'select',
        'options' => $services,
        'empty' => $services === [] ? 'Référentiel indisponible' : 'Non renseigné',
    ],
    [
        'name' => 'allergies',
        'label' => 'Allergies connues (référentiel)',
        'type' => 'allergy-picker',
        'options' => $substances,
        'criticalities' => is_array($valeurs['allergyCriticality'] ?? null) ? $valeurs['allergyCriticality'] : [],
        'criticalityOptions' => Allergie::CRITICALITIES,
        'emptyMessage' => 'Référentiel des allergènes indisponible : les allergies pourront être saisies '
            . 'depuis le dossier du séjour.',
        'help' => 'Cochez chaque allergène connu et indiquez sa criticité. Un patient déjà connu conserve '
            . 'ses allergies enregistrées.',
    ],
    $champCatalogue('allergyNote', RecordFields::PATIENT['allergyNote']),
];

// Mesures à l'admission (AURA-10.1), enregistrées sur le séjour. Bornes = colonnes DECIMAL(5,2) ;
// le contrôle serveur (AdmissionService::valider()) reste la garantie.
$mesures = [
    [
        'name' => 'taille',
        'label' => 'Taille (cm)',
        'type' => 'number',
        'attributes' => ['min' => '0.01', 'max' => '999.99', 'step' => '0.01', 'inputmode' => 'decimal'],
    ],
    [
        'name' => 'poids',
        'label' => 'Poids à l\'admission (kg)',
        'type' => 'number',
        'attributes' => ['min' => '0.01', 'max' => '999.99', 'step' => '0.01', 'inputmode' => 'decimal'],
    ],
];
$precautions = [
    'name' => 'precautions',
    'label' => 'Précautions',
    'type' => 'textarea',
    'attributes' => [
        'rows' => '3',
        'maxlength' => (string) AdmissionService::PRECAUTIONS_LONGUEUR_MAX,
        'placeholder' => 'Isolement, risque de chute…',
    ],
];

$sections = [
    ['id' => 'history', 'title' => RecordFields::SECTIONS['history'], 'fields' => $champsSection('history')],
    ['id' => 'disease', 'title' => RecordFields::SECTIONS['disease'], 'fields' => $champsSection('disease')],
    ['id' => 'scores', 'title' => RecordFields::SECTIONS['scores'], 'fields' => $champsSection('scores')],
    [
        'id' => 'measures',
        'title' => RecordFields::SECTIONS['measures'],
        'fields' => [...$mesures, ...$champsSection('measures')],
    ],
    [
        'id' => 'admin',
        'title' => RecordFields::SECTIONS['admin'],
        'fields' => [...$champsSection('admin'), $precautions],
    ],
];

// Valeurs ressaisies, erreurs par champ et compteur « n sur m renseignés » de chaque section.
$remplir = static function (array $champs) use ($valeurs, $erreurs): array {
    foreach ($champs as &$champ) {
        $champ['value'] = $valeurs[$champ['name']]
            ?? (in_array($champ['type'] ?? 'text', ['checkbox-group', 'allergy-picker'], true) ? [] : '');
        $champ['error'] = $erreurs[$champ['name']] ?? '';
    }
    unset($champ);

    return $champs;
};
$essentiels = $remplir($essentiels);
foreach ($sections as &$section) {
    $section['fields'] = $remplir($section['fields']);
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
    'fields' => $essentiels,
    'sections' => $sections,
    'error' => $erreurFormulaire,
]);
