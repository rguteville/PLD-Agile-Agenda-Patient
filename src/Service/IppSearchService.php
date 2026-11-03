<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\PatientDao;
use Aura\Domain\AllergyIntolerance;
use Aura\Domain\Patient;

/**
 * Recherche d'un patient déjà connu du service par son IPP (AURA-15).
 *
 * L'IPP est unique et conservé toute la vie du patient : à une nouvelle admission, on
 * retrouve son identité et ses allergies connues au lieu de les ressaisir. La recherche
 * se fait dans la base d'AURA uniquement ; l'appel à OpenEMR (AURA-38) est hors périmètre.
 */
class IppSearchService
{
    public function __construct(
        private PatientDao $patientDao,
        private AllergyIntoleranceDao $allergyIntoleranceDao
    ) {
    }

    /**
     * Patient portant cet IPP, avec ses allergies connues ; null si l'IPP est inconnu.
     * L'IPP est supposé déjà contrôlé (AdmissionService::validateIpp()).
     *
     * @return array<string, mixed>|null vue-modèle de present()
     */
    public function search(string $ipp): ?array
    {
        $patient = $this->patientDao->findByIpp($ipp);
        if ($patient === null) {
            return null;
        }

        $allergies = $this->allergyIntoleranceDao->findByPatient((int) $patient->getIdPatient());

        return self::present($patient, $allergies);
    }

    /**
     * Vue-modèle du patient retrouvé : identité dans le format attendu par le formulaire
     * d'admission (date AAAA-MM-JJ, sexe M/F) et allergies par libellé, comme le bandeau (RG2).
     * Méthode pure (pas d'accès aux données), testable sans base.
     *
     * @param Allergie[] $allergies
     * @return array{
     *     idPatient: int,
     *     ipp: ?string,
     *     nom: string,
     *     prenom: string,
     *     dateNaissance: ?string,
     *     sexe: ?string,
     *     allergies: list<array{libelle: string, criticite: ?string}>
     * }
     */
    public static function present(Patient $patient, array $allergies): array
    {
        return [
            'idPatient' => (int) $patient->getIdPatient(),
            'ipp' => $patient->getIpp(),
            'lastName' => trim($patient->getLastName()),
            'firstName' => trim($patient->getFirstName()),
            'birthDate' => $patient->getBirthDate()?->format(AdmissionService::FORMAT_DATE),
            'sex' => self::textOrNull($patient->getSex()),
            'allergies' => array_values(array_map(
                static fn (AllergyIntolerance $a): array => [
                    'label' => trim($a->getSubstance()),
                    'criticality' => self::textOrNull($a->getCriticality()),
                ],
                $allergies
            )),
        ];
    }

    private static function textOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
