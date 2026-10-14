<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeInterface;

class Patient
{
    public function __construct(
        private ?int $idPatient,
        private ?string $ipp,
        private string $nom,
        private string $prenom,
        private ?DateTimeInterface $dateNaissance,
        private ?string $sexe,
        private ?string $telephone = null,
        private ?string $email = null
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

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    /** Null pour un patient anonyme (« sous X ») dont la date est inconnue. */
    public function getDateNaissance(): ?DateTimeInterface
    {
        return $this->dateNaissance;
    }

    /** 'M', 'F', ou null si non renseigné à l'admission (RG1 : seuls nom et prénom sont obligatoires). */
    public function getSexe(): ?string
    {
        return $this->sexe;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    /** Patient inscrit « sous X » : identité inconnue. */
    public function estAnonyme(): bool
    {
        return strtoupper($this->nom) === 'X' && strtoupper($this->prenom) === 'X';
    }
}
