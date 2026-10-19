<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;
use Aura\Service\PrescriptionEntryService;
use InvalidArgumentException;

class PrescriptionController
{
    private const FORM_VIEW = 'prescription/saisie';
    private const FORM_TITLE = 'AURA - Nouvelles prescriptions';
    private const FORM_SCRIPTS = ['/js/form.js', '/js/prescription.js'];

    public function __construct(private PrescriptionEntryService $service)
    {
    }

    public function showForm(): void
    {
        $episodeId = trim((string) ($_GET['sejour'] ?? ''));
        if ($episodeId === '') {
            View::erreur(400, 'Paramètre « sejour » manquant.');
            return;
        }

        try {
            $data = $this->service->getFormData($episodeId);
        } catch (InvalidArgumentException $e) {
            View::erreur(404, $e->getMessage());
            return;
        }

        $data['savedCarePlanId'] = trim((string) ($_GET['enregistree'] ?? ''));
        $data['validatedCarePlanId'] = trim((string) ($_GET['validee'] ?? ''));
        View::page(self::FORM_VIEW, $data, self::FORM_TITLE, self::FORM_SCRIPTS);
    }

    public function store(): void
    {
        $errors = $this->service->validate($_POST);
        if ($errors !== []) {
            $this->respondJson(422, ['ok' => false, 'erreurs' => $errors]);
            return;
        }

        $carePlanId = $this->service->save($_POST);
        $episodeId = trim((string) ($_POST['episodeId'] ?? ''));
        $this->respondJson(200, [
            'ok' => true,
            'redirect' => '/prescriptions/nouvelle?sejour=' . rawurlencode($episodeId)
                . '&enregistree=' . rawurlencode($carePlanId),
        ]);
    }

    /**
     * POST /prescriptions/validate : un médecin valide une prescription en attente (RG5).
     * Réponse JSON pour public/js/form.js : 422 + erreurs, ou 200 + adresse de retour.
     */
    public function validateCarePlan(): void
    {
        $carePlanId = trim((string) ($_POST['carePlanId'] ?? ''));
        $errors = $this->service->validateCarePlan($carePlanId, trim((string) ($_POST['validator'] ?? '')));
        if ($errors !== []) {
            $this->respondJson(422, ['ok' => false, 'erreurs' => $errors]);
            return;
        }

        $episodeId = trim((string) ($_POST['episodeId'] ?? ''));
        $this->respondJson(200, [
            'ok' => true,
            'redirect' => '/prescriptions/nouvelle?sejour=' . rawurlencode($episodeId)
                . '&validee=' . rawurlencode($carePlanId),
        ]);
    }

    /** @param array<string, mixed> $body */
    private function respondJson(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
