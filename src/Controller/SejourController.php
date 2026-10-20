<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;
use Aura\Service\AgendaService;
use Aura\Service\DossierPatientService;
use Aura\Service\SejourService;

final class SejourController
{
    public function __construct(
        private ?DossierPatientService $dossierPatientService = null,
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
        View::page('sejour/dossier', $dossier, $titre);
    }

    public function getAgenda(): void
    {
        error_log('Appel Controller getAgenda');
        $id = $_GET['id'] ?? null;
        $numSejour = $_GET['numSejour'] ?? null;
        if($id === null && $numSejour === null)
        {
            View::erreur(400,'');
            return;
        }
        //Recuperer l'id du sejour selon son numero
        if($id === null && $numSejour !== null)
        {
            $id = $this->sejourService->getSejourByNumber($numSejour)->getIdSejour();
        }

        error_log($id);

        $careplans = $this->agendaService->getAgenda($id);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['careplans' => $careplans]);
    }
}
