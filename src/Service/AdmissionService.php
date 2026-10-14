<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\Patient;
use Aura\Domain\Sejour;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * Règles métier de l'admission d'un patient (AURA-10).
 *
 * AURA-10.2 : contrôle serveur des champs. La validation est pure : aucun accès
 * à la base, aucune dépendance PDO, aucune sortie HTTP.
 * AURA-10.4 : admettre() enregistre le patient (ou le retrouve par son IPP) et
 * ouvre son séjour (numéro généré, AURA-10.3) dans une seule transaction.
 */
class AdmissionService
{
    /** Valeurs de `sexe` acceptées (colonne Patient.sexe VARCHAR(1), cf. database/test.sql). */
    public const SEXES_AUTORISES = ['M', 'F'];

    /** Format attendu pour la date de naissance (AAAA-MM-JJ). */
    public const FORMAT_DATE = 'Y-m-d';

    /** IPP : 6 à 9 chiffres (colonne Patient.ipp INT avec CHECK entre 100000 et 999999999). */
    public const MOTIF_IPP = '/^\d{6,9}$/';

    /**
     * @param DateTimeImmutable|null $aujourdhui date de référence (injectable pour les tests)
     * @param PatientDao|null $patientDao accès aux patients, requis par admettre() seulement
     * @param SejourService|null $sejourService ouverture du séjour, requis par admettre() seulement
     * @param Transaction|null $transaction patient + séjour validés ensemble, requis par admettre() seulement
     */
    public function __construct(
        private ?DateTimeImmutable $aujourdhui = null,
        private ?PatientDao $patientDao = null,
        private ?SejourService $sejourService = null,
        private ?Transaction $transaction = null
    ) {
    }

    /**
     * Admet un patient : validation, puis insertion du patient (ou réutilisation du
     * patient déjà connu sous cet IPP) et ouverture de son séjour, le tout dans une
     * transaction. Si le séjour est refusé (patient déjà hospitalisé, lit occupé…),
     * rien n'est écrit : aucun patient orphelin.
     *
     * Les champs taille, poids et précautions sont validés mais pas enregistrés :
     * absents du schéma V1 (voir AURA-14, hors périmètre).
     *
     * @param array<string, mixed> $donnees clés du domaine (voir valider())
     * @return Sejour le séjour ouvert, avec son identifiant et son numéro
     * @throws InvalidArgumentException données invalides ou admission refusée (message affichable)
     * @throws LogicException si le service a été construit sans accès à la base
     */
    public function admettre(array $donnees): Sejour
    {
        if ($this->patientDao === null || $this->sejourService === null || $this->transaction === null) {
            throw new LogicException('AdmissionService::admettre() requiert PatientDao, SejourService et Transaction.');
        }

        $erreurs = $this->valider($donnees);
        if ($erreurs !== []) {
            throw new InvalidArgumentException(implode(' ', $erreurs));
        }

        $patient = $this->construirePatient($donnees);
        $patientDao = $this->patientDao;
        $sejourService = $this->sejourService;

        return $this->transaction->run(function () use ($patient, $patientDao, $sejourService): Sejour {
            $existant = $patient->getIpp() !== null ? $patientDao->findByIpp($patient->getIpp()) : null;
            $idPatient = $existant?->getIdPatient() ?? $patientDao->insert($patient);

            return $sejourService->ouvrirSejour($idPatient);
        });
    }

    /**
     * Construit l'entité à partir de données validées (champs vides = null).
     *
     * @param array<string, mixed> $donnees
     */
    private function construirePatient(array $donnees): Patient
    {
        $ipp = $this->texte($donnees['ipp'] ?? null);
        $dateNaissance = $this->texte($donnees['dateNaissance'] ?? null);
        $sexe = $this->texte($donnees['sexe'] ?? null);

        return new Patient(
            idPatient: null,
            ipp: $ipp !== '' ? $ipp : null,
            nom: $this->texte($donnees['nom'] ?? null),
            prenom: $this->texte($donnees['prenom'] ?? null),
            dateNaissance: $dateNaissance !== ''
                ? DateTimeImmutable::createFromFormat('!' . self::FORMAT_DATE, $dateNaissance) ?: null
                : null,
            sexe: $sexe !== '' ? $sexe : null
        );
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

        // ipp : facultatif (RG1) ; s'il est saisi, il doit tenir dans la colonne INT (6 à 9 chiffres).
        $ipp = $this->texte($donnees['ipp'] ?? null);
        if ($ipp !== '' && preg_match(self::MOTIF_IPP, $ipp) !== 1) {
            $erreurs['ipp'] = "L'IPP doit contenir entre 6 et 9 chiffres.";
        }

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
