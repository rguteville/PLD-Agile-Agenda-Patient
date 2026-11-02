<?php

declare(strict_types=1);

namespace Aura\Core;

/**
 * Dates saisies à la française (JJ/MM/AAAA) dans les formulaires, converties à l'entrée vers le format
 * des services et de la base (AAAA-MM-JJ). Retours d'usage du 2026-11-02 : le champ date natif du
 * navigateur n'accepte ni exemple grisé ni format imposé ; les formulaires utilisent un champ texte.
 *
 * Aucune validation ici : une saisie mal formée est rendue telle quelle, et le service la refuse
 * avec un message qui rappelle le format attendu.
 */
final class DateInput
{
    /** Format affiché et saisi. */
    public const DISPLAY_FORMAT = 'JJ/MM/AAAA';

    /** « 14/03/1958 » → « 1958-03-14 » ; toute autre saisie est renvoyée nettoyée, sans conversion. */
    public static function toIso(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m) === 1) {
            return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }

        return $value;
    }

    /** « 1958-03-14 » → « 14/03/1958 » ; toute autre valeur (déjà JJ/MM/AAAA, vide…) est renvoyée telle quelle. */
    public static function fromIso(?string $value): string
    {
        $value = trim((string) $value);
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $value, $m) === 1) {
            return sprintf('%s/%s/%s', $m[3], $m[2], $m[1]);
        }

        return $value;
    }
}
