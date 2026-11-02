<?php

use Aura\Core\View;
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
    ['name' => 'lastname', 'label' => 'Nom', 'required' => true],
    ['name' => 'firstname', 'label' => 'Prénom', 'required' => true],
    [
        'name' => 'ipp',
        'label' => 'IPP (identifiant permanent du patient)',
        // AURA-15 : un patient déjà connu est retrouvé par son IPP et son identité est reprise
        // en lecture seule (public/js/admission-ipp.js) ; sinon la saisie reste manuelle.
        'button' => ['id' => 'ipp-rechercher', 'label' => 'Rechercher'],
        'status' => 'ipp-statut',
        'attributes' => ['inputmode' => 'numeric', 'autocomplete' => 'off'],
    ],
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
        'type' => 'checkbox-group',
        'options' => $substances,
        'emptyMessage' => 'Référentiel des allergènes indisponible : les allergies pourront être saisies '
            . 'depuis le dossier du séjour.',
        'help' => 'Cochez les allergènes connus. Un patient retrouvé par son IPP conserve '
            . 'ses allergies déjà enregistrées.',
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
        $champ['value'] = $valeurs[$champ['name']] ?? (($champ['type'] ?? 'text') === 'checkbox-group' ? [] : '');
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
