<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\SejourDao;
use Aura\Domain\Patient;
use Aura\Domain\Sejour;

/**
 * Liste des patients du service avec leur séjour en cours (AURA-12.1).
 *
 * Tous les patients de la base apparaissent, avec ou sans séjour en cours ; la
 * recherche (nom, prénom ou IPP) est faite en base par PatientDao::search().
 */
final class ListePatientsService
{
    /** Longueur maximale du terme de recherche (au-delà, la saisie est tronquée). */
    public const LONGUEUR_MAX_RECHERCHE = 50;

    public function __construct(
        private PatientDao $patientDao,
        private SejourDao $sejourDao
    ) {
    }

    /**
     * Lignes prêtes pour la vue, triées par nom puis prénom.
     *
     * @return list<array{
     *     idPatient: int,
     *     nom: string,
     *     prenom: string,
     *     ipp: ?string,
     *     dateNaissance: ?string,
     *     idSejour: ?int,
     *     numeroSejour: ?string
     * }>
     */
    public function lister(?string $recherche = null): array
    {
        $recherche = self::nettoyerRecherche($recherche);
        $patients = $recherche === null ? $this->patientDao->findAll() : $this->patientDao->search($recherche);

        $enCours = [];
        foreach ($this->sejourDao->findEnCours() as $sejour) {
            // Un patient ne devrait avoir qu'un séjour en cours ; on garde le premier.
            $enCours[$sejour->getIdPatient()] ??= $sejour;
        }

        return array_map(
            fn(Patient $p) => self::presenterLigne($p, $enCours[$p->getIdPatient()] ?? null),
            $patients
        );
    }

    /** Terme de recherche nettoyé : null si vide, tronqué à LONGUEUR_MAX_RECHERCHE. */
    public static function nettoyerRecherche(?string $recherche): ?string
    {
        $recherche = trim((string) $recherche);
        if ($recherche === '') {
            return null;
        }

        return mb_substr($recherche, 0, self::LONGUEUR_MAX_RECHERCHE);
    }

    /**
     * @return array{
     *     idPatient: int,
     *     nom: string,
     *     prenom: string,
     *     ipp: ?string,
     *     dateNaissance: ?string,
     *     idSejour: ?int,
     *     numeroSejour: ?string
     * }
     */
    private static function presenterLigne(Patient $patient, ?Sejour $sejourEnCours): array
    {
        return [
            'idPatient' => (int) $patient->getIdPatient(),
            'nom' => $patient->getNom(),
            'prenom' => $patient->getPrenom(),
            'ipp' => $patient->getIpp(),
            'dateNaissance' => $patient->getDateNaissance()?->format('d/m/Y'),
            'idSejour' => $sejourEnCours?->getIdSejour(),
            'numeroSejour' => $sejourEnCours?->getNumSejour(),
        ];
    }
}
