<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;

/**
 * Liste des patients du service (AURA-12.1), avec le séjour en cours mis en avant (numéro, lit, service,
 * depuis le …), un lien vers le dossier pour chaque patient (séjour en cours, sinon dernier séjour),
 * l'historique de ses séjours et le décès.
 *
 * Tous les patients actifs apparaissent, avec ou sans séjour ; la recherche (nom, prénom ou IPP) est
 * faite en base par PatientDao::search(). Les séjours de tous les patients affichés sont lus en une
 * requête (EpisodeOfCareDao::findByPatients()).
 */
final class PatientListService
{
    /** Longueur maximale du terme de recherche (au-delà, la saisie est tronquée). */
    public const SEARCH_MAX_LENGTH = 50;

    public function __construct(
        private PatientDao $patientDao,
        private EpisodeOfCareDao $episodeOfCareDao,
        private ?ReferenceDataDao $referenceDataDao = null
    ) {
    }

    /**
     * Lignes prêtes pour la vue, triées par nom puis prénom (ordre du DAO).
     *
     * @return list<array<string, mixed>> voir presentLine()
     */
    public function listAll(?string $search = null): array
    {
        $search = self::cleanSearch($search);
        $patients = $search === null ? $this->patientDao->findAll() : $this->patientDao->search($search);
        if ($patients === []) {
            return [];
        }

        $episodesByPatient = [];
        $ids = array_map(static fn (Patient $p): int => (int) $p->getIdPatient(), $patients);
        foreach ($this->episodeOfCareDao->findByPatients($ids) as $episodeOfCare) {
            $episodesByPatient[$episodeOfCare->getIdPatient()][] = $episodeOfCare;
        }
        $services = $this->referenceDataDao?->healthcareServices() ?? [];

        return array_values(array_map(
            static fn (Patient $p): array => self::presentLine(
                $p,
                $episodesByPatient[(int) $p->getIdPatient()] ?? [],
                $services
            ),
            $patients
        ));
    }

    /** Terme de recherche nettoyé : null si vide, tronqué à SEARCH_MAX_LENGTH. */
    public static function cleanSearch(?string $search): ?string
    {
        $search = trim((string) $search);
        if ($search === '') {
            return null;
        }

        return mb_substr($search, 0, self::SEARCH_MAX_LENGTH);
    }

    /**
     * Ligne d'un patient. Pure : testable avec des entités construites à la main.
     *  - `current` : séjour en cours mis en avant, null s'il n'y en a pas ;
     *  - `record` : séjour dont le dossier s'ouvre (en cours, sinon le dernier), null sans aucun séjour ;
     *  - `history` : tous les séjours, du plus récent au plus ancien ;
     *  - `deceased` / `deceasedLabel` : décès (Patient.deceased), libellé accordé au sexe.
     *
     * @param EpisodeOfCare[] $episodes séjours du patient
     * @param array<string, string> $services référentiel des services (id => nom)
     * @return array{idPatient: int, lastName: string, firstName: string, ipp: ?string, birthDate: ?string,
     *               deceased: bool, deceasedLabel: ?string, current: ?array<string, mixed>,
     *               record: ?array{idEpisodeOfCare: int, episodeNumber: string, ongoing: bool,
     *                              dischargeDate: ?string},
     *               history: list<array<string, mixed>>}
     */
    public static function presentLine(Patient $patient, array $episodes, array $services = []): array
    {
        $ongoing = EpisodeHistoryService::ongoing($episodes);
        $recordEpisode = EpisodeHistoryService::recordEpisode($episodes);
        $deceased = PatientRecordService::isDeceased($patient);

        return [
            'idPatient' => (int) $patient->getIdPatient(),
            'lastName' => $patient->getLastName(),
            'firstName' => $patient->getFirstName(),
            'ipp' => $patient->getIpp(),
            'birthDate' => $patient->getBirthDate()?->format('d/m/Y'),
            'deceased' => $deceased,
            'deceasedLabel' => $deceased ? PatientRecordService::deceasedLabel($patient->getSex()) : null,
            'current' => $ongoing === null ? null : EpisodeHistoryService::presentCurrent($ongoing, $services),
            'record' => $recordEpisode === null ? null : [
                'idEpisodeOfCare' => (int) $recordEpisode->getIdEpisodeOfCare(),
                'episodeNumber' => EpisodeHistoryService::present([$recordEpisode])[0]['episodeNumber'],
                'ongoing' => $recordEpisode->isOngoing(),
                'dischargeDate' => $recordEpisode->getDischargeDate()?->format('d/m/Y'),
            ],
            'history' => EpisodeHistoryService::present($episodes, $services),
        ];
    }
}
