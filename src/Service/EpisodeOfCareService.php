<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\EpisodeOfCareDao;
use Aura\Domain\EpisodeOfCare;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Gestion des séjours (EpisodeOfCare) : lecture, ouverture à l'admission (AURA-10, numéro AAAA-NNNNNN
 * généré par EpisodeOfCareDao::insert(), AURA-10.3), changement de lit ou de box, et sortie (fin de
 * séjour ou décès, appelée par RecordSectionService). Règles : un seul séjour en cours par patient, un
 * lit ou un box occupé par un seul séjour en cours, sortie ni avant l'admission ni dans le futur.
 */
class EpisodeOfCareService
{
    public function __construct(private EpisodeOfCareDao $episodeOfCareDao)
    {
    }

    /**
     * Séjour par son identifiant interne.
     *
     * @throws InvalidArgumentException si le séjour n'existe pas
     */
    public function getEpisodeOfCare(int $idEpisodeOfCare): EpisodeOfCare
    {
        $episodeOfCare = $this->episodeOfCareDao->findById($idEpisodeOfCare);

        if ($episodeOfCare === null) {
            throw new InvalidArgumentException("Séjour $idEpisodeOfCare introuvable");
        }

        return $episodeOfCare;
    }

    /**
     * Séjour par son numéro (AAAA-NNNNNN).
     *
     * @throws InvalidArgumentException si aucun séjour ne porte ce numéro
     */
    public function getEpisodeOfCareByNumber(string $episodeNumber): EpisodeOfCare
    {
        $episodeOfCare = $this->episodeOfCareDao->findByNumber($episodeNumber);

        if ($episodeOfCare === null) {
            throw new InvalidArgumentException("Séjour n°$episodeNumber introuvable");
        }

        return $episodeOfCare;
    }

    /**
     * Ouvre un séjour : la date de prise en charge est celle du moment, le numéro est généré.
     * Poids (kg), taille (cm) et précautions sont les mesures relevées à l'admission (AURA-10.1),
     * déjà validées par l'appelant (AdmissionService::validate()), comme le service hospitalier
     * et les champs complémentaires ($details, clés de RecordFields::EPISODE). Le médecin référent
     * (null = à désigner) a lui aussi été contrôlé contre la liste des médecins par l'appelant.
     *
     * @param array<string, ?string> $details
     * @throws InvalidArgumentException si le patient a déjà un séjour en cours ou si l'emplacement est pris
     */
    public function openEpisodeOfCare(
        int $idPatient,
        ?string $bedNumber = null,
        ?string $boxNumber = null,
        ?float $weight = null,
        ?float $height = null,
        ?string $precautions = null,
        ?string $idService = null,
        array $details = [],
        ?string $idReferringPractitioner = null
    ): EpisodeOfCare {
        foreach ($this->episodeOfCareDao->findByPatient($idPatient) as $episodeOfCare) {
            if ($episodeOfCare->isOngoing()) {
                throw new InvalidArgumentException(
                    "Le patient a déjà un séjour en cours (n° {$episodeOfCare->getEpisodeNumber()})"
                );
            }
        }

        $bedNumber = $this->location($bedNumber, 'bed');
        $boxNumber = $this->location($boxNumber, 'box');
        $this->checkLocationFree($bedNumber, $boxNumber, null);

        return $this->episodeOfCareDao->insert(new EpisodeOfCare(
            idEpisodeOfCare: null,
            idPatient: $idPatient,
            episodeNumber: '',
            admissionDate: new DateTimeImmutable(),
            bedNumber: $bedNumber,
            boxNumber: $boxNumber,
            idService: $idService,
            weight: $weight,
            height: $height,
            precautions: $precautions,
            details: $details,
            idReferringPractitioner: $idReferringPractitioner
        ));
    }

    /**
     * Change le lit et/ou le box d'un séjour en cours (null = non attribué).
     *
     * @throws InvalidArgumentException si le séjour est terminé ou l'emplacement déjà occupé
     */
    public function changeLocation(int $idEpisodeOfCare, ?string $bedNumber, ?string $boxNumber): EpisodeOfCare
    {
        $episodeOfCare = $this->getEpisodeOfCare($idEpisodeOfCare);
        if (!$episodeOfCare->isOngoing()) {
            throw new InvalidArgumentException('Impossible de changer l\'emplacement d\'un séjour terminé');
        }

        $bedNumber = $this->location($bedNumber, 'bed');
        $boxNumber = $this->location($boxNumber, 'box');
        $this->checkLocationFree($bedNumber, $boxNumber, $idEpisodeOfCare);

        $this->episodeOfCareDao->updateLocation($idEpisodeOfCare, $bedNumber, $boxNumber);

        return $this->getEpisodeOfCare($idEpisodeOfCare);
    }

    /**
     * Enregistre la sortie du patient (maintenant par défaut). Le lit et le box sont libérés.
     *
     * @throws InvalidArgumentException si le séjour est déjà terminé ou la date incohérente
     */
    public function closeEpisodeOfCare(int $idEpisodeOfCare, ?DateTimeImmutable $dischargeDate = null): EpisodeOfCare
    {
        $episodeOfCare = $this->getEpisodeOfCare($idEpisodeOfCare);
        $dischargeDate ??= new DateTimeImmutable();

        if (!$episodeOfCare->isOngoing()) {
            throw new InvalidArgumentException("Le séjour n° {$episodeOfCare->getEpisodeNumber()} est déjà terminé");
        }
        if ($dischargeDate < $episodeOfCare->getAdmissionDate()) {
            throw new InvalidArgumentException('La date de sortie ne peut pas précéder la date d\'admission');
        }
        if ($dischargeDate > new DateTimeImmutable()) {
            throw new InvalidArgumentException('La date de sortie ne peut pas être dans le futur');
        }

        if (!$this->episodeOfCareDao->updateDischarge($idEpisodeOfCare, $dischargeDate)) {
            throw new InvalidArgumentException("Le séjour n° {$episodeOfCare->getEpisodeNumber()} est déjà terminé");
        }

        return $this->getEpisodeOfCare($idEpisodeOfCare);
    }

    /**
     * Un lit ou un box ne peut être occupé que par un seul séjour en cours.
     *
     * @param int|null $ignoredEpisodeId séjour qui change d'emplacement (sa place actuelle ne compte pas)
     * @throws InvalidArgumentException si le lit ou le box est déjà occupé
     */
    private function checkLocationFree(?string $bedNumber, ?string $boxNumber, ?int $ignoredEpisodeId): void
    {
        foreach ($this->episodeOfCareDao->findOngoing() as $other) {
            if ($other->getIdEpisodeOfCare() === $ignoredEpisodeId) {
                continue;
            }
            $occupant = "séjour n° {$other->getEpisodeNumber()}";
            if ($bedNumber !== null && $other->getBedNumber() === $bedNumber) {
                throw new InvalidArgumentException("Le lit $bedNumber est déjà occupé ($occupant)");
            }
            if ($boxNumber !== null && $other->getBoxNumber() === $boxNumber) {
                throw new InvalidArgumentException("Le box $boxNumber est déjà occupé ($occupant)");
            }
        }
    }

    /**
     * Nettoie un numéro de lit ou de box saisi : null si vide, 20 caractères maximum.
     *
     * @param string $label 'bed' ou 'box', repris tel quel dans le message d'erreur
     * @throws InvalidArgumentException si le numéro dépasse 20 caractères
     */
    private function location(?string $value, string $label): ?string
    {
        $value = $value !== null ? trim($value) : null;
        if ($value === null || $value === '') {
            return null;
        }
        if (strlen($value) > 20) {
            throw new InvalidArgumentException("Le numéro de $label ne doit pas dépasser 20 caractères");
        }

        return $value;
    }
}
