<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AppointmentDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\Transaction;
use Aura\Domain\Appointment;
use Aura\Domain\MedicationRequest;
use Aura\Domain\RequestLine;
use DateTimeImmutable;
use LogicException;

/**
 * AURA-24.2 et 24.3 : génération quotidienne de l'agenda (bin/generate-agenda.php, lancé par cron).
 *
 * Pour chaque ligne active (soin ou médicament non arrêté) d'un séjour en cours, crée les soins
 * planifiés (Appointment « PLANIFIE ») manquants entre maintenant et l'horizon (fin du jour
 * J + HORIZON_DAYS). Idempotente : une heure déjà planifiée pour la ligne n'est jamais recréée
 * (aucun doublon ligne + plannedTime), un soin existant n'est jamais modifié, et rien n'est créé
 * avant maintenant (RG9 : pas de recalcul rétroactif). Les heures viennent du seul algorithme
 * d'occurrences d'AURA, RequestLine::plannedTimesBetween().
 *
 * Les règles (expectedTimes(), missingTimes(), horizonEnd()) sont pures et testées sans base ;
 * generate() les applique en base, dans une seule transaction.
 */
final class AgendaGenerationService
{
    /**
     * Horizon de planification, en jours après aujourd'hui : le même que celui de l'enregistrement
     * d'une prescription (hypothèse de travail AURA-24.4, à confirmer avec le service).
     */
    public const HORIZON_DAYS = RequestLine::PLANNING_HORIZON_DAYS;

    /** Format de comparaison des heures (celui des colonnes DATETIME). */
    private const TIME_KEY = 'Y-m-d H:i:s';

    public function __construct(
        private CarePlanDao $carePlanDao,
        private AppointmentDao $appointmentDao,
        private Transaction $transaction
    ) {
    }

    /** Fin de l'horizon : le jour J + $days à 23:59:59 (J = jour de $now). */
    public static function horizonEnd(DateTimeImmutable $now, int $days = self::HORIZON_DAYS): DateTimeImmutable
    {
        return $now->setTime(23, 59, 59)->modify(sprintf('+%d days', max(0, $days)));
    }

    /**
     * Heures attendues d'une ligne entre $from et $until (bornes incluses). Une ligne arrêtée n'en a
     * plus aucune (AURA-22) ; sinon ce sont les occurrences de la ligne (RG3) dans la plage, bornées
     * par sa date de fin.
     *
     * @return DateTimeImmutable[]
     */
    public static function expectedTimes(RequestLine $line, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        if ($line->isStopped() || $until < $from) {
            return [];
        }

        return $line->plannedTimesBetween($from, $until);
    }

    /**
     * Heures attendues qui ne sont pas déjà planifiées, dans l'ordre d'origine. La comparaison se
     * fait à la seconde, comme en base (DATETIME) ; une heure attendue deux fois n'est gardée qu'une fois.
     *
     * @param DateTimeImmutable[] $expected
     * @param DateTimeImmutable[] $existing heures des soins déjà planifiés pour la ligne
     * @return DateTimeImmutable[]
     */
    public static function missingTimes(array $expected, array $existing): array
    {
        $known = [];
        foreach ($existing as $time) {
            $known[$time->format(self::TIME_KEY)] = true;
        }

        $missing = [];
        foreach ($expected as $time) {
            $key = $time->format(self::TIME_KEY);
            if (!isset($known[$key])) {
                $known[$key] = true;
                $missing[] = $time;
            }
        }

        return $missing;
    }

    /**
     * Crée les soins planifiés manquants de tous les séjours en cours, de $now à l'horizon.
     * Tout ou rien : en cas d'erreur, aucun soin n'est créé et l'exception remonte.
     *
     * @return array{created: int, episodes: int, examinedEpisodes: int, lines: int}
     *         created = soins créés ; episodes = séjours ayant reçu au moins un soin ;
     *         examinedEpisodes = séjours en cours ayant au moins une ligne active ; lines = lignes examinées
     */
    public function generate(DateTimeImmutable $now, int $days = self::HORIZON_DAYS): array
    {
        $until = self::horizonEnd($now, $days);

        return $this->transaction->run(function () use ($now, $until): array {
            // Verrou d'abord, lecture ensuite : une génération concurrente attend la fin de celle-ci.
            $this->carePlanDao->lockAppointments();

            $created = 0;
            $examined = [];
            $served = [];
            $lines = $this->carePlanDao->findActiveLinesOfOngoingEpisodes($now);
            foreach ($lines as ['idEpisodeOfCare' => $idEpisodeOfCare, 'line' => $line]) {
                $examined[$idEpisodeOfCare] = true;
                $missing = self::missingTimes(
                    self::expectedTimes($line, $now, $until),
                    $this->existingTimes($line)
                );
                if ($missing === []) {
                    continue;
                }
                $created += $this->carePlanDao->addAppointments($line, $missing);
                $served[$idEpisodeOfCare] = true;
            }

            return [
                'created' => $created,
                'episodes' => count($served),
                'examinedEpisodes' => count($examined),
                'lines' => count($lines),
            ];
        });
    }

    /**
     * Heures des soins déjà planifiés pour la ligne, quel que soit leur statut (réalisé, annulé…) :
     * un soin annulé à une heure donnée ne doit pas réapparaître à la génération suivante.
     *
     * @return DateTimeImmutable[]
     */
    private function existingTimes(RequestLine $line): array
    {
        $idLine = $line->getId();
        if ($idLine === null) {
            throw new LogicException('Génération de l\'agenda : ligne de prescription sans identifiant.');
        }
        $appointments = $this->appointmentDao->findByRequest((string) $idLine, $line instanceof MedicationRequest);

        return array_values(array_filter(array_map(
            static fn (Appointment $a): ?DateTimeImmutable => $a->getPlannedTime(),
            $appointments
        )));
    }
}
