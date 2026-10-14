<?php

declare(strict_types=1);

namespace Aura\Tests\Core;

use Aura\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testRouteConnueAppelleLeHandler(): void
    {
        $router = new Router();
        $appele = false;
        $router->get('/sejour', static function () use (&$appele): void {
            $appele = true;
        });

        $router->dispatch('GET', '/sejour?id=1');

        self::assertTrue($appele, 'la partie requête de l\'URI ne doit pas empêcher la correspondance');
    }

    public function testRouteInconnueAppelleLeFallback(): void
    {
        $router = new Router();
        $fallbackAppele = false;
        $router->notFound(static function () use (&$fallbackAppele): void {
            $fallbackAppele = true;
        });

        $router->dispatch('GET', '/inconnue');

        self::assertTrue($fallbackAppele);
    }

    public function testRouteInconnueSansFallbackAfficheUnTexte(): void
    {
        $router = new Router();

        ob_start();
        $router->dispatch('POST', '/inconnue');
        $sortie = (string) ob_get_clean();

        self::assertSame('Page introuvable', $sortie);
    }
}
