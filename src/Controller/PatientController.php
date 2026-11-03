<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\DateInput;
use Aura\Core\View;
use Aura\Domain\RecordFields;
use Aura\Service\PatientUpdateService;
use Aura\Service\AdmissionService;
use Aura\Service\PatientListService;
use Aura\Service\PatientSearchService;
use Aura\Service\IppSearchService;
use InvalidArgumentException;
use LogicException;

/**
 * Écrans et endpoints autour du patient : admission (formulaire, création), liste, modification
 * de l'identité, recherche par IPP (AURA-15) et par nom / prénom, fiche pour pré-remplissage.
 * Le contrôleur lit la requête, mappe les champs du formulaire vers les clés du domaine, appelle
 * le service et répond (HTML ou JSON) ; il ne valide rien lui-même.
 */
class PatientController
{
    /**
     * Correspondance entre les noms des champs du formulaire d'admission (PR #5,
     * AURA-40) et les clés attendues par le domaine. Le mapping vit ici, pas dans
     * le service, pour que la validation reste indépendante de l'IHM.
     */
    private const FORM_FIELDS = [
        'lastname' => 'lastName',
        'firstname' => 'firstName',
        'ipp' => 'ipp',
        'birthDate' => 'birthDate',
        'gender' => 'sex',
        'height' => 'height',
        'weight' => 'weight',
        'precautions' => 'precautions',
        'idHealthcareService' => 'idService',
        // Patient sélectionné dans la recherche (champ caché posé par admission-search.js) et mode de recherche.
        'idPatient' => 'idPatient',
        'searchMode' => 'searchMode',
    ];

    /**
     * Allergènes cochés à l'admission (liste à cases `allergies[]`) : seul champ à valeurs
     * multiples, lu à part de lireSaisie().
     */
    private const ALLERGIES_FIELD = 'allergies';

    /** Criticité choisie pour chaque allergène coché : allergyCriticality[idSubstance] = « Faible »… */
    private const ALLERGY_CRITICALITY_FIELD = 'allergyCriticality';

    /** Le formulaire propose H/F, le domaine attend M/F (colonne Patient.sexe). */
    private const FORM_SEX_TO_DOMAIN = ['H' => 'M', 'F' => 'F'];

    private const ADMISSION_VIEW = 'patient/admission';
    private const ADMISSION_TITLE = 'AURA - Admission';
    /**
     * Scripts du formulaire d'admission : envoi AJAX (AURA-10.2), recherche par IPP (AURA-15),
     * sections repliables avec compteur de champs renseignés (form-sections.js).
     */
    public const SCRIPTS_ADMISSION = [
        '/js/form.js',
        '/js/admission-search.js',
        '/js/form-sections.js',
        '/js/allergy-picker.js',
        '/js/date-input.js',
    ];

    /** Message renvoyé quand aucun patient ne porte l'IPP recherché (AURA-15). */
    public const UNKNOWN_IPP_MESSAGE = 'IPP inconnu, saisie manuelle.';

    private const EDIT_VIEW = 'patient/edit';
    private const EDIT_TITLE = 'AURA - Modifier le patient';

    /**
     * Les services qui lisent la base ne sont fournis que par les routes qui en ont besoin
     * (voir public/index.php), pour que l'admission reste utilisable sans connexion.
     *
     * @param array<string, array<string, string>> $referenceDataSets listes de l'admission lues en base :
     *        'services' (idHealthcareService => nom) et 'substances' (idSubstance => libellé) ;
     *        vides si la base est injoignable (le formulaire reste affichable, sans ces listes).
     */
    public function __construct(
        private AdmissionService $admissionService = new AdmissionService(),
        private ?PatientListService $patientListService = null,
        private ?PatientUpdateService $patientUpdateService = null,
        private ?IppSearchService $ippSearchService = null,
        private array $referenceDataSets = [],
        private ?PatientSearchService $patientSearchService = null
    ) {
    }

    /** Message affiché sous le champ de recherche quand la saisie est trop courte. */
    public const SHORT_SEARCH_MESSAGE = 'Saisissez au moins 2 caractères.';

    /**
     * GET /patients/recherche?q=… : patients existants correspondant à un IPP (résultat unique) ou à
     * des mots du nom / prénom, en JSON, pour la liste de suggestions de l'admission. Jamais de
     * sélection automatique, jamais de donnée médicale dans la réponse (identitovigilance).
     * 422 si la saisie fait moins de 2 caractères ; 200 avec `resultats` (éventuellement vide) sinon.
     */
    public function search(): void
    {
        if ($this->patientSearchService === null) {
            throw new LogicException('PatientController::search() requiert un PatientSearchService.');
        }
        $input = $_GET['q'] ?? '';
        $q = is_string($input) ? trim($input) : '';
        if (mb_strlen($q) < PatientSearchService::MIN_LENGTH) {
            $this->respondJson(422, ['ok' => false, 'errors' => ['patientSearch' => self::SHORT_SEARCH_MESSAGE]]);
            return;
        }

        $result = $this->patientSearchService->search($q);
        $this->respondJson(200, [
            'ok' => true,
            'mode' => $result['mode'],
            'results' => $result['results'],
            'total' => count($result['results']),
            'truncated' => $result['truncated'],
            'homonyms' => $result['homonyms'],
        ]);
    }

    /**
     * GET /patients/fiche?id=… : fiche d'un patient, lue seulement après confirmation de la sélection,
     * avec les noms de champs du formulaire d'admission pour le pré-remplir. 404 si inconnu.
     */
    public function record(): void
    {
        if ($this->patientSearchService === null) {
            throw new LogicException('PatientController::record() requiert un PatientSearchService.');
        }
        $id = $this->readPatientId();
        if ($id === null) {
            $this->respondJson(400, ['ok' => false, 'message' => 'Identifiant de patient manquant ou invalide.']);
            return;
        }
        $card = $this->patientSearchService->record($id);
        if ($card === null) {
            $this->respondJson(404, ['ok' => false, 'message' => 'Aucun patient ne porte cet identifiant.']);
            return;
        }

        $patient = [];
        foreach (self::FORM_FIELDS as $field => $key) {
            if (array_key_exists($key, $card['identity'])) {
                $patient[$field] = $card['identity'][$key] ?? '';
            }
        }
        foreach ($card['details'] as $field => $value) {
            $patient[$field] = $value ?? '';
        }
        // Le formulaire saisit les dates en JJ/MM/AAAA.
        $patient['birthDate'] = DateInput::fromIso($patient['birthDate'] ?? '');

        $this->respondJson(200, [
            'ok' => true,
            'patient' => $patient,
            'allergies' => array_column($card['allergies'], 'idSubstance'),
            'allergyLabels' => array_column($card['allergies'], 'label'),
            // Criticité connue de chaque allergie, pour pré-régler la liste de l'admission.
            'allergyCriticalities' => array_column($card['allergies'], 'criticality', 'idSubstance'),
        ]);
    }

    /** GET / : formulaire d'admission vide, avec les référentiels (services, allergènes) s'ils sont disponibles. */
    public function showAdmissionForm(): void
    {
        View::page(
            self::ADMISSION_VIEW,
            ['referenceDataSets' => $this->referenceDataSets],
            self::ADMISSION_TITLE,
            self::SCRIPTS_ADMISSION
        );
    }

    /**
     * GET /patients/recherche-ipp?ipp=… (AURA-15) : patient déjà connu sous cet IPP, en JSON,
     * pour pré-remplir le formulaire d'admission. L'identité est renvoyée avec les noms de
     * champs du formulaire ; les allergies connues (RG2) sont jointes en lecture seule.
     * Réponses : 422 si l'IPP est vide ou hors format ; 200 avec `trouve: false` si inconnu ;
     * 200 avec `trouve: true`, `patient` et `allergies` sinon.
     */
    public function searchByIpp(): void
    {
        if ($this->ippSearchService === null) {
            throw new LogicException('PatientController::rechercherParIpp() requiert un IppSearchService.');
        }

        $input = $_GET['ipp'] ?? '';
        $ipp = is_string($input) ? trim($input) : '';
        $error = $ipp === '' ? 'Saisissez un IPP pour lancer la recherche.' : AdmissionService::validateIpp($ipp);
        if ($error !== null) {
            $this->respondJson(422, ['ok' => false, 'errors' => ['ipp' => $error]]);
            return;
        }

        $result = $this->ippSearchService->search($ipp);
        if ($result === null) {
            $this->respondJson(200, ['ok' => true, 'found' => false, 'message' => self::UNKNOWN_IPP_MESSAGE]);
            return;
        }

        // Identité indexée par nom de champ du formulaire (même correspondance que la saisie).
        $patient = [];
        foreach (self::FORM_FIELDS as $field => $key) {
            if (array_key_exists($key, $result) && !in_array($field, ['idPatient', 'searchMode'], true)) {
                $patient[$field] = $result[$key] ?? '';
            }
        }
        $patient['editLink'] = '/patients/modifier?id=' . $result['idPatient'];

        $this->respondJson(200, [
            'ok' => true,
            'found' => true,
            'patient' => $patient,
            'allergies' => $result['allergies'],
        ]);
    }

    /** GET /patients?q=… : liste des patients avec leur séjour en cours (AURA-12.1). */
    public function list(): void
    {
        if ($this->patientListService === null) {
            throw new LogicException('PatientController::list() requiert un PatientListService.');
        }

        $input = $_GET['q'] ?? null;
        $search = PatientListService::cleanSearch(is_string($input) ? $input : null);

        View::page('patient/list', [
            'patients' => $this->patientListService->listAll($search),
            'search' => $search,
        ], 'AURA - Patients');
    }

    /** Clé d'erreur hors champ (admission refusée) : affichée en tête du formulaire. */
    public const FORM_ERROR = 'form';

    /**
     * Admission d'un patient (AURA-10).
     *
     * AURA-10.2 : la validation serveur est faite AVANT toute écriture ; si elle
     * échoue, rien n'est enregistré et les erreurs sont renvoyées, soit en JSON
     * (requête AJAX), soit en réaffichant le formulaire avec les valeurs saisies.
     * AURA-10.4 : sur données valides, le patient et son séjour sont enregistrés,
     * puis l'utilisateur est redirigé vers le dossier du séjour (POST → redirect → GET :
     * rafraîchir la page du dossier ne crée pas de second séjour).
     */
    public function create(): void
    {
        $input = $this->readInput($_POST);
        $data = $this->toDomain($input);
        $errors = $this->admissionService->validate($data, $this->referenceDataSets);

        if ($errors !== []) {
            $this->respondWithErrors($input, $this->toForm($errors));
            return;
        }

        try {
            $episodeOfCare = $this->admissionService->admit($data, $this->referenceDataSets);
        } catch (InvalidArgumentException $e) {
            // Admission refusée par le métier (déjà hospitalisé, lit occupé…) : rien n'a été écrit.
            $this->respondWithErrors($input, [self::FORM_ERROR => $e->getMessage()]);
            return;
        }

        $destination = '/sejour?id=' . $episodeOfCare->getIdEpisodeOfCare();
        if ($this->wantsJson()) {
            $this->respondJson(200, ['ok' => true, 'redirect' => $destination]);
            return;
        }
        header('Location: ' . $destination, true, 303);
    }

    /** GET /patients/modifier?id=… (AURA-12.2) : formulaire pré-rempli avec l'identité du patient ; 400 / 404 sinon. */
    public function edit(): void
    {
        $service = $this->patientUpdateService();
        $id = $this->readPatientId();
        if ($id === null) {
            View::error(400, 'Identifiant de patient manquant ou invalide.');
            return;
        }

        $patient = $service->find($id);
        if ($patient === null) {
            View::error(404, 'Aucun patient ne porte cet identifiant.');
            return;
        }

        $this->showEditForm($id, [
            'lastname' => $patient->getLastName(),
            'firstname' => $patient->getFirstName(),
            'ipp' => $patient->getIpp() ?? '',
            'birthDate' => $patient->getBirthDate()?->format(AdmissionService::FORMAT_DATE) ?? '',
            'gender' => $patient->getSex() ?? '',
        ], []);
    }

    /**
     * POST /patients/modifier?id=… : enregistre l'identité modifiée puis redirige vers la liste (303) ;
     * réaffiche le formulaire en 422 avec les erreurs par champ en cas de refus.
     */
    public function update(): void
    {
        $service = $this->patientUpdateService();
        $id = $this->readPatientId();
        if ($id === null) {
            View::error(400, 'Identifiant de patient manquant ou invalide.');
            return;
        }

        $input = $this->readInput($_POST);
        try {
            $errors = $service->edit($id, $this->toDomain($input));
        } catch (InvalidArgumentException) {
            View::error(404, 'Aucun patient ne porte cet identifiant.');
            return;
        }

        if ($errors !== []) {
            http_response_code(422);
            $this->showEditForm($id, $input, $this->toForm($errors));
            return;
        }

        header('Location: /patients', true, 303);
    }

    private function patientUpdateService(): PatientUpdateService
    {
        if ($this->patientUpdateService === null) {
            throw new LogicException('PatientController::edit() et update() requièrent un PatientUpdateService.');
        }

        return $this->patientUpdateService;
    }

    private function readPatientId(): ?int
    {
        $id = $_GET['id'] ?? null;
        if (!is_string($id) || preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
            return null;
        }

        return (int) $id;
    }

    private function showEditForm(int $idPatient, array $values, array $errors): void
    {
        View::page(
            self::EDIT_VIEW,
            ['idPatient' => $idPatient, 'values' => $values, 'errors' => $errors],
            self::EDIT_TITLE,
            ['/js/date-input.js']
        );
    }

    /**
     * Champs de date du formulaire (saisis en JJ/MM/AAAA) : la date de naissance et les champs du
     * catalogue de type date.
     *
     * @return list<string>
     */
    private static function dateFields(): array
    {
        $dates = ['birthDate'];
        foreach (RecordFields::PATIENT + RecordFields::EPISODE as $field => $spec) {
            if ($spec['type'] === 'date') {
                $dates[] = $field;
            }
        }

        return $dates;
    }

    /**
     * Correspondance complète champ formulaire => clé domaine : les champs historiques
     * (CHAMPS_FORMULAIRE) plus les champs du catalogue RecordFields, dont le nom est identique
     * des deux côtés.
     *
     * @return array<string, string>
     */
    private static function fields(): array
    {
        $catalogue = array_merge(RecordFields::patientColumns(), RecordFields::episodeColumns());

        return self::FORM_FIELDS + array_combine($catalogue, $catalogue);
    }

    /**
     * Lit les champs attendus du formulaire. Toute valeur non textuelle (tableau
     * envoyé par une requête forgée) est traitée comme vide. Les allergènes cochés
     * (`allergies[]`) sont lus comme une liste de chaînes.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed> valeurs nettoyées, indexées par nom de champ du formulaire
     */
    private function readInput(array $post): array
    {
        $input = [];
        foreach (array_keys(self::FORM_FIELDS) as $field) {
            $value = $post[$field] ?? '';
            $input[$field] = is_string($value) ? trim($value) : '';
        }
        // Champs du catalogue : seulement ceux présents dans la requête. Un champ absent n'est pas
        // « vidé » : pour un patient existant, il reste tel quel (un formulaire tronqué n'efface rien).
        foreach (array_merge(RecordFields::patientColumns(), RecordFields::episodeColumns()) as $field) {
            if (array_key_exists($field, $post)) {
                $value = $post[$field];
                $input[$field] = is_string($value) ? trim($value) : '';
            }
        }

        $allergies = $post[self::ALLERGIES_FIELD] ?? [];
        $input[self::ALLERGIES_FIELD] = array_values(array_filter(
            is_array($allergies) ? $allergies : [$allergies],
            static fn ($v): bool => is_string($v) && trim($v) !== ''
        ));
        // Dates saisies en JJ/MM/AAAA : converties au format des services (AAAA-MM-JJ).
        foreach (self::dateFields() as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = DateInput::toIso($input[$field]);
            }
        }
        // Criticités : seules les paires (identifiant texte => valeur texte) sont gardées.
        $criticalities = $post[self::ALLERGY_CRITICALITY_FIELD] ?? [];
        $input[self::ALLERGY_CRITICALITY_FIELD] = [];
        foreach (is_array($criticalities) ? $criticalities : [] as $id => $value) {
            if (is_string($value)) {
                $input[self::ALLERGY_CRITICALITY_FIELD][(string) $id] = trim($value);
            }
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $saisie
     * @return array<string, mixed> données indexées par clé du domaine
     */
    private function toDomain(array $input): array
    {
        $data = [];
        foreach (self::fields() as $field => $key) {
            if (array_key_exists($field, $input)) {
                $data[$key] = $input[$field];
            }
        }
        $data[self::ALLERGIES_FIELD] = $input[self::ALLERGIES_FIELD] ?? [];
        $data['allergyCriticalities'] = $input[self::ALLERGY_CRITICALITY_FIELD] ?? [];
        // Une valeur de sexe inconnue est transmise telle quelle : le service la refusera.
        $data['sex'] = self::FORM_SEX_TO_DOMAIN[$data['sex']] ?? $data['sex'];

        return $data;
    }

    /**
     * Ré-indexe les erreurs du domaine par nom de champ du formulaire, pour les
     * afficher à côté du bon champ. Une clé sans correspondance (allergies) est reprise telle quelle ;
     * une erreur de criticité est affichée sous la liste des allergies.
     *
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    private function toForm(array $errors): array
    {
        $byField = [];
        foreach (self::fields() as $field => $key) {
            if (isset($errors[$key])) {
                $byField[$field] = $errors[$key];
                unset($errors[$key]);
            }
        }

        if (isset($errors['allergyCriticalities'])) {
            $errors[self::ALLERGIES_FIELD] ??= $errors['allergyCriticalities'];
            unset($errors['allergyCriticalities']);
        }

        return $byField + $errors;
    }

    /**
     * @param array<string, mixed> $saisie
     * @param array<string, string> $errors
     */
    private function respondWithErrors(array $input, array $errors): void
    {
        if ($this->wantsJson()) {
            $this->respondJson(422, ['ok' => false, 'errors' => $errors]);
            return;
        }

        http_response_code(422);
        View::page(
            self::ADMISSION_VIEW,
            [
                'values' => $input,
                'errors' => $errors,
                'formError' => $errors[self::FORM_ERROR] ?? '',
                'referenceDataSets' => $this->referenceDataSets,
            ],
            self::ADMISSION_TITLE,
            self::SCRIPTS_ADMISSION
        );
    }

    /** Requête AJAX : en-tête Accept: application/json ou X-Requested-With: XMLHttpRequest. */
    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        return str_contains($accept, 'application/json')
            || strcasecmp($requestedWith, 'XMLHttpRequest') === 0;
    }

    /** @param array<string, mixed> $corps */
    private function respondJson(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
