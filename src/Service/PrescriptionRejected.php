<?php

declare(strict_types=1);

namespace Aura\Service;

use InvalidArgumentException;

/**
 * Ordonnance refusée avec des erreurs rattachées à un champ du formulaire B3 (ex. allergyOverride :
 * allergie du patient non confirmée). Hérite d'InvalidArgumentException : un appelant qui ne connaît
 * que ce type affiche simplement le message, qui reprend toutes les erreurs.
 */
final class PrescriptionRejected extends InvalidArgumentException
{
    /** @param array<string, string> $errors messages affichables indexés par champ */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
