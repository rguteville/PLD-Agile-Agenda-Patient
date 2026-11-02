<?php

declare(strict_types=1);

namespace Aura\Service;

/**
 * Valeurs calculées à partir des mesures d'admission (affichées, jamais saisies). Service PUR.
 *
 * Chaque formule cite sa source (règle du brief : aucune formule non sourcée). Les résultats
 * sont des aides à la décision ; le prescripteur reste responsable des réglages.
 */
final class DerivedMeasuresService
{
    /** Volume courant protecteur, en ml par kg de poids théorique : de 8 (réglage initial) à 6 (cible). */
    public const TIDAL_VOLUME_MIN_ML_PER_KG = 6;
    public const TIDAL_VOLUME_MAX_ML_PER_KG = 8;

    /** Un pouce vaut exactement 2,54 cm (définition internationale du yard, 1959). */
    private const CM_PER_INCH = 2.54;

    /**
     * Indice de masse corporelle = poids (kg) / taille² (m²).
     * Source : OMS, « Obesity and overweight », https://www.who.int/news-room/fact-sheets/detail/obesity-and-overweight
     */
    public static function bmi(?float $weightKg, ?float $heightCm): ?float
    {
        if ($weightKg === null || $heightCm === null || $weightKg <= 0 || $heightCm <= 0) {
            return null;
        }
        $heightM = $heightCm / 100;

        return $weightKg / ($heightM * $heightM);
    }

    /**
     * Surface corporelle (m²), formule de Mosteller : √(taille (cm) × poids (kg) / 3600).
     * Source : Mosteller RD. Simplified calculation of body-surface area. N Engl J Med.
     * 1987;317(17):1098. https://pubmed.ncbi.nlm.nih.gov/3657876/
     */
    public static function bodySurfaceArea(?float $weightKg, ?float $heightCm): ?float
    {
        if ($weightKg === null || $heightCm === null || $weightKg <= 0 || $heightCm <= 0) {
            return null;
        }

        return sqrt($heightCm * $weightKg / 3600);
    }

    /**
     * Poids théorique (predicted body weight, kg) du protocole ARDSNet :
     *   hommes : 50 + 2,3 × [taille (pouces) − 60] ; femmes : 45,5 + 2,3 × [taille (pouces) − 60].
     * Source : NIH NHLBI ARDS Clinical Network, Mechanical Ventilation Protocol Summary (2008),
     * http://www.ardsnet.org/files/ventilator_protocol_2008-07.pdf
     * Null si le sexe n'est pas M/F, si la taille manque ou si le résultat n'est pas positif.
     */
    public static function predictedBodyWeight(?string $sex, ?float $heightCm): ?float
    {
        if ($heightCm === null || $heightCm <= 0 || !in_array($sex, ['M', 'F'], true)) {
            return null;
        }
        $heightInches = $heightCm / self::CM_PER_INCH;
        $base = $sex === 'M' ? 50.0 : 45.5;
        $pbw = $base + 2.3 * ($heightInches - 60);

        return $pbw > 0 ? $pbw : null;
    }

    /**
     * Volume courant théorique (ml) : 6 à 8 ml par kg de poids théorique.
     * Source : protocole ARDSNet ci-dessus (VT initial 8 ml/kg PBW, réduit à 6 ml/kg PBW).
     *
     * @return array{0: float, 1: float}|null bornes basse et haute, null sans poids théorique
     */
    public static function tidalVolumeRange(?float $predictedBodyWeightKg): ?array
    {
        if ($predictedBodyWeightKg === null || $predictedBodyWeightKg <= 0) {
            return null;
        }

        return [
            self::TIDAL_VOLUME_MIN_ML_PER_KG * $predictedBodyWeightKg,
            self::TIDAL_VOLUME_MAX_ML_PER_KG * $predictedBodyWeightKg,
        ];
    }

    /**
     * Vue-modèle prête à afficher : chaque valeur est une chaîne à la française ou null si non
     * calculable, avec le motif (« taille manquante ») pour que le soignant sache pourquoi.
     *
     * @return array<string, array{value: ?string, reason: ?string}>
     */
    public static function present(?float $weightKg, ?float $heightCm, ?string $sex): array
    {
        $missing = [];
        if ($weightKg === null) {
            $missing[] = 'poids manquant';
        }
        if ($heightCm === null) {
            $missing[] = 'taille manquante';
        }
        $missingSex = in_array($sex, ['M', 'F'], true) ? [] : ['sexe non renseigné'];

        $bmi = self::bmi($weightKg, $heightCm);
        $bsa = self::bodySurfaceArea($weightKg, $heightCm);
        $pbw = self::predictedBodyWeight($sex, $heightCm);
        $vt = self::tidalVolumeRange($pbw);
        $pbwMissing = array_merge($heightCm === null ? ['taille manquante'] : [], $missingSex);

        return [
            'bmi' => [
                'value' => $bmi !== null ? self::number($bmi, 1) . ' kg/m²' : null,
                'reason' => $bmi !== null ? null : implode(', ', $missing),
            ],
            'bodySurfaceArea' => [
                'value' => $bsa !== null ? self::number($bsa, 2) . ' m²' : null,
                'reason' => $bsa !== null ? null : implode(', ', $missing),
            ],
            'predictedBodyWeight' => [
                'value' => $pbw !== null ? self::number($pbw, 1) . ' kg' : null,
                'reason' => $pbw !== null ? null : implode(', ', $pbwMissing),
            ],
            'tidalVolume' => [
                'value' => $vt !== null
                    ? sprintf('%s ml à %s ml', self::number($vt[0], 0), self::number($vt[1], 0))
                    : null,
                'reason' => $vt !== null ? null : implode(', ', $pbwMissing),
            ],
        ];
    }

    /** Nombre à la française (virgule décimale, pas de séparateur de milliers). */
    private static function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '');
    }
}
