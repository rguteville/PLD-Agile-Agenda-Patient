<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;
use Aura\Service\DossierPatientService;

final class SejourController
{
    public function __construct(private DossierPatientService $dossierPatientService)
    {
    }

    /** GET /sejour?id=… : dossier d'un séjour, bandeau identité + allergies en tête (RG2, AURA-14). */
    public function show(): void
    {
        $id = $_GET['id'] ?? null;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,20}$/', $id) !== 1) {
            View::erreur(400, 'Identifiant de séjour manquant ou invalide.');
            return;
        }

        $dossier = $this->dossierPatientService->dossier($id);
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
}
