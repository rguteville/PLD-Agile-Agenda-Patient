<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PatientDao;
use Aura\Domain\Patient;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Modification de l'identité d'un patient (AURA-12.2) : mêmes règles que l'admission
 * (AdmissionService::validerPatientIdentite()), plus l'unicité de l'IPP entre patients.
 * Les mesures (taille, poids, précautions) appartiennent au séjour et ne sont pas touchées.
 */
final class ModificationPatientService
{
    public function __construct(
        private PatientDao $patientDao,
        private AdmissionService $admissionService
    ) {
    }

    /** Patient à pré-remplir dans le formulaire, null s'il n'existe pas (404). */
    public function trouver(int $idPatient): ?Patient
    {
        return $this->patientDao->findById($idPatient);
    }

    /**
     * Valide puis enregistre. Renvoie les erreurs par champ ([] = modification faite).
     * Rien n'est écrit tant qu'il reste une erreur.
     *
     * @param array<string, mixed> $donnees clés du domaine : nom, prenom, ipp, dateNaissance, sexe
     * @return array<string, string>
     * @throws InvalidArgumentException si le patient n'existe pas
     */
    public function modifier(int $idPatient, array $donnees): array
    {
        $existant = $this->patientDao->findById($idPatient);
        if ($existant === null) {
            throw new InvalidArgumentException(sprintf('Aucun patient ne porte l\'identifiant %d.', $idPatient));
        }

        $erreurs = $this->admissionService->validerPatientIdentite($donnees);
        if ($erreurs !== []) {
            return $erreurs;
        }

        // L'IPP doit être unique : refusé seulement s'il est porté par un AUTRE patient.
        $ipp = $this->texte($donnees['ipp'] ?? null);
        if ($ipp !== null) {
            $porteur = $this->patientDao->findByIpp($ipp);
            if ($porteur !== null && $porteur->getIdPatient() !== $idPatient) {
                return ['ipp' => 'Cet IPP est déjà utilisé par un autre patient.'];
            }
        }

        $dateNaissance = $this->texte($donnees['dateNaissance'] ?? null);
        $this->patientDao->update(new Patient(
            idPatient: $idPatient,
            ipp: $ipp,
            nom: (string) $this->texte($donnees['nom'] ?? null),
            prenom: (string) $this->texte($donnees['prenom'] ?? null),
            dateNaissance: $dateNaissance !== null
                ? DateTimeImmutable::createFromFormat('!' . AdmissionService::FORMAT_DATE, $dateNaissance) ?: null
                : null,
            sexe: $this->texte($donnees['sexe'] ?? null),
            // Hors formulaire : conservés tels quels, sinon update() les effacerait.
            telephone: $existant->getTelephone(),
            email: $existant->getEmail()
        ));

        return [];
    }

    /** Valeur saisie nettoyée : null si absente, vide ou non textuelle. */
    private function texte(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return $valeur === '' ? null : $valeur;
    }
}
