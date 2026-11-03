<?php

declare(strict_types=1);

namespace Aura\Service;

use DateTimeImmutable;

/**
 * Validation et normalisation des champs complémentaires décrits par Aura\Domain\RecordFields.
 *
 * Service PUR (pas de PDO, pas de HTTP) partagé par l'admission et par toute autre écriture de
 * ces champs : une seule règle par champ, quel que soit l'écran. Tous les champs sont facultatifs ;
 * une valeur vide est toujours acceptée et devient null.
 */
final class FieldValidationService
{
    /** Format attendu pour un champ de type date (AAAA-MM-JJ). */
    public const DATE_FORMAT = 'Y-m-d';

    /** Téléphone : chiffres, espaces, +, parenthèses, points et tirets, 6 à 20 caractères. */
    private const PHONE_PATTERN = '/^[0-9 +().-]{6,20}$/';

    /**
     * @param DateTimeImmutable|null $today date de référence, réservée aux règles futures sur les dates
     *        (aucune borne aujourd'hui : une date de chirurgie peut être programmée, donc future)
     */
    public function __construct(private ?DateTimeImmutable $today = null)
    {
    }

    /**
     * Valide les valeurs contre le catalogue. Seules les clés du catalogue sont examinées :
     * une clé inconnue est ignorée (jamais une erreur, jamais une donnée écrite).
     *
     * @param array<string, array<string, mixed>> $specs catalogue (RecordFields::PATIENT ou ::EPISODE)
     * @param array<string, mixed> $values valeurs brutes, indexées par nom de champ
     * @return array<string, string> erreurs indexées par nom de champ, [] si tout est valide
     */
    public function validate(array $specs, array $values): array
    {
        $errors = [];
        foreach ($specs as $field => $spec) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $value = $this->normalizeValue($values[$field]);
            if ($value === null) {
                continue;
            }
            $error = $this->validateValue($spec, $value);
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        return $errors;
    }

    /**
     * Valeurs nettoyées, prêtes à écrire : espaces retirés, fins de ligne en \n, vide = null.
     * Ne renvoie que les champs du catalogue présents dans $values (un champ absent n'est pas
     * « effacé » : l'appelant décide s'il veut toutes les clés).
     *
     * @param array<string, array<string, mixed>> $specs
     * @param array<string, mixed> $values
     * @return array<string, ?string>
     */
    public function normalize(array $specs, array $values): array
    {
        $clean = [];
        foreach ($specs as $field => $spec) {
            if (array_key_exists($field, $values)) {
                $clean[$field] = $this->normalizeValue($values[$field]);
            }
        }

        return $clean;
    }

    /** Libellé utilisé dans les messages d'erreur (jamais la valeur saisie : données de santé). */
    private function validateValue(array $spec, string $value): ?string
    {
        $label = (string) ($spec['label'] ?? 'champ');
        $max = $spec['max'] ?? null;
        if ($max !== null && mb_strlen($value) > $max) {
            return sprintf('« %s » ne doit pas dépasser %d caractères.', $label, $max);
        }

        switch ($spec['type'] ?? 'text') {
            case 'select':
                if (!array_key_exists($value, $spec['options'] ?? [])) {
                    return sprintf('« %s » : valeur hors de l\'échelle proposée.', $label);
                }
                break;
            case 'date':
                if (!$this->isValidDate($value)) {
                    return sprintf('« %s » : date invalide (format attendu : JJ/MM/AAAA).', $label);
                }
                break;
            case 'email':
                if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    return sprintf('« %s » : adresse e-mail invalide.', $label);
                }
                break;
            case 'tel':
                if (preg_match(self::PHONE_PATTERN, $value) !== 1) {
                    return sprintf(
                        '« %s » : numéro de téléphone invalide (chiffres, espaces, +, parenthèses, points, tirets).',
                        $label
                    );
                }
                break;
            case 'number':
                if (!is_numeric($value)) {
                    return sprintf('« %s » doit être un nombre.', $label);
                }
                $number = (float) $value;
                if (isset($spec['min']) && $number < (float) $spec['min']) {
                    return sprintf('« %s » doit être supérieur ou égal à %s.', $label, (string) $spec['min']);
                }
                if (isset($spec['maxValue']) && $number > (float) $spec['maxValue']) {
                    return sprintf('« %s » doit être inférieur ou égal à %s.', $label, (string) $spec['maxValue']);
                }
                break;
            default:
                // text / textarea : seule la longueur est contrôlée.
                break;
        }

        return null;
    }

    /** Date existante au format AAAA-MM-JJ (le contrôle aller-retour rejette 2026-02-30). */
    private function isValidDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);

        return $date !== false && $date->format(self::DATE_FORMAT) === $value;
    }

    /** Chaîne nettoyée ou null ; une valeur non scalaire (tableau forgé) est traitée comme vide. */
    private function normalizeValue(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }
}
