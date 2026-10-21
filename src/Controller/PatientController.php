<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;
use Aura\Service\ModificationPatientService;
use Aura\Service\AdmissionService;
use Aura\Service\ListePatientsService;
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
    ];

    /** Le formulaire propose H/F, le domaine attend M/F (colonne Patient.sexe). */
    private const SEXE_FORMULAIRE_VERS_DOMAINE = ['H' => 'M', 'F' => 'F'];

    private const VUE_ADMISSION = 'patient/admission';
    private const TITRE_ADMISSION = 'AURA - Admission';
    private const SCRIPTS_ADMISSION = ['/js/form.js'];

    private const VUE_MODIFICATION = 'patient/modifier';
    private const TITRE_MODIFICATION = 'AURA - Modifier le patient';

    /**
     * Le service de liste lit la base : il n'est fourni que par la route GET /patients
     * (voir public/index.php), pour que l'admission reste utilisable sans connexion.
     */
    public function __construct(
        private AdmissionService $admissionService = new AdmissionService(),
        private ?ListePatientsService $listePatientsService = null,
        private ?ModificationPatientService $modificationPatientService = null
    ) {
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
        $erreurs = $this->admissionService->valider($donnees);

        if ($erreurs !== []) {
            $this->repondreErreurs($saisie, $this->versFormulaire($erreurs));
            return;
        }

        try {
            $sejour = $this->admissionService->admettre($donnees);
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
     * Lit les champs attendus du formulaire. Toute valeur non textuelle (tableau
     * envoyé par une requête forgée) est traitée comme vide.
     *
     * @param array<string, mixed> $post
     * @return array<string, string> valeurs nettoyées, indexées par nom de champ du formulaire
     */
    private function lireSaisie(array $post): array
    {
        $saisie = [];
        foreach (array_keys(self::CHAMPS_FORMULAIRE) as $champ) {
            $valeur = $post[$champ] ?? '';
            $saisie[$champ] = is_string($valeur) ? trim($valeur) : '';
        }

        return $saisie;
    }

    /**
     * @param array<string, string> $saisie
     * @return array<string, string> données indexées par clé du domaine
     */
    private function versDomaine(array $saisie): array
    {
        $donnees = [];
        foreach (self::CHAMPS_FORMULAIRE as $champ => $cle) {
            $donnees[$cle] = $saisie[$champ];
        }
        // Une valeur de sexe inconnue est transmise telle quelle : le service la refusera.
        $donnees['sexe'] = self::SEXE_FORMULAIRE_VERS_DOMAINE[$donnees['sexe']] ?? $donnees['sexe'];

        return $donnees;
    }

    /**
     * Ré-indexe les erreurs du domaine par nom de champ du formulaire, pour les
     * afficher à côté du bon champ.
     *
     * @param array<string, string> $erreurs
     * @return array<string, string>
     */
    private function versFormulaire(array $erreurs): array
    {
        $parChamp = [];
        foreach (self::CHAMPS_FORMULAIRE as $champ => $cle) {
            if (isset($erreurs[$cle])) {
                $parChamp[$champ] = $erreurs[$cle];
            }
        }

        return $parChamp;
    }

    /**
     * @param array<string, string> $saisie
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
