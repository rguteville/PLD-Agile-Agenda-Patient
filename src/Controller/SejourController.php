<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;
use Aura\Service\AgendaService;
use Aura\Service\AllergyIntoleranceService;
use Aura\Service\CarePlanService;
use Aura\Service\DossierPatientService;
use Aura\Service\SejourService;
use InvalidArgumentException;
use LogicException;

final class SejourController
{
    private const RECORD_VIEW = 'sejour/dossier';
    /** form.js : arrêt d'une ligne de prescription en AJAX ; confirm.js : confirmation du retrait d'une allergie. */
    private const RECORD_SCRIPTS = ['/js/form.js', '/js/confirm.js'];

    /** Identifiant numérique tel que stocké (VARCHAR contenant un entier, sans zéro de tête). */
    private const ID_PATTERN = '/^[1-9][0-9]{0,17}$/';

    /**
     * Chaque route n'injecte que ce dont elle a besoin (cf. public/index.php) :
     * show() le dossier et, sans CarePlanService, s'affiche sans ses prescriptions ;
     * addAllergy() / removeAllergy() le dossier et les allergies ; getAgenda() l'agenda et le séjour.
     */
    public function __construct(
        private ?DossierPatientService $dossierPatientService = null,
        private ?CarePlanService $carePlanService = null,
        private ?AgendaService $agendaService = null,
        private ?SejourService $sejourService = null,
        private ?AllergyIntoleranceService $allergyIntoleranceService = null
    ) {
    }

    /** GET /sejour?id=… : dossier d'un séjour, bandeau identité + allergies en tête (RG2, AURA-14). */
    public function show(): void
    {
        $id = self::readId($_GET['id'] ?? null);
        if ($id === null) {
            View::erreur(400, 'Identifiant de séjour manquant ou invalide.');
            return;
        }

        $this->showRecord($id);
    }

    /**
     * POST /sejour/allergies/ajouter (AURA-13.2) : champs idSejour, idSubstance, criticality.
     * Succès : redirection 303 vers le dossier (POST → redirect → GET : rafraîchir la page
     * n'enregistre pas l'allergie une seconde fois). Refus métier : le dossier est réaffiché
     * avec le message, en 422, comme le formulaire d'admission.
     */
    public function addAllergy(): void
    {
        $idSejour = self::readId($_POST['idSejour'] ?? null);
        if ($idSejour === null) {
            View::erreur(400, 'Identifiant de séjour manquant ou invalide.');
            return;
        }

        try {
            $this->allergyIntoleranceService()->add(
                $idSejour,
                $_POST['idSubstance'] ?? null,
                $_POST['criticality'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            $this->showRecord($idSejour, $e->getMessage());
            return;
        }

        header('Location: /sejour?id=' . $idSejour, true, 303);
    }

    /** POST /sejour/allergies/retirer (AURA-13.2) : champs idSejour, idAllergy. Même schéma que l'ajout. */
    public function removeAllergy(): void
    {
        $idSejour = self::readId($_POST['idSejour'] ?? null);
        if ($idSejour === null) {
            View::erreur(400, 'Identifiant de séjour manquant ou invalide.');
            return;
        }

        $idAllergy = self::readId($_POST['idAllergy'] ?? null);
        if ($idAllergy === null) {
            $this->showRecord($idSejour, 'Allergie à retirer manquante ou invalide.');
            return;
        }

        try {
            $this->allergyIntoleranceService()->remove($idSejour, $idAllergy);
        } catch (InvalidArgumentException $e) {
            $this->showRecord($idSejour, $e->getMessage());
            return;
        }

        header('Location: /sejour?id=' . $idSejour, true, 303);
    }

    /** GET /agenda-patient?id=… ou ?numSejour=… : agenda du séjour, bandeau en tête (RG2, AURA-24). */
    public function getAgenda(): void
    {
        $id = $_GET['id'] ?? null;
        $numSejour = $_GET['numSejour'] ?? null;
        if ($id === null && $numSejour === null) {
            View::erreur(404, 'Identifiant ou numéro de séjour manquant.');
            return;
        }
        //Recuperer l'id du sejour selon son numero
        if ($id === null && $numSejour !== null) {
            $id = (string) $this->sejourService->getSejourByNumber($numSejour)->getIdSejour();
        }

        $idSejour = self::readId($id);
        if ($idSejour === null) {
            View::erreur(400, 'Identifiant de séjour manquant ou invalide.');
            return;
        }

        // Bandeau identité + allergies : même vue-modèle que le dossier (DossierPatientService).
        $dossier = $this->dossierPatientService()->dossier($idSejour);
        if ($dossier === null) {
            View::erreur(404, sprintf('Aucun séjour ne porte l\'identifiant « %d ».', $idSejour));
            return;
        }

        $careplans = $this->agendaService->getAgenda($idSejour);
        $titre = sprintf(
            'AURA - Agenda de %s %s, séjour %s',
            $dossier['bandeau']['nom'],
            $dossier['bandeau']['prenom'],
            $dossier['bandeau']['numeroSejour']
        );
        View::page('sejour/agenda-patient', [
            'bandeau' => $dossier['bandeau'],
            'idEpisodeOfCare' => $idSejour,
            'careplans' => $careplans,
        ], $titre);
    }

    /**
     * Affiche le dossier du séjour (404 s'il n'existe pas). Avec un message d'erreur, la
     * page est servie en 422 et le message apparaît dans la section « Allergies connues ».
     */
    private function showRecord(int $idSejour, ?string $allergyError = null): void
    {
        $dossier = $this->dossierPatientService()->dossier($idSejour);
        if ($dossier === null) {
            View::erreur(404, sprintf('Aucun séjour ne porte l\'identifiant « %d ».', $idSejour));
            return;
        }

        if ($allergyError !== null) {
            http_response_code(422);
            $dossier['allergyError'] = $allergyError;
        }

        $titre = sprintf(
            'AURA - %s %s, séjour %s',
            $dossier['bandeau']['nom'],
            $dossier['bandeau']['prenom'],
            $dossier['bandeau']['numeroSejour']
        );
        View::page(self::RECORD_VIEW, $dossier + [
            // idSejour : lien du bouton « Prescription » vers l'écran B3 (AURA-20) et formulaires d'allergie.
            'idSejour' => $idSejour,
            // Prescriptions validées par un médecin (RG5), sans les lignes arrêtées.
            'prescriptions' => $this->carePlanService?->validatedLines($idSejour) ?? [],
            // Médecins proposés pour le bouton « Arrêter » (AURA-22).
            'doctors' => $this->carePlanService?->doctors() ?? [],
        ], $titre, self::RECORD_SCRIPTS);
    }

    /** Id lu dans la requête (query string ou corps du POST) ; null si absent ou mal formé. */
    private static function readId(mixed $value): ?int
    {
        if (!is_string($value) || preg_match(self::ID_PATTERN, $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function dossierPatientService(): DossierPatientService
    {
        if ($this->dossierPatientService === null) {
            throw new LogicException('SejourController : le dossier de séjour requiert un DossierPatientService.');
        }

        return $this->dossierPatientService;
    }

    private function allergyIntoleranceService(): AllergyIntoleranceService
    {
        if ($this->allergyIntoleranceService === null) {
            throw new LogicException('SejourController : les allergies requièrent un AllergyIntoleranceService.');
        }

        return $this->allergyIntoleranceService;
    }
}
