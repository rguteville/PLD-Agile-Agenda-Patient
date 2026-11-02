<?php

declare(strict_types=1);

namespace Aura\Core;

/**
 * Jeton anti-CSRF minimal, fondé sur la session PHP (PR « dossier-patient-edition-inline »).
 *
 * Le projet n'avait ni session ni CSRF : ce jeton ne protège que l'édition en ligne du dossier
 * (en-tête X-CSRF-Token envoyé par public/js/inline-edit.js). La session n'est ouverte que par
 * les pages qui appellent token() ou check(), pour ne pas changer le comportement des autres.
 * Décision à confirmer par l'équipe (voir la PR) : généraliser ou retirer.
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
