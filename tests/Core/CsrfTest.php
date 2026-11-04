<?php

declare(strict_types=1);

namespace Aura\Tests\Core;

use Aura\Core\Csrf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Jeton anti-CSRF de session (Csrf). Chaque test tourne dans son propre processus : Csrf ouvre la
 * session PHP, qui ne doit fuir ni vers les autres tests ni d'un test à l'autre.
 */
final class CsrfTest extends TestCase
{
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    #[RunInSeparateProcess]
    public function testTokenIsStableWithinTheSameSession(): void
    {
        $first = Csrf::token();

        self::assertSame($first, Csrf::token(), 'même session : même jeton');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first, '32 octets aléatoires en hexadécimal');
    }

    #[RunInSeparateProcess]
    public function testTokenOpensASessionWithProtectedCookie(): void
    {
        Csrf::token();

        self::assertSame(PHP_SESSION_ACTIVE, session_status(), 'token() ouvre la session');
        $cookie = session_get_cookie_params();
        self::assertTrue($cookie['httponly'], 'cookie de session illisible depuis JavaScript');
        self::assertSame('Lax', $cookie['samesite']);
        self::assertSame('1', ini_get('session.use_strict_mode'), 'identifiant de session imposé refusé');
    }

    #[RunInSeparateProcess]
    public function testANewSessionGetsANewToken(): void
    {
        $first = Csrf::token();
        $_SESSION = [];

        $second = Csrf::token();

        self::assertNotSame($first, $second, 'un jeton par session, jamais une valeur fixe');
        self::assertFalse(Csrf::check($first), 'le jeton de l\'ancienne session n\'est plus accepté');
        self::assertTrue(Csrf::check($second));
    }

    #[RunInSeparateProcess]
    public function testCheckAcceptsTheSessionToken(): void
    {
        self::assertTrue(Csrf::check(Csrf::token()));
    }

    /** @return array<string, array{?string}> */
    public static function emptyValues(): array
    {
        return [
            'null (en-tête absent)' => [null],
            'chaîne vide' => [''],
        ];
    }

    #[RunInSeparateProcess]
    #[DataProvider('emptyValues')]
    public function testCheckRefusesAMissingToken(?string $received): void
    {
        Csrf::token();

        self::assertFalse(Csrf::check($received));
    }

    #[RunInSeparateProcess]
    #[DataProvider('emptyValues')]
    public function testCheckRefusesAMissingTokenEvenWhenTheSessionHasNone(?string $received): void
    {
        // Session sans jeton : null === null ne doit surtout pas passer pour une égalité.
        self::assertFalse(Csrf::check($received));
    }

    #[RunInSeparateProcess]
    public function testCheckRefusesAnyTokenWhenTheSessionHasNone(): void
    {
        self::assertFalse(Csrf::check(str_repeat('a', 64)));
    }

    /**
     * Variantes d'un jeton valide. Des libellés plutôt que des closures : les données d'un test
     * isolé sont sérialisées vers le processus fils.
     *
     * @return array<string, array{string}>
     */
    public static function forgedTokens(): array
    {
        return [
            'dernier caractère changé' => ['last'],
            'premier caractère changé' => ['first'],
            'préfixe du jeton' => ['prefix'],
            'jeton suivi d\'un caractère' => ['suffix'],
            'jeton en majuscules' => ['upper'],
            'jeton entouré d\'espaces' => ['spaces'],
            'autre valeur de même longueur' => ['zeros'],
        ];
    }

    private static function forge(string $variant, string $token): string
    {
        return match ($variant) {
            'last' => substr($token, 0, -1) . ($token[63] === '0' ? '1' : '0'),
            'first' => ($token[0] === '0' ? '1' : '0') . substr($token, 1),
            'prefix' => substr($token, 0, 32),
            'suffix' => $token . '0',
            'upper' => strtoupper($token),
            'spaces' => ' ' . $token . ' ',
            'zeros' => str_repeat('0', 64),
        };
    }

    /**
     * Comparaison exacte (hash_equals) : toute variation, même d'un caractère ou de longueur, est refusée.
     * Le temps constant lui-même ne se mesure pas de façon fiable en test unitaire.
     */
    #[RunInSeparateProcess]
    #[DataProvider('forgedTokens')]
    public function testCheckRefusesAForgedToken(string $variant): void
    {
        $token = Csrf::token();
        $forged = self::forge($variant, $token);

        self::assertNotSame($token, $forged);
        self::assertFalse(Csrf::check($forged));
    }

    #[RunInSeparateProcess]
    public function testANonStringValueInSessionIsReplacedByAFreshToken(): void
    {
        $_SESSION = ['aura_csrf_token' => 12345];

        self::assertFalse(Csrf::check('12345'), 'une valeur non chaîne en session n\'est pas un jeton');
        $token = Csrf::token();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertTrue(Csrf::check($token));
    }
}
