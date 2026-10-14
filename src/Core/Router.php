<?php

declare(strict_types=1);

namespace Aura\Core;

class Router
{
    private array $routes = [];
    private ?\Closure $notFound = null;

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    /** Handler appelé quand aucune route ne correspond (page 404 HTML, AURA-06). */
    public function notFound(callable $handler): void
    {
        $this->notFound = \Closure::fromCallable($handler);
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            http_response_code(404);
            if ($this->notFound !== null) {
                ($this->notFound)();
                return;
            }
            echo 'Page introuvable';
            return;
        }

        $handler();
    }
}
