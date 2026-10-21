<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\SejourDao;
use Aura\Domain\Sejour;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Gestion des séjours d'un patient dans le service : ouverture (admission),
 */
class SejourService
{
    public function __construct(private SejourDao $sejourDao)
    {
    }

    public function getSejour(int $idSejour): Sejour
    {
        $sejour = $this->sejourDao->findById($idSejour);

        if ($sejour === null) {
            throw new InvalidArgumentException("Séjour $idSejour introuvable");
        }

        return $sejour;
    }

    public function getSejourByNumber(string $numSejour): Sejour
    {
        $sejour = $this->sejourDao->findByNumber($numSejour);

        if ($sejour === null) {
            throw new InvalidArgumentException("Séjour n°$numSejour introuvable");
        }

        return $sejour;
    }

    /**
     * Séjours d'un patient, du plus récent au plus ancien (consultation des séjours précédents).
     *
     * @return Sejour[]
     */
    public function getSejoursPatient(int $idPatient): array
    {
        return $this->sejourDao->findByPatient($idPatient);
    }

    /**
     * Patients actuellement présents dans le service.
     *
     * @return Sejour[]
     */
    public function getSejoursEnCours(): array
    {
        return $this->sejourDao->findEnCours();
    }

    /**
     * Ouvre un séjour : la date de prise en charge est celle du moment, le numéro est généré.
     * Poids (kg), taille (cm) et précautions sont les mesures relevées à l'admission (AURA-10.1),
     * déjà validées par l'appelant (AdmissionService::valider()).
     *
     * @throws InvalidArgumentException si le patient a déjà un séjour en cours ou si l'emplacement est pris
     */
    public function ouvrirSejour(
        int $idPatient,
        ?string $numLit = null,
        ?string $numBox = null,
        ?float $poids = null,
        ?float $taille = null,
        ?string $precautions = null
    ): Sejour {
        foreach ($this->sejourDao->findByPatient($idPatient) as $sejour) {
            if ($sejour->estEnCours()) {
                throw new InvalidArgumentException(
                    "Le patient a déjà un séjour en cours (n° {$sejour->getNumSejour()})"
                );
            }
        }

        $numLit = $this->emplacement($numLit, 'lit');
        $numBox = $this->emplacement($numBox, 'box');
        $this->verifierEmplacementLibre($numLit, $numBox, null);

        return $this->sejourDao->insert(new Sejour(
            idSejour: null,
            idPatient: $idPatient,
            numSejour: '',
            dateAdmission: new DateTimeImmutable(),
            numLit: $numLit,
            numBox: $numBox,
            poids: $poids,
            taille: $taille,
            precautions: $precautions
        ));
    }

    /**
     * Change le lit et/ou le box d'un séjour en cours (null = non attribué).
     *
     * @throws InvalidArgumentException si le séjour est terminé ou l'emplacement déjà occupé
     */
    public function changerEmplacement(int $idSejour, ?string $numLit, ?string $numBox): Sejour
    {
        $sejour = $this->getSejour($idSejour);
        if (!$sejour->estEnCours()) {
            throw new InvalidArgumentException('Impossible de changer l\'emplacement d\'un séjour terminé');
        }

        $numLit = $this->emplacement($numLit, 'lit');
        $numBox = $this->emplacement($numBox, 'box');
        $this->verifierEmplacementLibre($numLit, $numBox, $idSejour);

        $this->sejourDao->updateEmplacement($idSejour, $numLit, $numBox);

        return $this->getSejour($idSejour);
    }

    /**
     * Enregistre la sortie du patient (maintenant par défaut). Le lit et le box sont libérés.
     *
     * @throws InvalidArgumentException si le séjour est déjà terminé ou la date incohérente
     */
    public function cloturerSejour(int $idSejour, ?DateTimeImmutable $dateSortie = null): Sejour
    {
        $sejour = $this->getSejour($idSejour);
        $dateSortie ??= new DateTimeImmutable();

        if (!$sejour->estEnCours()) {
            throw new InvalidArgumentException("Le séjour n° {$sejour->getNumSejour()} est déjà terminé");
        }
        if ($dateSortie < $sejour->getDateAdmission()) {
            throw new InvalidArgumentException('La date de sortie ne peut pas précéder la date d\'admission');
        }
        if ($dateSortie > new DateTimeImmutable()) {
            throw new InvalidArgumentException('La date de sortie ne peut pas être dans le futur');
        }

        $this->sejourDao->updateSortie($idSejour, $dateSortie);

        return $this->getSejour($idSejour);
    }

    /** Un lit ou un box ne peut être occupé que par un seul séjour en cours. */
    private function verifierEmplacementLibre(?string $numLit, ?string $numBox, ?int $idSejourIgnore): void
    {
        foreach ($this->sejourDao->findEnCours() as $autre) {
            if ($autre->getIdSejour() === $idSejourIgnore) {
                continue;
            }
            $occupant = "séjour n° {$autre->getNumSejour()}";
            if ($numLit !== null && $autre->getNumLit() === $numLit) {
                throw new InvalidArgumentException("Le lit $numLit est déjà occupé ($occupant)");
            }
            if ($numBox !== null && $autre->getNumBox() === $numBox) {
                throw new InvalidArgumentException("Le box $numBox est déjà occupé ($occupant)");
            }
        }
    }

    /** Nettoie un numéro de lit / box saisi : null si vide, 20 caractères maximum. */
    private function emplacement(?string $valeur, string $libelle): ?string
    {
        $valeur = $valeur !== null ? trim($valeur) : null;
        if ($valeur === null || $valeur === '') {
            return null;
        }
        if (strlen($valeur) > 20) {
            throw new InvalidArgumentException("Le numéro de $libelle ne doit pas dépasser 20 caractères");
        }

        return $valeur;
    }
}
