<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\Csrf;
use Aura\Core\View;
use Aura\Service\AgendaService;
use Aura\Service\AppointmentService;
use DateTimeImmutable;

/**
 * GET /agenda-service (AURA-29) : soins de tous les patients en cours d'un service, pour un jour.
 * Sans identifiant, le premier service du référentiel (par identifiant) est affiché, pour que
 * l'onglet « Agenda service » de la barre du haut mène toujours quelque part.
 */
final class ServiceController
{
    /**
     * @param array<string, string> $services référentiel des services, identifiant => nom
     */
    public function __construct(
        private AgendaService $agendaService,
        private array $services = [],
        private ?AppointmentService $appointmentService = null,
    ) {
    }

    public function getAgenda(): void
    {
        $id = $_GET['idHealthcareService'] ?? null;
        if ($id === null || $id === '') {
            $ids = array_map('strval', array_keys($this->services));
            sort($ids);
            $id = $ids[0] ?? null;
        }
        if (!is_string($id) || !array_key_exists($id, $this->services)) {
            View::error(404, 'Service inconnu.');
            return;
        }

        $now = new DateTimeImmutable('now');
        $service = AgendaService::presentService(
            $this->agendaService->getAgendaService($id),
            AgendaService::parseDay($_GET['date'] ?? null, $now),
            $now
        );

        // Valider un soin depuis la vue du service (AURA-26), même dialogue que l'agenda du patient.
        $validationEnabled = $this->appointmentService !== null;
        View::page('service/consolidated-view', [
            'service' => $service,
            'services' => $this->services,
            'idHealthcareService' => $id,
            'validationEnabled' => $validationEnabled,
            'validators' => $this->appointmentService?->performers() ?? [],
            'csrfToken' => $validationEnabled ? Csrf::token() : null,
        ], 'AURA - Agenda du service', ['/js/agenda.js', '/js/auto-submit.js']);
    }
}
