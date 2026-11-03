<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Professionnel de santé qui peut prescrire (Epic 4). Tables Practitioner / PractitionerRole.
 */
class Practitioner
{
    public function __construct(
        private string $idPractitioner,
        private string $lastName,
        private string $firstName,
        private ?string $role = null,
        private bool $canValidate = false
    ) {
    }

    public function getIdPractitioner(): string
    {
        return $this->idPractitioner;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    /** Libellé du rôle (PractitionerRole.title) : « Médecin réanimateur », « Interne »… */
    public function getRole(): ?string
    {
        return $this->role;
    }

    /** « Claire Morel » */
    public function getFullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /** « Dr Claire Morel » : nom affiché d'un médecin (référent du séjour). */
    public function getDoctorName(): string
    {
        return self::doctorTitle($this->getFullName());
    }

    /** Préfixe « Dr » devant un nom complet déjà composé (« Claire Morel » → « Dr Claire Morel »). */
    public static function doctorTitle(string $fullName): string
    {
        return 'Dr ' . trim($fullName);
    }

    /**
     * RG5 : médecin habilité (PractitionerRole.canValidate). Ses prescriptions sont validées
     * d'office et il peut valider celles des autres.
     */
    public function canValidate(): bool
    {
        return $this->canValidate;
    }
}
