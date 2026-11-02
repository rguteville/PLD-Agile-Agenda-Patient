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
    public function testImcDunAdulteDe70KgPour175Cm(): void
    {
        // 70 / 1,75² = 22,857…
        self::assertEqualsWithDelta(22.857, DerivedMeasuresService::bmi(70.0, 175.0), 0.001);
    }

    public function testImcNullSansPoidsOuSansTailleOuValeurNulle(): void
    {
        self::assertNull(DerivedMeasuresService::bmi(null, 175.0));
        self::assertNull(DerivedMeasuresService::bmi(70.0, null));
        self::assertNull(DerivedMeasuresService::bmi(70.0, 0.0));
    }

    public function testSurfaceCorporelleMostellerPour70KgEt170Cm(): void
    {
        // √(170 × 70 / 3600) = √3,3056 = 1,818…
        self::assertEqualsWithDelta(1.818, DerivedMeasuresService::bodySurfaceArea(70.0, 170.0), 0.001);
    }

    public function testPoidsTheoriqueArdsnetHommeDe175Cm(): void
    {
        // 175 cm = 68,898 pouces ; 50 + 2,3 × (68,898 − 60) = 70,47
        self::assertEqualsWithDelta(70.47, DerivedMeasuresService::predictedBodyWeight('M', 175.0), 0.01);
    }

    public function testPoidsTheoriqueArdsnetFemmeDe160Cm(): void
    {
        // 160 cm = 62,992 pouces ; 45,5 + 2,3 × (62,992 − 60) = 52,38
        self::assertEqualsWithDelta(52.38, DerivedMeasuresService::predictedBodyWeight('F', 160.0), 0.01);
    }

    public function testPoidsTheoriqueNullSansSexeConnu(): void
    {
        self::assertNull(DerivedMeasuresService::predictedBodyWeight(null, 175.0));
        self::assertNull(DerivedMeasuresService::predictedBodyWeight('X', 175.0));
        self::assertNull(DerivedMeasuresService::predictedBodyWeight('M', null));
    }

    public function testPoidsTheoriqueNullSiLaFormuleDonneUnPoidsNegatif(): void
    {
        // Taille aberrante : la formule donnerait une valeur négative, on n'affiche rien.
        self::assertNull(DerivedMeasuresService::predictedBodyWeight('M', 50.0));
    }

    public function testVtTheoriqueEntre6Et8MlParKg(): void
    {
        self::assertSame([420.0, 560.0], DerivedMeasuresService::tidalVolumeRange(70.0));
        self::assertNull(DerivedMeasuresService::tidalVolumeRange(null));
    }

    public function testPresentationCompleteALaFrancaise(): void
    {
        $vue = DerivedMeasuresService::present(70.0, 175.0, 'M');

        self::assertSame('22,9 kg/m²', $vue['bmi']['value']);
        self::assertSame('1,84 m²', $vue['bodySurfaceArea']['value']);
        self::assertSame('70,5 kg', $vue['predictedBodyWeight']['value']);
        self::assertSame('423 ml à 564 ml', $vue['tidalVolume']['value']);
        self::assertNull($vue['bmi']['reason']);
    }

    public function testPresentationExpliquePourquoiUneValeurManque(): void
    {
        $vue = DerivedMeasuresService::present(70.0, null, null);

        self::assertNull($vue['bmi']['value']);
        self::assertSame('taille manquante', $vue['bmi']['reason']);
        self::assertSame('taille manquante, sexe non renseigné', $vue['predictedBodyWeight']['reason']);
        self::assertSame('taille manquante, sexe non renseigné', $vue['tidalVolume']['reason']);
    }
}
