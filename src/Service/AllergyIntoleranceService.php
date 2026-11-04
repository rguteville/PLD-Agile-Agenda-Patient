<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\PatientDao;
use Aura\Dao\SubstanceDao;
use Aura\Domain\AllergyIntolerance;
use Aura\Domain\EpisodeOfCare;
use InvalidArgumentException;
use PDOException;

/**
 * Ajout et retrait des allergies connues d'un patient depuis le dossier de séjour.
 *
 * Les formulaires envoient l'id du séjour affiché ; le patient est retrouvé ici, côté
 * serveur : un POST forgé ne peut pas rattacher une allergie à un autre patient.
 * Tous les contrôles sont faits ici, quoi que propose l'IHM (la liste déroulante
 * n'empêche pas d'envoyer n'importe quelle valeur avec curl ou les outils du navigateur).
 * Les refus sont des InvalidArgumentException au message affichable, comme l'admission.
 */
final class AllergyIntoleranceService
{
    public const DUPLICATE_MESSAGE = 'Cette allergie est déjà enregistrée pour ce patient.';
    public const UNKNOWN_SUBSTANCE_MESSAGE = 'Cet allergène ne figure pas dans la liste de référence.';

    /** Erreurs MariaDB : 1062 doublon (contrainte UNIQUE), 1452 clé étrangère absente. */
    private const DUPLICATE_ERROR = 1062;
    private const FOREIGN_KEY_ERROR = 1452;

    public function __construct(
        private EpisodeOfCareDao $episodeOfCareDao,
        private AllergyIntoleranceDao $allergyIntoleranceDao,
        private SubstanceDao $substanceDao,
        private ?PatientDao $patientDao = null
    ) {
    }

    /**
     * Enregistre une allergie pour le patient du séjour et renvoie son id.
     *
     * @param mixed $idSubstance valeur brute du formulaire : doit exister dans le référentiel (critère 1)
     * @param mixed $criticality valeur brute du formulaire : vide, ou l'une des AllergyIntolerance::CRITICALITIES
     * @throws InvalidArgumentException séjour inconnu, allergène hors référentiel, criticité
     *                                  hors liste, ou allergie déjà enregistrée (critère 3)
     * @throws PDOException autre erreur SQL que doublon ou clé étrangère
     */
    public function add(int $idEpisodeOfCare, mixed $idSubstance, mixed $criticality): int
    {
        $episodeOfCare = $this->findEpisodeOfCare($idEpisodeOfCare);

        $idSubstance = self::text($idSubstance);
        if ($idSubstance === null) {
            throw new InvalidArgumentException('Choisissez un allergène dans la liste.');
        }
        $substance = $this->substanceDao->findById($idSubstance);
        if ($substance === null) {
            throw new InvalidArgumentException(self::UNKNOWN_SUBSTANCE_MESSAGE);
        }

        $criticality = self::text($criticality);
        if ($criticality !== null && !in_array($criticality, AllergyIntolerance::CRITICALITIES, true)) {
            throw new InvalidArgumentException(
                'La criticité doit être choisie dans la liste : '
                . implode(', ', AllergyIntolerance::CRITICALITIES) . '.'
            );
        }

        // Première couche : message clair sans attendre la base.
        foreach ($this->allergyIntoleranceDao->findByPatient($episodeOfCare->getIdPatient()) as $existing) {
            if ($existing->getIdSubstance() === $substance->getIdSubstance()) {
                throw new InvalidArgumentException(self::DUPLICATE_MESSAGE);
            }
        }

        try {
            return $this->allergyIntoleranceDao->insert(new AllergyIntolerance(
                idAllergyIntolerance: null,
                idPatient: $episodeOfCare->getIdPatient(),
                idSubstance: $substance->getIdSubstance(),
                substance: $substance->getDescription(),
                criticality: $criticality
            ));
        } catch (PDOException $e) {
            // Seconde couche : condition de course (double clic, deux soignants en même temps).
            // Entre la vérification ci-dessus et l'INSERT, une autre requête a pu enregistrer
            // le même couple : la contrainte UNIQUE de la base est la garantie finale.
            $code = $e->errorInfo[1] ?? null;
            if ($code === self::DUPLICATE_ERROR) {
                throw new InvalidArgumentException(self::DUPLICATE_MESSAGE, 0, $e);
            }
            if ($code === self::FOREIGN_KEY_ERROR) {
                throw new InvalidArgumentException(self::UNKNOWN_SUBSTANCE_MESSAGE, 0, $e);
            }
            throw $e;
        }
    }

    /**
     * Retire une allergie du dossier du patient du séjour. Suppression PHYSIQUE (DELETE), exception
     * connue à la règle du soft delete : voir AllergyIntoleranceDao::delete().
     *
     * @throws InvalidArgumentException séjour inconnu, ou allergie absente du dossier de ce patient
     */
    public function remove(int $idEpisodeOfCare, int $idAllergy): void
    {
        $episodeOfCare = $this->findEpisodeOfCare($idEpisodeOfCare);

        if (!$this->allergyIntoleranceDao->delete($idAllergy, $episodeOfCare->getIdPatient())) {
            throw new InvalidArgumentException('Cette allergie n\'est pas enregistrée pour ce patient.');
        }
    }

    /**
     * Séjour affiché par le formulaire, dont on tire le patient.
     *
     * @throws InvalidArgumentException si aucun séjour ne porte cet identifiant
     */
    private function findEpisodeOfCare(int $idEpisodeOfCare): EpisodeOfCare
    {
        $episodeOfCare = $this->episodeOfCareDao->findById($idEpisodeOfCare);
        if ($episodeOfCare === null) {
            throw new InvalidArgumentException(sprintf('Aucun séjour ne porte l\'identifiant %d.', $idEpisodeOfCare));
        }
        // Le dossier d'un patient supprimé de la liste n'est plus modifiable.
        if ($this->patientDao !== null && $this->patientDao->findById($episodeOfCare->getIdPatient()) === null) {
            throw new InvalidArgumentException(
                'Ce patient a été supprimé de la liste : son dossier n\'est plus modifiable.'
            );
        }

        return $episodeOfCare;
    }

    /** Valeur saisie nettoyée : null si absente, vide ou non textuelle (tableau forgé). */
    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
