<?php

declare(strict_types=1);

namespace Aura\Core;

use RuntimeException;

/**
 * Erreur de configuration de l'application : fichier de configuration absent
 * ou invalide, ou connexion à la base de données impossible.
 *
 * Son message est destiné à être affiché : il ne contient jamais d'identifiant
 * ni de DSN (voir Database).
 */
final class ConfigurationException extends RuntimeException
{
}
