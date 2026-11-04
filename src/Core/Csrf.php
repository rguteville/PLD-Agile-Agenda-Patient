<?php

declare(strict_types=1);

namespace Aura\Core;

/**
 * Jeton anti-CSRF minimal, fondé sur la session PHP.
 *
 * Un seul jeton par session, envoyé en en-tête X-CSRF-Token (appels JSON) ou en champ « csrf »
 * (formulaires). Toutes les routes POST le vérifient : admission, modification et suppression d'un
 * patient, écritures du dossier (champ, partie, fin de séjour, décès, allergies), prescriptions
 * (enregistrement, validation, arrêt d'une ligne), soins (POST /agenda-patient/soin) et équipes.
 * La session n'est ouverte que par les pages qui appellent token() ou check().
 */
final class Csrf
{
    private const SESSION_KEY = 'aura_csrf_token';

    /** Jeton de la session courante, créé au premier appel. */
    public static function token(): string
    {
        self::startSession();
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /** Vrai si le jeton reçu est celui de la session (comparaison à temps constant). */
    public static function check(?string $received): bool
    {
        if ($received === null || $received === '') {
            return false;
        }
        self::startSession();
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($expected) && hash_equals($expected, $received);
    }

    /**
     * Ouvre la session si besoin (cookie HttpOnly, SameSite=Lax, mode strict). Si les en-têtes sont déjà
     * partis (tests en ligne de commande), $_SESSION est seulement initialisé.
     */
    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
    }
}
