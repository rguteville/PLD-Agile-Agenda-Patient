<?php

declare(strict_types=1);

namespace Aura\Model;

use DateTimeInterface;

class Patient
{
    public function __construct(
        private ?int $idPatient,
        private ?string $ipp,
        private string $nom,
        private string $prenom,
        private DateTimeInterface $dateNaissance,
        private string $sexe,
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

    public function getDateNaissance(): DateTimeInterface
    {
        return $this->dateNaissance;
    }

    public function getSexe(): string
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
}
