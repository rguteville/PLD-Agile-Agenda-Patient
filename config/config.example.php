<?php

declare(strict_types=1);

// Modèle de configuration, versionné dans Git.
// En local ou sur la VM : copiez ce fichier en config/config.php (ignoré par Git)
// et mettez-y les vrais identifiants. Ne committez JAMAIS de vrais identifiants.
// Les variables d'environnement (utilisées par la CI) sont prioritaires.

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'aura',
        'user' => getenv('DB_USER') ?: 'aura',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],
];
