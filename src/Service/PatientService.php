<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PatientDao;
use Aura\Model\Patient;
use DateTimeImmutable;
use InvalidArgumentException;

class PatientService
{
    public function __construct(private PatientDao $patientDao)
    {
    }

    /** @return Patient[] */
    public function getAllPatients(): array
    {
        return $this->patientDao->findAll();
    }

    public function getPatient(int $id): Patient
    {
        $patient = $this->patientDao->findById($id);

        if ($patient === null) {
            throw new \RuntimeException("Patient $id introuvable");
        }

        return $patient;
    }

    /**
     * Inscrit un patient à partir des données brutes du formulaire.
     *
     * @param array<string, mixed> $data
     * @return int identifiant du patient créé
     * @throws InvalidArgumentException si les données sont invalides
     */
    public function inscrirePatient(array $data): int
    {
        $nom = $this->texte($data['nom'] ?? null) ?? '';
        $prenom = $this->texte($data['prenom'] ?? null) ?? '';
        $sexe = strtoupper($this->texte($data['sexe'] ?? null) ?? '');
        $dateSaisie = $this->texte($data['date_naissance'] ?? null) ?? '';
        $ipp = $this->texte($data['ipp'] ?? null);
        $telephone = $this->texte($data['telephone'] ?? null);
        $email = $this->texte($data['email'] ?? null);

        if ($nom === '' || $prenom === '') {
            throw new InvalidArgumentException('Le nom et le prénom du patient sont obligatoires');
        }
        if (!preg_match('/^.{1,50}$/u', $nom) || !preg_match('/^.{1,50}$/u', $prenom)) {
            throw new InvalidArgumentException('Le nom et le prénom ne doivent pas dépasser 50 caractères');
        }
        if (!in_array($sexe, ['M', 'F'], true)) {
            throw new InvalidArgumentException('Le sexe doit être M ou F');
        }

        $dateNaissance = DateTimeImmutable::createFromFormat('!Y-m-d', $dateSaisie);
        if ($dateNaissance === false || $dateNaissance->format('Y-m-d') !== $dateSaisie) {
            throw new InvalidArgumentException('La date de naissance est invalide (format AAAA-MM-JJ)');
        }
        if ($dateNaissance > new DateTimeImmutable()) {
            throw new InvalidArgumentException('La date de naissance ne peut pas être dans le futur');
        }

        if ($ipp !== null) {
            if (!preg_match('/^\d{6,9}$/', $ipp)) {
                throw new InvalidArgumentException("L'IPP doit contenir entre 6 et 9 chiffres");
            }
            if ($this->patientDao->existsByIpp($ipp)) {
                throw new InvalidArgumentException("Un patient avec l'IPP $ipp existe déjà");
            }
        }
        if ($telephone !== null && !preg_match('/^\d{10}$/', $telephone)) {
            throw new InvalidArgumentException('Le téléphone doit contenir 10 chiffres');
        }
        if ($email !== null && (strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException("L'adresse email est invalide");
        }

        $patient = new Patient(
            idPatient: null,
            ipp: $ipp,
            nom: $nom,
            prenom: $prenom,
            dateNaissance: $dateNaissance,
            sexe: $sexe,
            telephone: $telephone,
            email: $email
        );

        return $this->patientDao->insert($patient);
    }

    /** Nettoie une valeur saisie : null si absente ou vide. */
    private function texte(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return $valeur === '' ? null : $valeur;
    }
}
