<?php

declare(strict_types=1);

namespace Aura\Core;

/**
 * Rendu des vues PHP de src/View : un gabarit reçoit ses données en variables locales (extract).
 * Les vues doivent échapper toute valeur avec e().
 */
class View
{
    private const BASE_PATH = __DIR__ . '/../View/';

    /**
     * Rend un gabarit (chemin relatif à src/View, sans .php) et renvoie le HTML sans l'afficher.
     *
     * @param array<string, mixed> $data variables mises à disposition du gabarit
     */
    public static function render(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        $level = ob_get_level();
        ob_start();
        try {
            require self::BASE_PATH . $template . '.php';

            return (string) ob_get_clean();
        } finally {
            // Gabarit en échec : le HTML partiel est jeté, il ne s'affichera pas devant la page d'erreur.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /** Affiche une page complète : son contenu inséré dans le layout */
    public static function page(string $template, array $data = [], string $title = 'AURA', array $scripts = []): void
    {
        $content = self::render('pages/' . $template, $data);
        echo self::render('layouts/main', compact('content', 'title', 'scripts'));
    }

    /** Page d'erreur applicative (400 paramètre invalide, 404 séjour inexistant…) dans le layout commun. */
    public static function error(int $code, string $message): void
    {
        http_response_code($code);
        self::page('error', ['code' => $code, 'message' => $message], 'AURA - Erreur ' . $code);
    }

    /** Échappe une valeur pour l'HTML (texte et attributs, guillemets compris), en UTF-8. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
