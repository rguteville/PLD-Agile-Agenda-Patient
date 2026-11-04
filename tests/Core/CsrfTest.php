<?php

declare(strict_types=1);

namespace Aura\Tests\Core;

use Aura\Core\Csrf;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** Jeton anti-CSRF de session. Processus séparé : Csrf ouvre la session PHP. */
final class CsrfTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testSessionTokenIsStableAndAccepted(): void
    {
        $token = Csrf::token();

        self::assertSame($token, Csrf::token(), 'même session : même jeton');
        self::assertTrue(Csrf::check($token));
    }

    #[RunInSeparateProcess]
    public function testMissingOrForgedTokenIsRefused(): void
    {
        $token = Csrf::token();

        self::assertFalse(Csrf::check(null), 'en-tête absent');
        self::assertFalse(Csrf::check(''));
        self::assertFalse(Csrf::check(substr($token, 0, -1) . ($token[63] === '0' ? '1' : '0')), 'un caractère');
        self::assertFalse(Csrf::check(strtoupper($token)));
        self::assertFalse(Csrf::check(' ' . $token . ' '));
    }

    #[RunInSeparateProcess]
    public function testSessionWithoutTokenAcceptsNothing(): void
    {
        // null === null ne doit pas passer pour une égalité.
        self::assertFalse(Csrf::check(null));
        self::assertFalse(Csrf::check(''));
        self::assertFalse(Csrf::check(str_repeat('a', 64)));
    }
}
