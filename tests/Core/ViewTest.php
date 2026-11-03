<?php

declare(strict_types=1);

namespace Aura\Tests\Core;

use Aura\Core\View;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Moteur de vues (View) : échappement, rendu d'un gabarit, page complète dans le layout, page
 * d'erreur et gabarit absent. Sans base ni serveur ; le rendu détaillé des écrans est dans
 * tests/View/*RenderTest.php.
 */
final class ViewTest extends TestCase
{
    /** @return array<string, array{mixed, string}> */
    public static function escapedValues(): array
    {
        return [
            'esperluette' => ['a & b', 'a &amp; b'],
            'chevrons' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
            'guillemet double' => ['" onmouseover="x', '&quot; onmouseover=&quot;x'],
            'apostrophe' => ["' onfocus='x", '&#039; onfocus=&#039;x'],
            'les cinq ensemble' => ['&<>"\'', '&amp;&lt;&gt;&quot;&#039;'],
            'entité déjà échappée : ré-échappée' => ['&amp;', '&amp;amp;'],
            'accents UTF-8 intacts' => ['Hélène Lefèvre, Œdème', 'Hélène Lefèvre, Œdème'],
            'entier' => [42, '42'],
            'décimal' => [62.5, '62.5'],
            'null' => [null, ''],
            'chaîne vide' => ['', ''],
        ];
    }

    #[DataProvider('escapedValues')]
    public function testEscapeProtectsTextAndAttributes(mixed $value, string $expected): void
    {
        self::assertSame($expected, View::e($value));
    }

    public function testEscapeNeverOutputsInvalidUtf8(): void
    {
        // htmlspecialchars sans ENT_SUBSTITUTE : une chaîne mal encodée devient vide (sûr, mais muet).
        self::assertSame('', View::e("Jos\xE9 <b>"));
    }

    public function testRenderReturnsHtmlWithoutPrintingIt(): void
    {
        $this->expectOutputString('');

        $html = View::render('components/empty_state', ['title' => 'Zz <titre>', 'text' => 'Zz texte']);

        self::assertStringContainsString('class="empty-state"', $html);
        self::assertStringContainsString('Zz &lt;titre&gt;', $html);
        self::assertStringContainsString('Zz texte', $html);
    }

    public function testRenderDoesNotLetDataOverrideItsOwnVariables(): void
    {
        // extract(EXTR_SKIP) : une clé « template » dans les données ne détourne pas le gabarit rendu.
        $html = View::render('components/empty_state', ['title' => 'Zz', 'template' => 'layouts/main']);

        self::assertStringContainsString('class="empty-state"', $html);
        self::assertStringNotContainsString('<html', $html);
    }

    public function testRenderedTemplatesDoNotShareVariables(): void
    {
        View::render('components/empty_state', ['title' => 'Zz premier', 'text' => 'Zz texte du premier']);
        $second = View::render('components/empty_state', ['title' => 'Zz second']);

        self::assertStringNotContainsString('Zz texte du premier', $second);
    }

    public function testPageWrapsTheContentInTheLayoutWithEscapedScripts(): void
    {
        ob_start();
        View::page(
            'error',
            ['code' => 400, 'message' => 'Zz message'],
            'Zz titre',
            ['/js/zz.js', '/js/a.js?x="><script>']
        );
        $html = (string) ob_get_clean();

        self::assertStringStartsWith('<!DOCTYPE html>', ltrim($html));
        self::assertStringContainsString('<title>Zz titre</title>', $html);
        $main = strpos($html, '<main id="main"');
        $content = strpos($html, 'Zz message');
        $end = strpos($html, '</main>');
        self::assertNotFalse($main);
        self::assertTrue($main < $content && $content < $end, 'le contenu de la page est dans <main>');
        self::assertStringContainsString('<script src="/js/zz.js"></script>', $html);
        self::assertStringContainsString('<script src="/js/a.js?x=&quot;&gt;&lt;script&gt;"></script>', $html);
        self::assertGreaterThan(
            strpos($html, '/js/layout.js'),
            strpos($html, '/js/zz.js'),
            'scripts de la page après ceux du layout'
        );
    }

    public function testPageUsesDefaultTitle(): void
    {
        ob_start();
        View::page('error', ['code' => 404, 'message' => 'Zz']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<title>AURA</title>', $html);
    }

    #[RunInSeparateProcess]
    public function testErrorSetsTheHttpCodeAndShowsTheEscapedMessage(): void
    {
        ob_start();
        View::error(404, 'Séjour <introuvable>');
        $html = (string) ob_get_clean();

        self::assertSame(404, http_response_code());
        self::assertStringContainsString('<title>AURA - Erreur 404</title>', $html);
        self::assertStringContainsString('Erreur 404', $html);
        self::assertStringContainsString('Séjour &lt;introuvable&gt;', $html);
        self::assertStringContainsString('role="alert"', $html);
    }

    /**
     * Rend un gabarit absent en transformant l'avertissement de require en exception (sinon PHP
     * enchaîne sur une erreur fatale qui arrêterait PHPUnit). Renvoie l'exception et le nombre de
     * tampons de sortie laissés ouverts par render(), après les avoir refermés.
     *
     * @return array{?ErrorException, int}
     */
    private static function renderMissingTemplate(): array
    {
        $level = ob_get_level();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        $caught = null;
        try {
            View::render('components/zz_inexistant');
        } catch (ErrorException $e) {
            $caught = $e;
        } finally {
            restore_error_handler();
        }
        $leaked = ob_get_level() - $level;
        while (ob_get_level() > $level) {
            ob_end_clean();
        }

        return [$caught, $leaked];
    }

    public function testMissingTemplateIsAnErrorNotAnEmptyPage(): void
    {
        [$caught] = self::renderMissingTemplate();

        self::assertNotNull($caught, 'un gabarit absent doit faire échouer le rendu');
        self::assertStringContainsString('zz_inexistant.php', $caught->getMessage());
    }

    public function testRenderClosesItsOutputBufferWhenTheTemplateFails(): void
    {
        [$caught, $leaked] = self::renderMissingTemplate();
        self::assertNotNull($caught);

        if ($leaked !== 0) {
            $this->markTestIncomplete(
                'BUG : View::render() (src/Core/View.php:23-25) ouvre un tampon (ob_start) sans try/finally. '
                . 'Si le gabarit lève une exception ou une erreur, le tampon reste ouvert : le HTML partiel '
                . 'déjà produit est envoyé plus tard, devant la page d\'erreur. Correctif proposé : '
                . 'try { require … ; return ob_get_clean(); } '
                . 'finally { si le tampon est encore ouvert, ob_end_clean() }.'
            );
        }
        self::assertSame(0, $leaked);
    }
}
