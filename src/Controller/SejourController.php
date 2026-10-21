<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;
use Aura\Service\CarePlanService;
use Aura\Service\DossierPatientService;

final class SejourController
{
    /** Sans CarePlanService, le dossier s'affiche sans ses prescriptions. */
    public function __construct(
        private DossierPatientService $dossierPatientService,
        private ?CarePlanService $carePlanService = null
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
}
