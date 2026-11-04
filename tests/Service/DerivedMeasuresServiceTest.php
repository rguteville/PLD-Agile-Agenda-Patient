<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\DerivedMeasuresService;
use PHPUnit\Framework\TestCase;

/**
 * Valeurs calculées (IMC, surface corporelle, poids théorique, Vt) avec des cas connus.
 * Les valeurs attendues sont recalculées à la main à partir des formules sourcées dans le service.
 */
final class DerivedMeasuresServiceTest extends TestCase
{
    public function testBmiPredictedWeightAndTidalVolumeOfAMan(): void
    {
        $view = DerivedMeasuresService::present(70.0, 175.0, 'M');

        self::assertSame('22,9 kg/m²', $view['bmi']['value']);
        self::assertSame('1,84 m²', $view['bodySurfaceArea']['value']);
        self::assertSame('70,5 kg', $view['predictedBodyWeight']['value']);
        self::assertSame('423 ml à 564 ml', $view['tidalVolume']['value']);
        self::assertNull($view['bmi']['reason']);
    }

    public function testMissingHeightOrSexIsExplainedInsteadOfAValue(): void
    {
        $view = DerivedMeasuresService::present(70.0, null, null);

        self::assertNull($view['bmi']['value']);
        self::assertSame('taille manquante', $view['bmi']['reason']);
        self::assertSame('taille manquante, sexe non renseigné', $view['predictedBodyWeight']['reason']);
        self::assertSame('taille manquante, sexe non renseigné', $view['tidalVolume']['reason']);
    }
}
