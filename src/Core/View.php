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

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}