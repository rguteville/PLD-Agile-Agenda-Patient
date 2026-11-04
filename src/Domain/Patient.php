<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeInterface;

/**
 * Patient connu du service (ressource HL7 Patient, table Patient). Identité (nom et prénom
 * obligatoires, IPP facultatif à l'admission, RG1) et champs complémentaires facultatifs du
 * catalogue RecordFields::PATIENT, dont le décès (deceased) et le groupe sanguin (bloodType),
 * réservés au médecin. Entité immuable : l'id est null tant que le patient n'est pas enregistré
 * (PatientDao::insert()). removedAt (suppression logique, AURA-12.3) n'est pas porté : PatientDao
 * ne renvoie jamais un patient supprimé.
 *
 * Accesseurs get*() : un par colonne, sans logique ni validation.
 */
class Patient
{
    /**
     * @param ?string $ipp chiffres de l'IPP (colonne INT), null si inconnu
     * @param ?string $phone colonne phoneNumber
     * @param array<string, ?string> $details champs complémentaires, clés de RecordFields::PATIENT ;
     *                                        partiel si le patient vient de PatientDao::findAll() ou search()
     */
    public function __construct(
        private ?int $idPatient,
        private ?string $ipp,
        private string $lastName,
        private string $firstName,
        private ?DateTimeInterface $birthDate,
        private ?string $sex,
        private ?string $phone = null,
        private ?string $email = null,
        private array $details = []
    ) {
    }

    public function getIdPatient(): ?int
    {
        return $this->idPatient;
    }

    public function getIpp(): ?string
    {
        return $this->ipp;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    /** Null pour un patient anonyme (« sous X ») dont la date est inconnue. */
    public function getBirthDate(): ?DateTimeInterface
    {
        return $this->birthDate;
    }

    /** 'M', 'F', ou null si non renseigné à l'admission (RG1 : seuls nom et prénom sont obligatoires). */
    public function getSex(): ?string
    {
        return $this->sex;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Champs complémentaires du dossier (RecordFields::PATIENT) : valeur texte ou null si non
     * renseigné. Les clés absentes sont traitées comme non renseignées.
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
}
