<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Dao\SejourDao;
use Aura\Domain\Allergie;
use Aura\Domain\Patient;
use Aura\Domain\Sejour;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Prépare l'affichage du dossier d'un séjour (AURA-14) : le bandeau identité + allergies
 * imposé par RG2 en tête de tout écran lié au séjour, et les informations du séjour.
 *
 * Les données viennent de la base via les DAO (AURA-11) ; les méthodes presenter*()
 * sont pures (pas d'accès aux données) pour être testables sans base.
 */
final class DossierPatientService
{
    public function __construct(
        private SejourDao $sejourDao,
        private PatientDao $patientDao,
        private AllergieDao $allergieDao,
        private DateTimeImmutable $aujourdhui,
    ) {
    }

    /**
     * @return array{bandeau: array<string, mixed>, sejour: array<string, mixed>}|null
     *         null si le séjour n'existe pas (ou si son patient a disparu : données incohérentes)
     */
    public function dossier(int $idSejour): ?array
    {
        $sejour = $this->sejourDao->findById($idSejour);
        if ($sejour === null) {
            return null;
        }

        $patient = $this->patientDao->findById($sejour->getIdPatient());
        if ($patient === null) {
            return null;
        }

        $allergies = $this->allergieDao->findByPatient($patient->getIdPatient() ?? $sejour->getIdPatient());

        return [
            'bandeau' => self::presenterBandeau($patient, $sejour, $allergies, $this->aujourdhui),
            'sejour' => self::presenterSejour($sejour, $patient),
        ];
    }

    /**
     * Vue-modèle du bandeau : identité (nom, prénom, date de naissance, IPP) et allergies
     * connues (RG2). Composant src/View/components/bandeau_patient.php.
     *
     * @param Allergie[] $allergies
     * @return array<string, mixed>
     */
    public static function presenterBandeau(
        Patient $patient,
        Sejour $sejour,
        array $allergies,
        DateTimeImmutable $aujourdhui
    ): array {
        $naissance = $patient->getDateNaissance();

        return [
            'nom' => trim($patient->getNom()),
            'prenom' => trim($patient->getPrenom()),
            'sexe' => self::texteOuNull($patient->getSexe()),
            'dateNaissance' => $naissance?->format('d/m/Y'),
            'age' => $naissance === null ? null : self::age($naissance, $aujourdhui),
            'ipp' => self::texteOuNull($patient->getIpp()),
            'numeroSejour' => trim($sejour->getNumSejour()) !== ''
                ? trim($sejour->getNumSejour())
                : (string) $sejour->getIdSejour(),
            'dateAdmission' => $sejour->getDateAdmission()->format('d/m/Y H:i'),
            'allergies' => array_values(array_map(
                static fn (Allergie $a): array => [
                    'libelle' => trim($a->getSubstance()),
                    'criticite' => self::texteOuNull($a->getCriticite()),
                ],
                $allergies
            )),
        ];
    }

    /** @return array<string, mixed> */
    public static function presenterSejour(Sejour $sejour, Patient $patient): array
    {
        $sortie = $sejour->getDateSortie();

        return [
            'enCours' => $sortie === null,
            'dateSortie' => $sortie?->format('d/m/Y H:i'),
            'service' => self::texteOuNull($sejour->getIdService()),
            'lit' => self::texteOuNull($sejour->getNumLit()),
            'box' => self::texteOuNull($sejour->getNumBox()),
            // Mesures relevées à l'admission (AURA-10.1) ; null = non renseigné.
            'poids' => self::mesure($sejour->getPoids(), 'kg'),
            'taille' => self::mesure($sejour->getTaille(), 'cm'),
            'precautions' => self::texteOuNull($sejour->getPrecautions()),
            'telephone' => self::texteOuNull($patient->getTelephone()),
            'email' => self::texteOuNull($patient->getEmail()),
        ];
    }

    /** Âge révolu à la date donnée ; null si la date de naissance est dans le futur. */
    public static function age(DateTimeInterface $naissance, DateTimeInterface $aujourdhui): ?int
    {
        if ($naissance > $aujourdhui) {
            return null;
        }

        return $naissance->diff($aujourdhui)->y;
    }

    /** Mesure à la française, sans zéros inutiles : 62.5 → « 62,5 kg », 164.0 → « 164 cm ». */
    public static function mesure(?float $valeur, string $unite): ?string
    {
        if ($valeur === null) {
            return null;
        }
        $nombre = rtrim(rtrim(number_format($valeur, 2, ',', ''), '0'), ',');

        return $nombre . ' ' . $unite;
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
