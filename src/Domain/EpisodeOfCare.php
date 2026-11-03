<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeInterface;

/**
 * Séjour d'un patient dans un service (ressource HL7 EpisodeOfCare, table EpisodeOfCare). Immuable :
 * toute modification passe par EpisodeOfCareDao, puis on relit le séjour.
 *
 * Le séjour est en cours tant que dischargeDate est null. Il se termine par la fin de séjour
 * (POST /sejour/cloturer) ou par la déclaration du décès du patient (POST /sejour/deces, ou
 * Patient.deceased passé à « Oui » par « Modifier »), qui renseignent toutes deux dischargeDate.
 * Le numéro AAAA-NNNNNN est généré par EpisodeOfCareDao::insert() (RG1) : à la création, l'entité
 * porte un numéro vide, ignoré.
 */
class EpisodeOfCare
{
    /**
     * @param ?int $idEpisodeOfCare null tant que le séjour n'est pas enregistré
     * @param ?string $idService idHealthcareService du service hospitalier (null = non renseigné)
     * @param ?float $weight poids à l'admission, en kg
     * @param ?float $height taille à l'admission, en cm
     * @param array<string, ?string> $details champs complémentaires, clés de RecordFields::EPISODE
     * @param ?string $referringPractitionerName nom affiché du référent, lu par jointure, jamais écrit
     */
    public function __construct(
        private ?int $idEpisodeOfCare,
        private int $idPatient,
        private string $episodeNumber,
        private DateTimeInterface $admissionDate,
        private ?DateTimeInterface $dischargeDate = null,
        private ?string $bedNumber = null,
        private ?string $boxNumber = null,
        private ?string $idService = null,
        private ?float $weight = null,
        private ?float $height = null,
        private ?string $precautions = null,
        private array $details = [],
        private ?string $idReferringPractitioner = null,
        private ?string $referringPractitionerName = null
    ) {
    }

    public function getIdEpisodeOfCare(): ?int
    {
        return $this->idEpisodeOfCare;
    }

    public function getIdPatient(): int
    {
        return $this->idPatient;
    }

    public function getEpisodeNumber(): string
    {
        return $this->episodeNumber;
    }

    public function getAdmissionDate(): DateTimeInterface
    {
        return $this->admissionDate;
    }

    public function getDischargeDate(): ?DateTimeInterface
    {
        return $this->dischargeDate;
    }

    public function getBedNumber(): ?string
    {
        return $this->bedNumber;
    }

    public function getBoxNumber(): ?string
    {
        return $this->boxNumber;
    }

    public function getIdService(): ?string
    {
        return $this->idService;
    }

    /** Poids à l'admission, en kg (null = non renseigné). */
    public function getWeight(): ?float
    {
        return $this->weight;
    }

    /** Taille à l'admission, en cm (null = non renseignée). */
    public function getHeight(): ?float
    {
        return $this->height;
    }

    /** Précautions à prendre (isolement, risque de chute…), texte libre. */
    public function getPrecautions(): ?string
    {
        return $this->precautions;
    }

    /**
     * Champs complémentaires de l'admission (RecordFields::EPISODE) : valeur texte ou null si
     * non renseigné. Les clés absentes sont traitées comme non renseignées.
     *
     * @return array<string, ?string>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** Valeur d'un champ complémentaire, null si non renseigné ou inconnu. */
    public function getDetail(string $field): ?string
    {
        return $this->details[$field] ?? null;
    }

    /**
     * Médecin référent du séjour (responsable de soin) : le médecin de jour qui fait l'admission ou
     * reprend le patient. null = « à désigner » (admission pendant une garde).
     */
    public function getIdReferringPractitioner(): ?string
    {
        return $this->idReferringPractitioner;
    }

    /**
     * « Prénom Nom » du médecin référent, lu par EpisodeOfCareDao (jointure sur Practitioner) pour
     * l'affichage ; son identifiant s'il n'a pas de nom, null s'il n'y a pas de référent. Jamais écrit en base.
     */
    public function getReferringPractitionerName(): ?string
    {
        return $this->referringPractitionerName;
    }

    /** En cours : pas encore de date de sortie (ni fin de séjour ni décès déclaré). */
    public function isOngoing(): bool
    {
        return $this->dischargeDate === null;
    }
}
