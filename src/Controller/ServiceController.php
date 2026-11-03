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

        // Jour demandé (?date=AAAA-MM-JJ), aujourd'hui sinon ; le retard se calcule à l'heure courante.
        $now = new DateTimeImmutable('now');
        $agenda = AgendaService::presentDay(
            $this->agendaService->getAgendaService($id),
            AgendaService::parseDay($_GET['date'] ?? null, $now),
            $now
        );
        View::page('service/agenda-service', [
            'agenda' => $agenda,
            // La validation d'un soin n'a pas encore de route : colonne et dialogue restent masqués.
            'validationEnabled' => false,
        ]);
    }
}
