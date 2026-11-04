<?php

declare(strict_types=1);

namespace Aura\Tests\Core;

use Aura\Core\View;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** Échappement HTML et page d'erreur : la défense contre l'injection de balisage. */
final class ViewTest extends TestCase
{
    public function testEscapeProtectsTextAndAttributes(): void
    {
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', View::e('<script>alert(1)</script>'));
        self::assertSame('&quot; onmouseover=&quot;x', View::e('" onmouseover="x'));
        self::assertSame('&#039; onfocus=&#039;x', View::e("' onfocus='x"));
        self::assertSame('a &amp; b', View::e('a & b'));
        self::assertSame('Hélène Lefèvre', View::e('Hélène Lefèvre'), 'accents intacts');
        self::assertSame('', View::e(null));
    }

    public function testInvalidUtf8IsNeverOutput(): void
    {
        // Une chaîne mal encodée devient vide plutôt que de laisser passer des octets bruts.
        self::assertSame('', View::e("Jos\xE9 <b>"));
    }

    #[RunInSeparateProcess]
    public function testErrorPageSetsTheHttpCodeAndEscapesTheMessage(): void
    {
        ob_start();
        View::error(404, 'Séjour <introuvable>');
        $html = (string) ob_get_clean();

        self::assertSame(404, http_response_code());
        self::assertStringContainsString('Séjour &lt;introuvable&gt;', $html);
        self::assertStringNotContainsString('<introuvable>', $html);
    }
}
