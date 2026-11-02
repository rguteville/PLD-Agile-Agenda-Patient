<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;
use Aura\Domain\RecordFields;
use Aura\Service\ModificationPatientService;
use Aura\Service\AdmissionService;
use Aura\Service\ListePatientsService;
use Aura\Service\PatientSearchService;
use Aura\Service\RechercheIppService;
use InvalidArgumentException;
use LogicException;

class PatientController
{
    /**
     * Correspondance entre les noms des champs du formulaire d'admission (PR #5,
     * AURA-40) et les clés attendues par le domaine. Le mapping vit ici, pas dans
     * le service, pour que la validation reste indépendante de l'IHM.
     */
    private const CHAMPS_FORMULAIRE = [
        'lastname' => 'nom',
        'firstname' => 'prenom',
        'ipp' => 'ipp',
        'birthDate' => 'dateNaissance',
        'gender' => 'sexe',
        'taille' => 'taille',
        'poids' => 'poids',
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
    private const CHAMP_ALLERGIES = 'allergies';

    /** Le formulaire propose H/F, le domaine attend M/F (colonne Patient.sexe). */
    private const SEXE_FORMULAIRE_VERS_DOMAINE = ['H' => 'M', 'F' => 'F'];

    private const VUE_ADMISSION = 'patient/admission';
    private const TITRE_ADMISSION = 'AURA - Admission';
    /**
     * Scripts du formulaire d'admission : envoi AJAX (AURA-10.2), recherche par IPP (AURA-15),
     * sections repliables avec compteur de champs renseignés (form-sections.js).
     */
    public const SCRIPTS_ADMISSION = ['/js/form.js', '/js/admission-search.js', '/js/form-sections.js'];

    /** Message renvoyé quand aucun patient ne porte l'IPP recherché (AURA-15). */
    public const MESSAGE_IPP_INCONNU = 'IPP inconnu, saisie manuelle.';

    private const VUE_MODIFICATION = 'patient/modifier';
    private const TITRE_MODIFICATION = 'AURA - Modifier le patient';

    /**
     * Les services qui lisent la base ne sont fournis que par les routes qui en ont besoin
     * (voir public/index.php), pour que l'admission reste utilisable sans connexion.
     *
     * @param array<string, array<string, string>> $referentiels listes de l'admission lues en base :
     *        'services' (idHealthcareService => nom) et 'substances' (idSubstance => libellé) ;
     *        vides si la base est injoignable (le formulaire reste affichable, sans ces listes).
     */
    public function __construct(
        private AdmissionService $admissionService = new AdmissionService(),
        private ?ListePatientsService $listePatientsService = null,
        private ?ModificationPatientService $modificationPatientService = null,
        private ?RechercheIppService $rechercheIppService = null,
        private array $referentiels = [],
        private ?PatientSearchService $patientSearchService = null
    ) {
    }

    /** Message affiché sous le champ de recherche quand la saisie est trop courte. */
    public const MESSAGE_RECHERCHE_COURTE = 'Saisissez au moins 2 caractères.';

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
        $saisie = $_GET['q'] ?? '';
        $q = is_string($saisie) ? trim($saisie) : '';
        if (mb_strlen($q) < PatientSearchService::MIN_LENGTH) {
            $this->repondreJson(422, ['ok' => false, 'erreurs' => ['patientSearch' => self::MESSAGE_RECHERCHE_COURTE]]);
            return;
        }

        $resultat = $this->patientSearchService->search($q);
        $this->repondreJson(200, [
            'ok' => true,
            'mode' => $resultat['mode'],
            'resultats' => $resultat['results'],
            'total' => count($resultat['results']),
            'tronque' => $resultat['truncated'],
            'homonymes' => $resultat['homonyms'],
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
        $id = $this->lireIdPatient();
        if ($id === null) {
            $this->repondreJson(400, ['ok' => false, 'message' => 'Identifiant de patient manquant ou invalide.']);
            return;
        }
        $fiche = $this->patientSearchService->record($id);
        if ($fiche === null) {
            $this->repondreJson(404, ['ok' => false, 'message' => 'Aucun patient ne porte cet identifiant.']);
            return;
        }

        $patient = [];
        foreach (self::CHAMPS_FORMULAIRE as $champ => $cle) {
            if (array_key_exists($cle, $fiche['identite'])) {
                $patient[$champ] = $fiche['identite'][$cle] ?? '';
            }
        }
        foreach ($fiche['details'] as $champ => $valeur) {
            $patient[$champ] = $valeur ?? '';
        }

        $this->repondreJson(200, [
            'ok' => true,
            'patient' => $patient,
            'allergies' => array_column($fiche['allergies'], 'idSubstance'),
            'allergiesLibelles' => array_column($fiche['allergies'], 'libelle'),
        ]);
    }

    /** GET / : formulaire d'admission vide, avec les référentiels (services, allergènes) s'ils sont disponibles. */
    public function showAdmissionForm(): void
    {
        View::page(
            self::VUE_ADMISSION,
            ['referentiels' => $this->referentiels],
            self::TITRE_ADMISSION,
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
    public function rechercherParIpp(): void
    {
        if ($this->rechercheIppService === null) {
            throw new LogicException('PatientController::rechercherParIpp() requiert un RechercheIppService.');
        }

        $saisie = $_GET['ipp'] ?? '';
        $ipp = is_string($saisie) ? trim($saisie) : '';
        $erreur = $ipp === '' ? 'Saisissez un IPP pour lancer la recherche.' : AdmissionService::validerIpp($ipp);
        if ($erreur !== null) {
            $this->repondreJson(422, ['ok' => false, 'erreurs' => ['ipp' => $erreur]]);
            return;
        }

        $resultat = $this->rechercheIppService->rechercher($ipp);
        if ($resultat === null) {
            $this->repondreJson(200, ['ok' => true, 'trouve' => false, 'message' => self::MESSAGE_IPP_INCONNU]);
            return;
        }

        // Identité indexée par nom de champ du formulaire (même correspondance que la saisie).
        $patient = [];
        foreach (self::CHAMPS_FORMULAIRE as $champ => $cle) {
            if (array_key_exists($cle, $resultat) && !in_array($champ, ['idPatient', 'searchMode'], true)) {
                $patient[$champ] = $resultat[$cle] ?? '';
            }
        }
        $patient['lienModification'] = '/patients/modifier?id=' . $resultat['idPatient'];

        $this->repondreJson(200, [
            'ok' => true,
            'trouve' => true,
            'patient' => $patient,
            'allergies' => $resultat['allergies'],
        ]);
    }

    /** GET /patients?q=… : liste des patients avec leur séjour en cours (AURA-12.1). */
    public function list(): void
    {
        if ($this->listePatientsService === null) {
            throw new LogicException('PatientController::list() requiert un ListePatientsService.');
        }

        $saisie = $_GET['q'] ?? null;
        $recherche = ListePatientsService::nettoyerRecherche(is_string($saisie) ? $saisie : null);

        View::page('patient/list', [
            'patients' => $this->listePatientsService->lister($recherche),
            'recherche' => $recherche,
        ], 'AURA - Patients');
    }

    /** Clé d'erreur hors champ (admission refusée) : affichée en tête du formulaire. */
    public const ERREUR_FORMULAIRE = 'form';

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
        $saisie = $this->lireSaisie($_POST);
        $donnees = $this->versDomaine($saisie);
        $erreurs = $this->admissionService->valider($donnees, $this->referentiels);

        if ($erreurs !== []) {
            $this->repondreErreurs($saisie, $this->versFormulaire($erreurs));
            return;
        }

        try {
            $sejour = $this->admissionService->admettre($donnees, $this->referentiels);
        } catch (InvalidArgumentException $e) {
            // Admission refusée par le métier (déjà hospitalisé, lit occupé…) : rien n'a été écrit.
            $this->repondreErreurs($saisie, [self::ERREUR_FORMULAIRE => $e->getMessage()]);
            return;
        }

        $destination = '/sejour?id=' . $sejour->getIdSejour();
        if ($this->attendDuJson()) {
            $this->repondreJson(200, ['ok' => true, 'redirect' => $destination]);
            return;
        }
        header('Location: ' . $destination, true, 303);
    }

    public function edit(): void
    {
        $service = $this->modificationPatientService();
        $id = $this->lireIdPatient();
        if ($id === null) {
            View::erreur(400, 'Identifiant de patient manquant ou invalide.');
            return;
        }

        $patient = $service->trouver($id);
        if ($patient === null) {
            View::erreur(404, 'Aucun patient ne porte cet identifiant.');
            return;
        }

        $this->afficherModification($id, [
            'lastname' => $patient->getNom(),
            'firstname' => $patient->getPrenom(),
            'ipp' => $patient->getIpp() ?? '',
            'birthDate' => $patient->getDateNaissance()?->format(AdmissionService::FORMAT_DATE) ?? '',
            'gender' => $patient->getSexe() ?? '',
        ], []);
    }

    public function update(): void
    {
        $service = $this->modificationPatientService();
        $id = $this->lireIdPatient();
        if ($id === null) {
            View::erreur(400, 'Identifiant de patient manquant ou invalide.');
            return;
        }

        $saisie = $this->lireSaisie($_POST);
        try {
            $erreurs = $service->modifier($id, $this->versDomaine($saisie));
        } catch (InvalidArgumentException) {
            View::erreur(404, 'Aucun patient ne porte cet identifiant.');
            return;
        }

        if ($erreurs !== []) {
            http_response_code(422);
            $this->afficherModification($id, $saisie, $this->versFormulaire($erreurs));
            return;
        }

        header('Location: /patients', true, 303);
    }

    private function modificationPatientService(): ModificationPatientService
    {
        if ($this->modificationPatientService === null) {
            throw new LogicException('PatientController::edit() et update() requièrent un ModificationPatientService.');
        }

        return $this->modificationPatientService;
    }

    private function lireIdPatient(): ?int
    {
        $id = $_GET['id'] ?? null;
        if (!is_string($id) || preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
            return null;
        }

        return (int) $id;
    }

    private function afficherModification(int $idPatient, array $valeurs, array $erreurs): void
    {
        View::page(
            self::VUE_MODIFICATION,
            ['idPatient' => $idPatient, 'valeurs' => $valeurs, 'erreurs' => $erreurs],
            self::TITRE_MODIFICATION
        );
    }

    /**
     * Correspondance complète champ formulaire => clé domaine : les champs historiques
     * (CHAMPS_FORMULAIRE) plus les champs du catalogue RecordFields, dont le nom est identique
     * des deux côtés.
     *
     * @return array<string, string>
     */
    private static function champs(): array
    {
        $catalogue = array_merge(RecordFields::patientColumns(), RecordFields::episodeColumns());

        return self::CHAMPS_FORMULAIRE + array_combine($catalogue, $catalogue);
    }

    /**
     * Lit les champs attendus du formulaire. Toute valeur non textuelle (tableau
     * envoyé par une requête forgée) est traitée comme vide. Les allergènes cochés
     * (`allergies[]`) sont lus comme une liste de chaînes.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed> valeurs nettoyées, indexées par nom de champ du formulaire
     */
    private function lireSaisie(array $post): array
    {
        $saisie = [];
        foreach (array_keys(self::CHAMPS_FORMULAIRE) as $champ) {
            $valeur = $post[$champ] ?? '';
            $saisie[$champ] = is_string($valeur) ? trim($valeur) : '';
        }
        // Champs du catalogue : seulement ceux présents dans la requête. Un champ absent n'est pas
        // « vidé » : pour un patient existant, il reste tel quel (un formulaire tronqué n'efface rien).
        foreach (array_merge(RecordFields::patientColumns(), RecordFields::episodeColumns()) as $champ) {
            if (array_key_exists($champ, $post)) {
                $valeur = $post[$champ];
                $saisie[$champ] = is_string($valeur) ? trim($valeur) : '';
            }
        }

        $allergies = $post[self::CHAMP_ALLERGIES] ?? [];
        $saisie[self::CHAMP_ALLERGIES] = array_values(array_filter(
            is_array($allergies) ? $allergies : [$allergies],
            static fn ($v): bool => is_string($v) && trim($v) !== ''
        ));

        return $saisie;
    }

    /**
     * @param array<string, mixed> $saisie
     * @return array<string, mixed> données indexées par clé du domaine
     */
    private function versDomaine(array $saisie): array
    {
        $donnees = [];
        foreach (self::champs() as $champ => $cle) {
            if (array_key_exists($champ, $saisie)) {
                $donnees[$cle] = $saisie[$champ];
            }
        }
        $donnees[self::CHAMP_ALLERGIES] = $saisie[self::CHAMP_ALLERGIES] ?? [];
        // Une valeur de sexe inconnue est transmise telle quelle : le service la refusera.
        $donnees['sexe'] = self::SEXE_FORMULAIRE_VERS_DOMAINE[$donnees['sexe']] ?? $donnees['sexe'];

        return $donnees;
    }

    /**
     * Ré-indexe les erreurs du domaine par nom de champ du formulaire, pour les
     * afficher à côté du bon champ. Une clé sans correspondance (allergies) est reprise telle quelle.
     *
     * @param array<string, string> $erreurs
     * @return array<string, string>
     */
    private function versFormulaire(array $erreurs): array
    {
        $parChamp = [];
        foreach (self::champs() as $champ => $cle) {
            if (isset($erreurs[$cle])) {
                $parChamp[$champ] = $erreurs[$cle];
                unset($erreurs[$cle]);
            }
        }

        return $parChamp + $erreurs;
    }

    /**
     * @param array<string, mixed> $saisie
     * @param array<string, string> $erreurs
     */
    private function repondreErreurs(array $saisie, array $erreurs): void
    {
        if ($this->attendDuJson()) {
            $this->repondreJson(422, ['ok' => false, 'erreurs' => $erreurs]);
            return;
        }

        http_response_code(422);
        View::page(
            self::VUE_ADMISSION,
            [
                'valeurs' => $saisie,
                'erreurs' => $erreurs,
                'erreurFormulaire' => $erreurs[self::ERREUR_FORMULAIRE] ?? '',
                'referentiels' => $this->referentiels,
            ],
            self::TITRE_ADMISSION,
            self::SCRIPTS_ADMISSION
        );
    }

    /** Requête AJAX : en-tête Accept: application/json ou X-Requested-With: XMLHttpRequest. */
    private function attendDuJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        return str_contains($accept, 'application/json')
            || strcasecmp($requestedWith, 'XMLHttpRequest') === 0;
    }

    /** @param array<string, mixed> $corps */
    private function repondreJson(int $statut, array $corps): void
    {
        http_response_code($statut);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($corps, JSON_UNESCAPED_UNICODE);
    }
}
