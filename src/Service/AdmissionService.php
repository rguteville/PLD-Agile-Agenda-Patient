<?php

declare(strict_types=1);

namespace Aura\Service;

use DateTimeImmutable;

/**
 * Règles métier de l'admission d'un patient (AURA-10).
 *
 * AURA-10.2 : contrôle serveur des champs. La validation est pure : aucun accès
 * à la base, aucune dépendance PDO, aucune sortie HTTP. L'insertion (AURA-10.4)
 * et le numéro de séjour (AURA-10.3) viendront compléter cette classe.
 */
class AdmissionService
{
    /** Valeurs de `sexe` acceptées (colonne Patient.sexe VARCHAR(1), cf. database/test.sql). */
    public const SEXES_AUTORISES = ['M', 'F'];

    /** Format attendu pour la date de naissance (AAAA-MM-JJ). */
    public const FORMAT_DATE = 'Y-m-d';

    /** @param DateTimeImmutable|null $aujourdhui date de référence (injectable pour les tests) */
    public function __construct(private ?DateTimeImmutable $aujourdhui = null)
    {
    }

    /**
     * Valide les données d'admission (RG1 : nom et prénom obligatoires, IPP facultatif).
     *
     * Clés attendues (celles du domaine) : nom, prenom, ipp, dateNaissance, sexe,
     * taille, poids, precautions. Une clé absente est traitée comme vide.
     * Les messages ne reprennent jamais la valeur saisie (données de santé).
     *
     * @param array<string, mixed> $donnees
     * @return array<string, string> erreurs indexées par champ ; [] si les données sont valides
     */
    public function valider(array $donnees): array
    {
        $erreurs = [];

        if ($this->texte($donnees['nom'] ?? null) === '') {
            $erreurs['nom'] = 'Le nom est obligatoire.';
        }
        if ($this->texte($donnees['prenom'] ?? null) === '') {
            $erreurs['prenom'] = 'Le prénom est obligatoire.';
        }

        // ipp : facultatif, conservé tel quel (trim uniquement) : aucune règle ici.

        $dateNaissance = $this->texte($donnees['dateNaissance'] ?? null);
        if ($dateNaissance !== '') {
            $erreurDate = $this->validerDateNaissance($dateNaissance);
            if ($erreurDate !== null) {
                $erreurs['dateNaissance'] = $erreurDate;
            }
        }

        $sexe = $this->texte($donnees['sexe'] ?? null);
        if ($sexe !== '' && !in_array($sexe, self::SEXES_AUTORISES, true)) {
            $erreurs['sexe'] = 'Le sexe doit être M ou F.';
        }

        $taille = $this->texte($donnees['taille'] ?? null);
        if ($taille !== '' && !$this->estStrictementPositif($taille)) {
            $erreurs['taille'] = 'La taille doit être un nombre strictement positif.';
        }

        $poids = $this->texte($donnees['poids'] ?? null);
        if ($poids !== '' && !$this->estStrictementPositif($poids)) {
            $erreurs['poids'] = 'Le poids doit être un nombre strictement positif.';
        }

        // precautions : texte libre facultatif, aucune règle.

        return $erreurs;
    }

    /** Message d'erreur si la date n'est pas au format AAAA-MM-JJ ou n'est pas strictement passée, sinon null. */
    private function validerDateNaissance(string $valeur): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::FORMAT_DATE, $valeur);
        $anomalies = DateTimeImmutable::getLastErrors();
        $avecAnomalies = $anomalies !== false
            && ($anomalies['warning_count'] > 0 || $anomalies['error_count'] > 0);

        // Le contrôle aller-retour rejette les dates inexistantes (ex. 2026-13-45, 1990-02-30)
        // que createFromFormat accepterait en les décalant.
        if ($date === false || $avecAnomalies || $date->format(self::FORMAT_DATE) !== $valeur) {
            return 'La date de naissance est invalide (format attendu : AAAA-MM-JJ).';
        }

        $aujourdhui = ($this->aujourdhui ?? new DateTimeImmutable())->setTime(0, 0);
        if ($date >= $aujourdhui) {
            return 'La date de naissance doit être antérieure à la date du jour.';
        }

        return null;
    }

    private function estStrictementPositif(string $valeur): bool
    {
        return is_numeric($valeur) && (float) $valeur > 0;
    }

    /**
     * Normalise une valeur saisie en chaîne nettoyée.
     * Les valeurs non scalaires (tableau envoyé par un formulaire forgé) sont traitées comme vides.
     */
    private function texte(mixed $valeur): string
    {
        if (is_string($valeur)) {
            return trim($valeur);
        }
        if (is_int($valeur) || is_float($valeur)) {
            return (string) $valeur;
        }

        return '';
    }
}
