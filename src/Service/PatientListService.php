<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;

/**
 * Liste des patients du service avec leur séjour en cours (AURA-12.1).
 *
 * Tous les patients de la base apparaissent, avec ou sans séjour en cours ; la
 * recherche (nom, prénom ou IPP) est faite en base par PatientDao::search().
 */
final class PatientListService
{
    /** Longueur maximale du terme de recherche (au-delà, la saisie est tronquée). */
    public const SEARCH_MAX_LENGTH = 50;

    public function __construct(
        private PatientDao $patientDao,
        private EpisodeOfCareDao $episodeOfCareDao
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
    public function listAll(?string $search = null): array
    {
        $search = self::cleanSearch($search);
        $patients = $search === null ? $this->patientDao->findAll() : $this->patientDao->search($search);

        $ongoing = [];
        foreach ($this->episodeOfCareDao->findOngoing() as $episodeOfCare) {
            // Un patient ne devrait avoir qu'un séjour en cours ; on garde le premier.
            $ongoing[$episodeOfCare->getIdPatient()] ??= $episodeOfCare;
        }

        return array_map(
            fn(Patient $p) => self::presentLine($p, $ongoing[$p->getIdPatient()] ?? null),
            $patients
        );
    }

    /** Terme de recherche nettoyé : null si vide, tronqué à LONGUEUR_MAX_RECHERCHE. */
    public static function cleanSearch(?string $search): ?string
    {
        $search = trim((string) $search);
        if ($search === '') {
            return null;
        }

        return mb_substr($search, 0, self::SEARCH_MAX_LENGTH);
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
    private static function presentLine(Patient $patient, ?EpisodeOfCare $ongoingEpisodeOfCare): array
    {
        return [
            'idPatient' => (int) $patient->getIdPatient(),
            'lastName' => $patient->getLastName(),
            'firstName' => $patient->getFirstName(),
            'ipp' => $patient->getIpp(),
            'birthDate' => $patient->getBirthDate()?->format('d/m/Y'),
            'idEpisodeOfCare' => $ongoingEpisodeOfCare?->getIdEpisodeOfCare(),
            'episodeNumber' => $ongoingEpisodeOfCare?->getEpisodeNumber(),
        ];
    }
}
