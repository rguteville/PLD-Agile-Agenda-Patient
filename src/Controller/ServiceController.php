<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;
use Aura\Service\AgendaService;
use DateTimeImmutable;

final class ServiceController
{

    public function __construct(
        private AgendaService $agendaService
    ) {
    }


    public function getAgenda(): void
    {
        $id = $_GET['idHealthcareService'] ?? null;

        if ($id === null) {
            View::erreur(404, 'Identifiant manquant.');
            return;
        }

        // A adapter à la vue service !!!
        // Attention à la fonction presentDay qui était utilisée pour l'agenda-patient et qui devrait être réutilisée pour l'agenda-service
        $now = new DateTimeImmutable('now');
        $agenda = AgendaService::presentDay(
            $this->agendaService->getAgendaService($id),
            AgendaService::parseDay($_GET['date'] ?? null, $now),
            $now
        );
        
        // Créer la sérialisation suivant le modèle de agenda-service
        View::page('service/agenda-service', [
            'agenda' => $agenda,
        ]);
    }
}
