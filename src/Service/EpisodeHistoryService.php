<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Domain\EpisodeOfCare;

/**
 * Séjours d'un patient : dossier toujours accessible depuis la
 * liste, historique des séjours dans la liste et dans le dossier, séjour en cours mis en avant.
 *
 * Service PUR (pas de PDO, pas de HTTP) : il reçoit les séjours déjà lus par EpisodeOfCareDao et le
 * référentiel des services, et prépare les vue-modèles partagés par PatientListService et
 * PatientRecordService (composant components/episode_history).
 */
final class EpisodeHistoryService
{
    /**
     * Séjours du plus récent au plus ancien (date d'admission, puis identifiant). Le DAO trie déjà ;
     * on retrie ici pour que la règle ne dépende pas de l'ordre reçu.
     *
     * @param EpisodeOfCare[] $episodes
     * @return list<EpisodeOfCare>
     */
    public static function sortRecentFirst(array $episodes): array
    {
        $episodes = array_values($episodes);
        usort($episodes, static function (EpisodeOfCare $a, EpisodeOfCare $b): int {
            return [$b->getAdmissionDate()->format('Y-m-d H:i:s'), (int) $b->getIdEpisodeOfCare()]
                <=> [$a->getAdmissionDate()->format('Y-m-d H:i:s'), (int) $a->getIdEpisodeOfCare()];
        });

        return $episodes;
    }

    /**
     * Séjour en cours du patient (sans date de sortie), le plus récent s'il y en a plusieurs (données
     * incohérentes : un patient ne devrait avoir qu'un séjour en cours) ; null s'il n'en a pas.
     *
     * @param EpisodeOfCare[] $episodes
     */
    public static function ongoing(array $episodes): ?EpisodeOfCare
    {
        foreach (self::sortRecentFirst($episodes) as $episode) {
            if ($episode->isOngoing()) {
                return $episode;
            }
        }

        return null;
    }

    /**
     * Séjour dont le dossier s'ouvre depuis la liste : le séjour en cours s'il existe, sinon le dernier
     * séjour (clos, consulté comme tout séjour clos) ; null si le patient n'a aucun séjour.
     *
     * @param EpisodeOfCare[] $episodes
     */
    public static function recordEpisode(array $episodes): ?EpisodeOfCare
    {
        return self::ongoing($episodes) ?? (self::sortRecentFirst($episodes)[0] ?? null);
    }

    /**
     * Historique prêt à afficher, du plus récent au plus ancien. `displayed` signale le séjour dont le
     * dossier est affiché (null depuis la liste des patients).
     *
     * @param EpisodeOfCare[] $episodes
     * @param array<string, string> $services référentiel des services (id => nom)
     * @return list<array{idEpisodeOfCare: int, episodeNumber: string, admissionDate: string,
     *               dischargeDate: ?string, ongoing: bool, service: ?string, admissionReason: ?string,
     *               displayed: bool}>
     */
    public static function present(array $episodes, array $services = [], ?int $displayedId = null): array
    {
        return array_map(
            static fn (EpisodeOfCare $e): array => [
                'idEpisodeOfCare' => (int) $e->getIdEpisodeOfCare(),
                'episodeNumber' => self::number($e),
                'admissionDate' => $e->getAdmissionDate()->format('d/m/Y'),
                'dischargeDate' => $e->getDischargeDate()?->format('d/m/Y'),
                'ongoing' => $e->isOngoing(),
                'service' => self::service($e, $services),
                'admissionReason' => self::textOrNull($e->getDetail('admissionReason')),
                'displayed' => $displayedId !== null && $e->getIdEpisodeOfCare() === $displayedId,
            ],
            self::sortRecentFirst($episodes)
        );
    }

    /**
     * Séjour en cours mis en avant (liste des patients, encart du dossier d'un séjour clos) : numéro,
     * lit, box, service et date d'admission.
     *
     * @param array<string, string> $services référentiel des services (id => nom)
     * @return array{idEpisodeOfCare: int, episodeNumber: string, bed: ?string, box: ?string,
     *               service: ?string, since: string}
     */
    public static function presentCurrent(EpisodeOfCare $episode, array $services = []): array
    {
        return [
            'idEpisodeOfCare' => (int) $episode->getIdEpisodeOfCare(),
            'episodeNumber' => self::number($episode),
            'bed' => self::textOrNull($episode->getBedNumber()),
            'box' => self::textOrNull($episode->getBoxNumber()),
            'service' => self::service($episode, $services),
            'since' => $episode->getAdmissionDate()->format('d/m/Y'),
        ];
    }

    /** Numéro du séjour, ou son identifiant si le numéro est vide (comme le bandeau). */
    private static function number(EpisodeOfCare $episode): string
    {
        $number = trim($episode->getEpisodeNumber());

        return $number !== '' ? $number : (string) $episode->getIdEpisodeOfCare();
    }

    /**
     * Nom du service si le référentiel le connaît, sinon l'identifiant brut (on ne cache rien).
     *
     * @param array<string, string> $services
     */
    private static function service(EpisodeOfCare $episode, array $services): ?string
    {
        $id = self::textOrNull($episode->getIdService());

        return $id === null ? null : ($services[$id] ?? $id);
    }

    /** Texte sans espaces de bord ; vide ou null = null. */
    private static function textOrNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
