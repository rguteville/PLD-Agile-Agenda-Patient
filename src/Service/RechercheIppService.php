<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Domain\Allergie;
use Aura\Domain\Patient;

/**
 * Recherche d'un patient déjà connu du service par son IPP (AURA-15).
 *
 * L'IPP est unique et conservé toute la vie du patient : à une nouvelle admission, on
 * retrouve son identité et ses allergies connues au lieu de les ressaisir. La recherche
 * se fait dans la base d'AURA uniquement ; l'appel à OpenEMR (AURA-38) est hors périmètre.
 */
class RechercheIppService
{
    public function __construct(
        private PatientDao $patientDao,
        private AllergieDao $allergieDao
    ) {
    }

    /**
     * Patient portant cet IPP, avec ses allergies connues ; null si l'IPP est inconnu.
     * L'IPP est supposé déjà contrôlé (AdmissionService::validerIpp()).
     *
     * @return array<string, mixed>|null vue-modèle de presenter()
     */
    public function rechercher(string $ipp): ?array
    {
        $patient = $this->patientDao->findByIpp($ipp);
        if ($patient === null) {
            return null;
        }

        $allergies = $this->allergieDao->findByPatient((int) $patient->getIdPatient());

        return self::presenter($patient, $allergies);
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
    public static function presenter(Patient $patient, array $allergies): array
    {
        return [
            'idPatient' => (int) $patient->getIdPatient(),
            'ipp' => $patient->getIpp(),
            'nom' => trim($patient->getNom()),
            'prenom' => trim($patient->getPrenom()),
            'dateNaissance' => $patient->getDateNaissance()?->format(AdmissionService::FORMAT_DATE),
            'sexe' => self::texteOuNull($patient->getSexe()),
            'allergies' => array_values(array_map(
                static fn (Allergie $a): array => [
                    'libelle' => trim($a->getSubstance()),
                    'criticite' => self::texteOuNull($a->getCriticite()),
                ],
                $allergies
            )),
        ];
    }

    private static function texteOuNull(?string $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }
        $texte = trim($valeur);

        return $texte === '' ? null : $texte;
    }
}
