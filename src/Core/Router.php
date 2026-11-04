<?php

declare(strict_types=1);

namespace Aura\Core;

/**
 * Routeur minimal : correspondance exacte entre (méthode HTTP, chemin) et une closure. Pas de
 * paramètres d'URL : les identifiants passent en query string (?id=…) ou dans le corps du POST.
 */
class Router
{
    private array $routes = [];
    private ?\Closure $notFound = null;

    /** Déclare une route GET pour un chemin exact (ex. « /sejour »). */
    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    /** Déclare une route POST pour un chemin exact. */
    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    /** Handler appelé quand aucune route ne correspond (page 404 HTML). */
    public function notFound(callable $handler): void
    {
        $this->notFound = \Closure::fromCallable($handler);
    }

    /**
     * Exécute le handler de la route (méthode, chemin de l'URI sans query string) ; sinon 404 via le
     * handler notFound() s'il existe, ou un texte brut.
     */
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
