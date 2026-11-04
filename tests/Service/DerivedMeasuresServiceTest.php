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
    public function testBmiOfAdult70KgFor175Cm(): void
    {
        // 70 / 1,75² = 22,857…
        self::assertEqualsWithDelta(22.857, DerivedMeasuresService::bmi(70.0, 175.0), 0.001);
    }

    public function testBmiNullWithoutWeightOrHeightOrZeroValue(): void
    {
        self::assertNull(DerivedMeasuresService::bmi(null, 175.0));
        self::assertNull(DerivedMeasuresService::bmi(70.0, null));
        self::assertNull(DerivedMeasuresService::bmi(70.0, 0.0));
    }

    public function testMostellerBodySurfaceFor70KgAnd170Cm(): void
    {
        // √(170 × 70 / 3600) = √3,3056 = 1,818…
        self::assertEqualsWithDelta(1.818, DerivedMeasuresService::bodySurfaceArea(70.0, 170.0), 0.001);
    }

    public function testArdsnetPredictedWeightMan175Cm(): void
    {
        // 175 cm = 68,898 pouces ; 50 + 2,3 × (68,898 − 60) = 70,47
        self::assertEqualsWithDelta(70.47, DerivedMeasuresService::predictedBodyWeight('M', 175.0), 0.01);
    }

    public function testArdsnetPredictedWeightWoman160Cm(): void
    {
        // 160 cm = 62,992 pouces ; 45,5 + 2,3 × (62,992 − 60) = 52,38
        self::assertEqualsWithDelta(52.38, DerivedMeasuresService::predictedBodyWeight('F', 160.0), 0.01);
    }

    public function testPredictedWeightNullWithoutKnownSex(): void
    {
        self::assertNull(DerivedMeasuresService::predictedBodyWeight(null, 175.0));
        self::assertNull(DerivedMeasuresService::predictedBodyWeight('X', 175.0));
        self::assertNull(DerivedMeasuresService::predictedBodyWeight('M', null));
    }

    public function testPredictedWeightNullIfFormulaGivesNegativeWeight(): void
    {
        // Taille aberrante : la formule donnerait une valeur négative, on n'affiche rien.
        self::assertNull(DerivedMeasuresService::predictedBodyWeight('M', 50.0));
    }

    public function testPresentationExplainsOutOfRangeHeight(): void
    {
        $view = DerivedMeasuresService::present(20.0, 90.0, 'M');

        self::assertNull($view['predictedBodyWeight']['value']);
        self::assertSame(
            'taille hors du domaine de la formule ARDSNet (adulte)',
            $view['predictedBodyWeight']['reason']
        );
        self::assertNotNull($view['bmi']['value'], 'l\'IMC reste calculable');
    }

    public function testPredictedTidalVolumeBetween6And8MlPerKg(): void
    {
        self::assertSame([420.0, 560.0], DerivedMeasuresService::tidalVolumeRange(70.0));
        self::assertNull(DerivedMeasuresService::tidalVolumeRange(null));
    }

    public function testFullFrenchStylePresentation(): void
    {
        $view = DerivedMeasuresService::present(70.0, 175.0, 'M');

        self::assertSame('22,9 kg/m²', $view['bmi']['value']);
        self::assertSame('1,84 m²', $view['bodySurfaceArea']['value']);
        self::assertSame('70,5 kg', $view['predictedBodyWeight']['value']);
        self::assertSame('423 ml à 564 ml', $view['tidalVolume']['value']);
        self::assertNull($view['bmi']['reason']);
    }

    public function testPresentationExplainsWhyValueIsMissing(): void
    {
        $view = DerivedMeasuresService::present(70.0, null, null);

        self::assertNull($view['bmi']['value']);
        self::assertSame('taille manquante', $view['bmi']['reason']);
        self::assertSame('taille manquante, sexe non renseigné', $view['predictedBodyWeight']['reason']);
        self::assertSame('taille manquante, sexe non renseigné', $view['tidalVolume']['reason']);
    }
}
