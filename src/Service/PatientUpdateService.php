<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PatientDao;
use Aura\Domain\Patient;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Modification de l'identité d'un patient (AURA-12.2) : mêmes règles que l'admission
 * (AdmissionService::validatePatientIdentity()), plus l'unicité de l'IPP entre patients.
 * Les mesures (taille, poids, précautions) appartiennent au séjour et ne sont pas touchées.
 */
final class PatientUpdateService
{
    public function __construct(
        private PatientDao $patientDao,
        private AdmissionService $admissionService
    ) {
    }

    /** Patient à pré-remplir dans le formulaire, null s'il n'existe pas (404). */
    public function find(int $idPatient): ?Patient
    {
        return $this->patientDao->findById($idPatient);
    }

    /**
     * Valide puis enregistre. Renvoie les erreurs par champ ([] = modification faite).
     * Rien n'est écrit tant qu'il reste une erreur.
     *
     * @param array<string, mixed> $data clés du domaine : lastName, firstName, ipp, birthDate, sex
     * @return array<string, string>
     * @throws InvalidArgumentException si le patient n'existe pas
     */
    public function edit(int $idPatient, array $data): array
    {
        $existing = $this->patientDao->findById($idPatient);
        if ($existing === null) {
            throw new InvalidArgumentException(sprintf('Aucun patient ne porte l\'identifiant %d.', $idPatient));
        }

        $errors = $this->admissionService->validatePatientIdentity($data);
        if ($errors !== []) {
            return $errors;
        }

        // L'IPP doit être unique : refusé seulement s'il est porté par un AUTRE patient, même supprimé
        // (AURA-12.3 : l'IPP d'un patient supprimé reste réservé).
        $ipp = $this->text($data['ipp'] ?? null);
        if ($ipp !== null) {
            $carrier = $this->patientDao->ippOwnerId($ipp);
            if ($carrier !== null && $carrier !== $idPatient) {
                return ['ipp' => 'Cet IPP est déjà utilisé par un autre patient.'];
            }
        }

        $birthDate = $this->text($data['birthDate'] ?? null);
        $this->patientDao->update(new Patient(
            idPatient: $idPatient,
            ipp: $ipp,
            lastName: (string) $this->text($data['lastName'] ?? null),
            firstName: (string) $this->text($data['firstName'] ?? null),
            birthDate: $birthDate !== null
                ? DateTimeImmutable::createFromFormat('!' . AdmissionService::FORMAT_DATE, $birthDate) ?: null
                : null,
            sex: $this->text($data['sex'] ?? null),
            // Hors formulaire : conservés tels quels, sinon update() les effacerait.
            phone: $existing->getPhone(),
            email: $existing->getEmail(),
            details: $existing->getDetails()
        ));

        return [];
    }

    /** Valeur saisie nettoyée : null si absente, vide ou non textuelle. */
    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
