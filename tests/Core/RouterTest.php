<?php

declare(strict_types=1);

namespace Aura\Tests\Core;

use Aura\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testKnownRouteCallsHandler(): void
    {
        $router = new Router();
        $called = false;
        $router->get('/sejour', static function () use (&$called): void {
            $called = true;
        });

        $router->dispatch('GET', '/sejour?id=1');

        self::assertTrue($called, 'la partie requête de l\'URI ne doit pas empêcher la correspondance');
    }

    public function testUnknownRouteCallsFallback(): void
    {
        $router = new Router();
        $fallbackCalled = false;
        $router->notFound(static function () use (&$fallbackCalled): void {
            $fallbackCalled = true;
        });

        $router->dispatch('GET', '/inconnue');

        self::assertTrue($fallbackCalled);
    }

    public function testUnknownRouteWithoutFallbackShowsText(): void
    {
        $router = new Router();

        ob_start();
        $router->dispatch('POST', '/inconnue');
        $sortie = (string) ob_get_clean();

        self::assertSame('Page introuvable', $sortie);
    }
}
