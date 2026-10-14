<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\SejourDaoInterface;
use DateTimeImmutable;
use Exception;

/**
 * Prépare l'affichage du dossier d'un séjour (AURA-14) : le bandeau identité + allergies
 * imposé par RG2 en tête de tout écran lié au séjour, et les informations du séjour.
 *
 * Les méthodes presenter*() sont pures (pas d'accès aux données) pour être testables.
 */
final class DossierPatientService
{
    public function __construct(
        private SejourDaoInterface $sejourDao,
        private DateTimeImmutable $aujourdhui,
    ) {
    }

    /**
     * @return array{bandeau: array<string, mixed>, sejour: array<string, mixed>}|null
     *         null si le séjour n'existe pas
     */
    public function dossier(string $idSejour): ?array
    {
        $resultat = $this->sejourDao->findAvecPatient($idSejour);
        if ($resultat === null) {
            return null;
        }

        $allergies = $this->sejourDao->findAllergies((string) $resultat['patient']['id_patient']);

        return [
            'bandeau' => self::presenterBandeau(
                $resultat['patient'],
                $resultat['sejour'],
                $allergies,
                $this->aujourdhui
            ),
            'sejour' => self::presenterSejour($resultat['sejour'], $resultat['patient']),
        ];
    }

    /**
     * Vue-modèle du bandeau : identité (nom, prénom, date de naissance, IPP) et allergies
     * connues (RG2). Composant src/View/components/bandeau_patient.php.
     *
     * @param array<string, mixed> $patient ligne Patient
     * @param array<string, mixed> $sejour ligne Sejour
     * @param list<array{libelle: string, criticite: string|null}> $allergies
     * @return array<string, mixed>
     */
    public static function presenterBandeau(
        array $patient,
        array $sejour,
        array $allergies,
        DateTimeImmutable $aujourdhui
    ): array {
        $naissance = self::date($patient['date_de_naissance'] ?? null);

        return [
            'nom' => trim((string) ($patient['nom'] ?? '')),
            'prenom' => trim((string) ($patient['prenom'] ?? '')),
            'sexe' => self::texteOuNull($patient['sexe'] ?? null),
            'dateNaissance' => $naissance?->format('d/m/Y'),
            'age' => $naissance === null ? null : self::age($naissance, $aujourdhui),
            'ipp' => self::texteOuNull($patient['ipp'] ?? null),
            'numeroSejour' => trim((string) ($sejour['num_sejour'] ?? $sejour['id_sejour'] ?? '')),
            'dateAdmission' => self::date($sejour['date_admission'] ?? null)?->format('d/m/Y H:i'),
            'allergies' => array_values(array_map(
                static fn (array $a): array => [
                    'libelle' => trim((string) $a['libelle']),
                    'criticite' => self::texteOuNull($a['criticite'] ?? null),
                ],
                $allergies
            )),
        ];
    }

    /**
     * @param array<string, mixed> $sejour ligne Sejour
     * @param array<string, mixed> $patient ligne Patient
     * @return array<string, mixed>
     */
    public static function presenterSejour(array $sejour, array $patient): array
    {
        $sortie = self::date($sejour['date_sortie'] ?? null);

        return [
            'enCours' => $sortie === null,
            'dateSortie' => $sortie?->format('d/m/Y H:i'),
            'service' => self::texteOuNull($sejour['id_service'] ?? null),
            'lit' => self::texteOuNull($sejour['num_lit'] ?? null),
            'box' => self::texteOuNull($sejour['num_box'] ?? null),
            'telephone' => self::texteOuNull($patient['telephone'] ?? null),
            'email' => self::texteOuNull($patient['email'] ?? null),
        ];
    }

    /** Âge révolu à la date donnée ; null si la date de naissance est dans le futur. */
    public static function age(DateTimeImmutable $naissance, DateTimeImmutable $aujourdhui): ?int
    {
        if ($naissance > $aujourdhui) {
            return null;
        }

        return $naissance->diff($aujourdhui)->y;
    }

    private static function date(mixed $valeur): ?DateTimeImmutable
    {
        if (!is_string($valeur) || trim($valeur) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($valeur);
        } catch (Exception) {
            return null;
        }
    }

    private static function texteOuNull(mixed $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }
        $texte = trim((string) $valeur);

        return $texte === '' ? null : $texte;
    }
}
