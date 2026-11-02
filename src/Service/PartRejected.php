<?php

declare(strict_types=1);

namespace Aura\Service;

use RuntimeException;

/**
 * Refus d'une partie : levée dans la transaction pour tout annuler, porte les erreurs par champ.
 *
 * @internal
 */
final class PartRejected extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors, public readonly bool $conflict)
    {
        parent::__construct('Partie du dossier refusée.');
    }
}
