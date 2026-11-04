<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\Csrf;
use Aura\Core\View;
use Aura\Service\AgendaService;
use Aura\Service\AppointmentService;
use Aura\Service\AllergyIntoleranceService;
use Aura\Service\CarePlanService;
use Aura\Service\PatientRecordService;
use Aura\Service\RecordEditService;
use Aura\Service\EpisodeOfCareService;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * Écrans d'un séjour : dossier (bandeau RG2, allergies, dossier complet éditable, prescriptions
 * validées, historique), ajout / retrait d'allergie et agenda du patient, jour ou semaine
 * (validation d'un soin).
 *
 * Routes : GET /sejour, POST /sejour/allergies/ajouter, POST /sejour/allergies/retirer, GET /agenda-patient.
 * Les modifications du dossier (champ, partie, fin de séjour, décès) passent par RecordController.
 */
final class EpisodeOfCareController
{
    private const RECORD_VIEW = 'episode/record';
    /**
     * form.js : arrêt d'une ligne de prescription en AJAX ; confirm.js : confirmation du retrait d'une
     * allergie ; inline-edit.js : édition champ par champ du dossier complet ; record-menu.js : menu figé ;
     * date-input.js : dates saisies en JJ/MM/AAAA ; part-edit.js : modification d'une partie, fin de séjour
     * et décès, avec la fenêtre de validation par un médecin.
     */
    private const RECORD_SCRIPTS = [
        '/js/form.js',
        '/js/confirm.js',
        '/js/inline-edit.js',
        '/js/record-menu.js',
        '/js/date-input.js',
        '/js/part-edit.js',
    ];
    /**
     * agenda.js : filtres, impression et dialogue d'action sur un soin ; auto-submit.js : changement de jour
     * dès le choix d'une date.
     */
    private const AGENDA_SCRIPTS = ['/js/agenda.js', '/js/auto-submit.js'];

    /** Identifiant numérique tel que stocké (VARCHAR contenant un entier, sans zéro de tête). */
    private const ID_PATTERN = '/^[1-9][0-9]{0,17}$/';

    /**
     * Chaque route n'injecte que ce dont elle a besoin (cf. public/index.php) :
     * show() le dossier et, sans CarePlanService, s'affiche sans ses prescriptions ;
     * addAllergy() / removeAllergy() le dossier et les allergies ; getAgenda() l'agenda et le séjour.
     */
    public function __construct(
        private ?PatientRecordService $patientRecordService = null,
        private ?CarePlanService $carePlanService = null,
        private ?AgendaService $agendaService = null,
        private ?EpisodeOfCareService $episodeOfCareService = null,
        private ?AllergyIntoleranceService $allergyIntoleranceService = null,
        private ?RecordEditService $recordEditService = null,
        private ?AppointmentService $appointmentService = null
    ) {
    }

    /** GET /sejour?id=… : dossier d'un séjour, bandeau identité + allergies en tête (RG2). */
    public function show(): void
    {
        $id = self::readId($_GET['id'] ?? null);
        if ($id === null) {
            View::error(400, 'Identifiant de séjour manquant ou invalide.');
            return;
        }

        $this->showRecord($id);
    }

    /**
     * POST /sejour/allergies/ajouter : champs csrf, idEpisodeOfCare, idSubstance, criticality.
     * Jeton faux : 403 ; séjour invalide : 400.
     * Succès : redirection 303 vers le dossier (POST → redirect → GET : rafraîchir la page
     * n'enregistre pas l'allergie une seconde fois). Refus métier : le dossier est réaffiché
     * avec le message, en 422, comme le formulaire d'admission.
     */
    public function addAllergy(): void
    {
        // Une allergie retirée à l'insu du soignant désactiverait le refus d'un allergène prescrit : CSRF.
        if (!Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            View::error(403, 'Session expirée : rechargez la page puis réessayez.');
            return;
        }
        $idEpisodeOfCare = self::readId($_POST['idEpisodeOfCare'] ?? null);
        if ($idEpisodeOfCare === null) {
            View::error(400, 'Identifiant de séjour manquant ou invalide.');
            return;
        }

        try {
            $this->allergyIntoleranceService()->add(
                $idEpisodeOfCare,
                $_POST['idSubstance'] ?? null,
                $_POST['criticality'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            $this->showRecord($idEpisodeOfCare, $e->getMessage());
            return;
        }

        header('Location: /sejour?id=' . $idEpisodeOfCare, true, 303);
    }

    /**
     * POST /sejour/allergies/retirer : champs csrf, idEpisodeOfCare, idAllergy. Même schéma que
     * l'ajout. Le retrait est physique (DELETE), exception connue à la suppression logique.
     */
    public function removeAllergy(): void
    {
        // Une allergie retirée à l'insu du soignant désactiverait le refus d'un allergène prescrit : CSRF.
        if (!Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            View::error(403, 'Session expirée : rechargez la page puis réessayez.');
            return;
        }
        $idEpisodeOfCare = self::readId($_POST['idEpisodeOfCare'] ?? null);
        if ($idEpisodeOfCare === null) {
            View::error(400, 'Identifiant de séjour manquant ou invalide.');
            return;
        }

        $idAllergy = self::readId($_POST['idAllergy'] ?? null);
        if ($idAllergy === null) {
            $this->showRecord($idEpisodeOfCare, 'Allergie à retirer manquante ou invalide.');
            return;
        }

        try {
            $this->allergyIntoleranceService()->remove($idEpisodeOfCare, $idAllergy);
        } catch (InvalidArgumentException $e) {
            $this->showRecord($idEpisodeOfCare, $e->getMessage());
            return;
        }

        header('Location: /sejour?id=' . $idEpisodeOfCare, true, 303);
    }

    /**
     * GET /agenda-patient?id=… ou ?numSejour=… : agenda du séjour, bandeau en tête (RG2).
     * Paramètres facultatifs : `date` (AAAA-MM-JJ, aujourd'hui par défaut), `vue=semaine`. Les actions sur
     * un soin ne sont proposées que pour un séjour en cours.
     *
     * Un `numSejour` mal formé ou inconnu répond 404.
     */
    public function getAgenda(): void
    {
        $id = $_GET['id'] ?? null;
        $episodeNumber = $_GET['numSejour'] ?? null;
        if ($id === null && $episodeNumber === null) {
            View::error(404, 'Identifiant ou numéro de séjour manquant.');
            return;
        }
        // Identifiant du séjour à partir de son numéro (AAAA-NNNNNN).
        if ($id === null && $episodeNumber !== null) {
            if (!is_string($episodeNumber) || preg_match('/^\d{4}-\d{6}$/', $episodeNumber) !== 1) {
                View::error(404, 'Numéro de séjour invalide.');
                return;
            }
            try {
                $episode = $this->episodeOfCareService->getEpisodeOfCareByNumber($episodeNumber);
            } catch (InvalidArgumentException) {
                View::error(404, 'Aucun séjour ne porte ce numéro.');
                return;
            }
            $id = (string) $episode->getIdEpisodeOfCare();
        }

        $idEpisodeOfCare = self::readId($id);
        if ($idEpisodeOfCare === null) {
            View::error(400, 'Identifiant de séjour manquant ou invalide.');
            return;
        }

        // Bandeau identité + allergies : même vue-modèle que le dossier (PatientRecordService).
        $record = $this->patientRecordService()->record($idEpisodeOfCare);
        if ($record === null) {
            View::error(404, sprintf('Aucun séjour ne porte l\'identifiant « %d ».', $idEpisodeOfCare));
            return;
        }

        // Jour demandé (?date=AAAA-MM-JJ), aujourd'hui sinon ; le retard se calcule à l'heure courante.
        // ?vue=semaine affiche la semaine (lundi → dimanche) qui contient ce jour.
        $now = new DateTimeImmutable('now');
        $carePlans = $this->agendaService->getAgenda($idEpisodeOfCare);
        $day = AgendaService::parseDay($_GET['date'] ?? null, $now);
        $isWeek = ($_GET['vue'] ?? '') === 'semaine';
        $title = sprintf(
            'AURA - Agenda de %s %s, séjour %s',
            $record['banner']['lastName'],
            $record['banner']['firstName'],
            $record['banner']['episodeNumber']
        );
        // Valider un soin : seulement pour un séjour en cours et si le service est câblé.
        $validationEnabled = $this->appointmentService !== null && ($record['episodeOfCare']['ongoing'] ?? false);
        View::page('episode/patient-agenda', [
            'banner' => $record['banner'],
            'idEpisodeOfCare' => $idEpisodeOfCare,
            'view' => $isWeek ? 'week' : 'day',
            'agenda' => AgendaService::presentDay($carePlans, $day, $now, carryOverdue: true),
            'week' => $isWeek ? AgendaService::presentWeek($carePlans, $day, $now) : null,
            'validators' => $this->appointmentService?->performers() ?? [],
            'csrfToken' => $validationEnabled ? Csrf::token() : null,
            'validationEnabled' => $validationEnabled,
        ], $title, self::AGENDA_SCRIPTS);
    }

    /**
     * Affiche le dossier du séjour (404 s'il n'existe pas). Avec un message d'erreur, la
     * page est servie en 422 et le message apparaît dans la section « Allergies connues ».
     */
    private function showRecord(int $idEpisodeOfCare, ?string $allergyError = null): void
    {
        $record = $this->patientRecordService()->record($idEpisodeOfCare);
        if ($record === null) {
            View::error(404, sprintf('Aucun séjour ne porte l\'identifiant « %d ».', $idEpisodeOfCare));
            return;
        }

        if ($allergyError !== null) {
            http_response_code(422);
            $record['allergyError'] = $allergyError;
        }

        $title = sprintf(
            'AURA - %s %s, séjour %s',
            $record['banner']['lastName'],
            $record['banner']['firstName'],
            $record['banner']['episodeNumber']
        );
        // Édition en ligne : jeton de session pour l'endpoint JSON et historique des 20 dernières modifications.
        $idPatient = (int) ($record['episodeOfCare']['idPatient'] ?? 0);
        $editing = $this->recordEditService !== null ? [
            'editable' => true,
            'csrfToken' => Csrf::token(),
            'history' => $this->recordEditService->history($idPatient, $idEpisodeOfCare),
        ] : ['editable' => false, 'csrfToken' => Csrf::token(), 'history' => []];

        View::page(self::RECORD_VIEW, $record + $editing + [
            // idEpisodeOfCare : lien du bouton « Prescription » vers l'écran B3 et formulaires d'allergie.
            'idEpisodeOfCare' => $idEpisodeOfCare,
            // Prescriptions validées par un médecin (RG5), sans les lignes arrêtées ; une ligne qui contient un
            // allergène du patient est signalée (prescrite malgré l'allergie).
            'prescriptions' => $this->carePlanService?->validatedLines($idEpisodeOfCare, $record['allergens']) ?? [],
            // Médecins proposés pour le bouton « Arrêter ».
            'doctors' => $this->carePlanService?->doctors() ?? [],
        ], $title, self::RECORD_SCRIPTS);
    }

    /** Id lu dans la requête (query string ou corps du POST) ; null si absent ou mal formé. */
    private static function readId(mixed $value): ?int
    {
        if (!is_string($value) || preg_match(self::ID_PATTERN, $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /** @throws LogicException si la route n'a pas injecté de PatientRecordService */
    private function patientRecordService(): PatientRecordService
    {
        if ($this->patientRecordService === null) {
            throw new LogicException(
                'EpisodeOfCareController : le dossier de séjour requiert un PatientRecordService.'
            );
        }

        return $this->patientRecordService;
    }

    /** @throws LogicException si la route n'a pas injecté d'AllergyIntoleranceService */
    private function allergyIntoleranceService(): AllergyIntoleranceService
    {
        if ($this->allergyIntoleranceService === null) {
            throw new LogicException(
                'EpisodeOfCareController : les allergies requièrent un AllergyIntoleranceService.'
            );
        }

        return $this->allergyIntoleranceService;
    }
}
