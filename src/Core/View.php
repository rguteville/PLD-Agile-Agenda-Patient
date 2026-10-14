<?php

declare(strict_types=1);

namespace Aura\Core;

class View
{
    private const BASE_PATH = __DIR__ . '/../View/';

    public static function render(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require self::BASE_PATH . $template . '.php';
        return (string) ob_get_clean();
    }

    /** Affiche une page complète : son contenu inséré dans le layout */
    public static function page(string $template, array $data = [], string $title = 'AURA', array $scripts = []): void
    {
        $content = self::render('pages/' . $template, $data);
        echo self::render('layouts/main', compact('content', 'title', 'scripts'));
    }

    /** Page d'erreur applicative (400 paramètre invalide, 404 séjour inexistant…) dans le layout commun. */
    public static function erreur(int $code, string $message): void
    {
        http_response_code($code);
        self::page('erreur', ['code' => $code, 'message' => $message], 'AURA - Erreur ' . $code);
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
