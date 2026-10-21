<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;
use Aura\Service\AgendaService;
use Aura\Service\CarePlanService;
use Aura\Service\DossierPatientService;
use Aura\Service\SejourService;
use InvalidArgumentException;

final class SejourController
{
    /**
     * Chaque route n'injecte que ce dont elle a besoin (cf. public/index.php) :
     * show() le dossier et, sans CarePlanService, s'affiche sans ses prescriptions ;
     * getAgenda() l'agenda et le séjour.
     */
    public function __construct(
        private ?DossierPatientService $dossierPatientService = null,
        private ?CarePlanService $carePlanService = null,
        private ?AgendaService $agendaService = null,
        private ?SejourService $sejourService = null
    ) {
    }

    /** GET /sejour?id=… : dossier d'un séjour, bandeau identité + allergies en tête (RG2, AURA-14). */
    public function show(): void
    {
        $id = $_GET['id'] ?? null;
        if (!is_string($id) || preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
            View::erreur(400, 'Identifiant de séjour manquant ou invalide.');
            return;
        }

        $dossier = $this->dossierPatientService->dossier((int) $id);
        if ($dossier === null) {
            View::erreur(404, sprintf('Aucun séjour ne porte l\'identifiant « %s ».', $id));
            return;
        }

        $titre = sprintf(
            'AURA - %s %s, séjour %s',
            $dossier['bandeau']['nom'],
            $dossier['bandeau']['prenom'],
            $dossier['bandeau']['numeroSejour']
        );
        View::page('sejour/dossier', $dossier + [
            // idSejour : lien du bouton « Prescription » vers l'écran B3 (AURA-20).
            'idSejour' => (int) $id,
            // Prescriptions validées par un médecin (RG5), sans les lignes arrêtées.
            'prescriptions' => $this->carePlanService?->validatedLines((int) $id) ?? [],
            // Médecins proposés pour le bouton « Arrêter » (AURA-22).
            'doctors' => $this->carePlanService?->doctors() ?? [],
        ], $titre, ['/js/form.js']);
    }

    /** GET /agenda-patient?id=… ou ?numSejour=… : agenda du séjour en JSON (AURA-24). */
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

        error_log($id);

        $careplans = $this->agendaService->getAgenda($id);
        //header('Content-Type: application/json; charset=utf-8');
        //echo json_encode(['careplans' => $careplans]);
        View::page('sejour/agenda-patient',['careplans' => $careplans], 'AURA - Agenda Patient');
    }
}
