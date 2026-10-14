<?php

declare(strict_types=1);

namespace Aura\Core;

use PDO;
use PDOException;

/**
 * Connexion PDO unique de l'application (AURA-11.1).
 *
 * La connexion est paresseuse : rien n'est ouvert tant que get() n'est pas appelé,
 * ce qui permet aux pages sans accès à la base de fonctionner sans config/config.php.
 *
 * Configuration lue, dans l'ordre : le chemin injecté par setConfigPath(),
 * sinon config/config.php, sinon config/config.example.php mais seulement si la
 * variable d'environnement DB_HOST est définie (cas du CI). Sans config.php ni
 * DB_HOST, l'erreur « Configuration manquante » est levée plutôt qu'une tentative
 * de connexion avec les valeurs par défaut du modèle. Le fichier doit renvoyer un
 * tableau ['db' => ['host', 'port', 'name', 'user', 'password']].
 *
 * Cette classe ne fait aucune requête : les DAO reçoivent le PDO par injection.
 */
final class Database
{
    private const CONFIG_DIR = __DIR__ . '/../../config/';

    private const CLES_REQUISES = ['host', 'port', 'name', 'user', 'password'];

    private static ?PDO $pdo = null;

    private static ?string $configPath = null;

    /**
     * Renvoie l'instance PDO unique, créée au premier appel.
     *
     * @throws ConfigurationException si la configuration est absente, invalide,
     *                                ou si la connexion échoue
     */
    public static function get(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::creerConnexion();
        }

        return self::$pdo;
    }

    /**
     * Impose le fichier de configuration à utiliser (tests). null rétablit la
     * recherche par défaut. Toute connexion déjà ouverte est oubliée.
     */
    public static function setConfigPath(?string $path): void
    {
        self::$configPath = $path;
        self::reset();
    }

    /** Oublie la connexion courante : le prochain get() en ouvrira une nouvelle. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Ouvre la connexion. La configuration n'est jamais passée en argument d'une
     * méthode : elle n'apparaît donc pas dans la trace d'une exception.
     */
    private static function creerConnexion(): PDO
    {
        $config = self::lireConfiguration();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['name']
        );

        try {
            return new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // On ne relance pas l'exception PDO d'origine : sa trace contient les
            // arguments du constructeur, donc le mot de passe.
            $message = self::nettoyerMessage($e->getMessage(), $dsn, $config['user'], $config['password']);

            throw new ConfigurationException('Connexion à la base impossible : ' . $message);
        }
    }

    /**
     * Lit le fichier de configuration et renvoie la section 'db' validée.
     *
     * @return array{host: string, port: int, name: string, user: string, password: string}
     */
    private static function lireConfiguration(): array
    {
        $path = self::resoudreCheminConfig();

        if ($path === null) {
            throw new ConfigurationException(
                'Configuration manquante : copiez config/config.example.php en config/config.php et renseignez la base.'
            );
        }

        $config = require $path;

        if (!is_array($config) || !isset($config['db']) || !is_array($config['db'])) {
            throw new ConfigurationException(
                'Configuration invalide : ' . basename($path) . " doit renvoyer un tableau contenant la clé 'db'."
            );
        }

        foreach (self::CLES_REQUISES as $cle) {
            if (!array_key_exists($cle, $config['db'])) {
                throw new ConfigurationException(
                    'Configuration invalide : la clé db.' . $cle . ' est absente de ' . basename($path) . '.'
                );
            }
        }

        return [
            'host' => (string) $config['db']['host'],
            'port' => (int) $config['db']['port'],
            'name' => (string) $config['db']['name'],
            'user' => (string) $config['db']['user'],
            'password' => (string) $config['db']['password'],
        ];
    }

    /** Premier fichier de configuration lisible, ou null s'il n'y en a aucun. */
    private static function resoudreCheminConfig(): ?string
    {
        if (self::$configPath !== null) {
            $candidats = [self::$configPath];
        } else {
            $candidats = [self::CONFIG_DIR . 'config.php'];
            // Le modèle ne sert de repli que si l'environnement fournit la base (CI).
            if ((string) getenv('DB_HOST') !== '') {
                $candidats[] = self::CONFIG_DIR . 'config.example.php';
            }
        }

        foreach ($candidats as $candidat) {
            if (is_file($candidat) && is_readable($candidat)) {
                return $candidat;
            }
        }

        return null;
    }

    /**
     * Retire du message de PDO ce qui identifie la base : le DSN complet et le
     * mot de passe (au cas où le pilote les y ferait figurer) ainsi que le nom
     * d'utilisateur, que MariaDB renvoie sous la forme 'utilisateur'@'hôte'.
     */
    private static function nettoyerMessage(string $message, string $dsn, string $user, string $password): string
    {
        $secrets = array_filter([$dsn, $password], fn(string $s) => $s !== '');
        $message = str_replace($secrets, '***', $message);

        return $user === '' ? $message : str_replace("'" . $user . "'@", "'***'@", $message);
    }
}
