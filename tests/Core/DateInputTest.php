<?php

declare(strict_types=1);

namespace Aura\Tests\Core;

use Aura\Core\DateInput;
use PHPUnit\Framework\TestCase;

/** Dates saisies en JJ/MM/AAAA, converties en AAAA-MM-JJ avant la validation par le service. */
final class DateInputTest extends TestCase
{
    public function testFrenchDateIsConvertedAndAnythingElseIsLeftForTheServiceToRefuse(): void
    {
        self::assertSame('1958-03-14', DateInput::toIso(' 14/03/1958 '));
        self::assertSame('1958-03-14', DateInput::toIso('1958-03-14'), 'déjà au format ISO');
        self::assertSame('14-03-1958', DateInput::toIso('14-03-1958'), 'mal formée : laissée telle quelle');
        self::assertSame('14/03/1958', DateInput::fromIso('1958-03-14'));
    }
}
