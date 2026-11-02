<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AllergieDao;
use Aura\Dao\SejourDao;
use Aura\Dao\SubstanceDao;
use Aura\Domain\Allergie;
use Aura\Domain\Sejour;
use InvalidArgumentException;
use PDOException;

/**
 * Ajout et retrait des allergies connues d'un patient depuis le dossier de séjour (AURA-13.2).
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
        private SejourDao $sejourDao,
        private AllergieDao $allergieDao,
        private SubstanceDao $substanceDao
    ) {
    }

    /**
     * Enregistre une allergie pour le patient du séjour et renvoie son id.
     *
     * @param mixed $idSubstance valeur brute du formulaire : doit exister dans le référentiel (critère 1)
     * @param mixed $criticality valeur brute du formulaire : vide, ou l'une des Allergie::CRITICALITIES
     * @throws InvalidArgumentException séjour inconnu, allergène hors référentiel, criticité
     *                                  hors liste, ou allergie déjà enregistrée (critère 3)
     */
    public function add(int $idSejour, mixed $idSubstance, mixed $criticality): int
    {
        $sejour = $this->findSejour($idSejour);

        $idSubstance = self::text($idSubstance);
        if ($idSubstance === null) {
            throw new InvalidArgumentException('Choisissez un allergène dans la liste.');
        }
        $substance = $this->substanceDao->findById($idSubstance);
        if ($substance === null) {
            throw new InvalidArgumentException(self::UNKNOWN_SUBSTANCE_MESSAGE);
        }

        $criticality = self::text($criticality);
        if ($criticality !== null && !in_array($criticality, Allergie::CRITICALITIES, true)) {
            throw new InvalidArgumentException(
                'La criticité doit être choisie dans la liste : ' . implode(', ', Allergie::CRITICALITIES) . '.'
            );
        }

        // Première couche : message clair sans attendre la base.
        foreach ($this->allergieDao->findByPatient($sejour->getIdPatient()) as $existing) {
            if ($existing->getIdAllergene() === $substance->getIdSubstance()) {
                throw new InvalidArgumentException(self::DUPLICATE_MESSAGE);
            }
        }

        try {
            return $this->allergieDao->insert(new Allergie(
                idAllergie: null,
                idPatient: $sejour->getIdPatient(),
                idAllergene: $substance->getIdSubstance(),
                substance: $substance->getDescription(),
                criticite: $criticality
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
     * Retire une allergie du dossier du patient du séjour.
     *
     * @throws InvalidArgumentException séjour inconnu, ou allergie absente du dossier de ce patient
     */
    public function remove(int $idSejour, int $idAllergy): void
    {
        $sejour = $this->findSejour($idSejour);

        if (!$this->allergieDao->delete($idAllergy, $sejour->getIdPatient())) {
            throw new InvalidArgumentException('Cette allergie n\'est pas enregistrée pour ce patient.');
        }
    }

    private function findSejour(int $idSejour): Sejour
    {
        $sejour = $this->sejourDao->findById($idSejour);
        if ($sejour === null) {
            throw new InvalidArgumentException(sprintf('Aucun séjour ne porte l\'identifiant %d.', $idSejour));
        }

        return $sejour;
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
