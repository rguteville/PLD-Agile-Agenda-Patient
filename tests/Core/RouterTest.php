<?php

declare(strict_types=1);

namespace Aura\Tests\Core;

use Aura\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testKnownRouteIsFoundDespiteTheQueryString(): void
    {
        $router = new Router();
        $called = false;
        $router->get('/sejour', static function () use (&$called): void {
            $called = true;
        });

        $router->dispatch('GET', '/sejour?id=1');

        self::assertTrue($called);
    }

    public function testUnknownRouteOrWrongMethodCallsTheFallback(): void
    {
        $router = new Router();
        $router->get('/sejour', static function (): void {
        });
        $fallbacks = 0;
        $router->notFound(static function () use (&$fallbacks): void {
            $fallbacks++;
        });

        $router->dispatch('GET', '/inconnue');
        $router->dispatch('POST', '/sejour');

        self::assertSame(2, $fallbacks);
    }
}
