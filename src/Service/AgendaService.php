<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\ActivityDefinitionDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\AppointmentDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\MedicationDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\PatientDao;
use Aura\Domain\AllergyIntolerance;
use Aura\Domain\CarePlan;
use Aura\Domain\MedicationRequest;
use Aura\Domain\ServiceRequest;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;

final class AgendaService
{
    /**
     * Rotations de l'équipe soignante, en heures (début inclus, fin exclue), dans l'ordre
     * chronologique de la journée civile : la nuit chevauche minuit, elle apparaît donc en deux
     * tranches. Bornes 6 h / 14 h / 22 h = HYPOTHÈSE à confirmer avec le service (docs/ui.md).
     */
    public const SHIFTS = [
        ['key' => 'night-end', 'label' => 'Nuit (fin)', 'hours' => '0 h – 6 h', 'from' => 0, 'to' => 6],
        ['key' => 'morning', 'label' => 'Matin', 'hours' => '6 h – 14 h', 'from' => 6, 'to' => 14],
        ['key' => 'afternoon', 'label' => 'Après-midi', 'hours' => '14 h – 22 h', 'from' => 14, 'to' => 22],
        ['key' => 'night-start', 'label' => 'Nuit (début)', 'hours' => '22 h – 24 h', 'from' => 22, 'to' => 24],
    ];

    /** Statut en base (Appointment.status) → clé du badge (components/status_badge.php). */
    public const STATUS_KEYS = [
        'PLANIFIE' => 'planned',
        'REALISE' => 'done',
        'ANNULE' => 'cancelled',
        'NON_FAIT' => 'not_done',
    ];

    /** Durée d'un créneau de la vue du service, en heures (0 h, 2 h… 22 h). */
    public const SERVICE_SLOT_HOURS = 2;

    private const DAYS_FR = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    private const MONTHS_FR = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    public function __construct(
        private CarePlanDao $carePlanDao,
        private AppointmentDao $appointmentDao,
        private ActivityDefinitionDao $activityDefinitionDao,
        private EpisodeOfCareDao $episodeOfCareDao,
        private MedicationDao $medicationDao,
        private ?PatientDao $patientDao = null,
        private ?AuditEventDao $auditEventDao = null,
        private ?AllergyIntoleranceDao $allergyIntoleranceDao = null,
    ) {
    }

    /**
     * Agenda du service (AURA-29) : un élément par séjour en cours du service, avec l'identité du
     * patient, ses allergies (RG2 : visibles sur tout écran de soins) et ses prescriptions au format
     * de getAgenda(). Requiert le PatientDao ; les allergies demandent l'AllergyIntoleranceDao.
     *
     * @return list<array{idEpisodeOfCare: int, lastName: string, firstName: string, bed: ?string,
     *                    episodeNumber: string, allergies: ?list<array{label: string, level: string}>,
     *                    carePlans: list<array<string, mixed>>}>
     */
    public function getAgendaService(string $idHealthServiceId): array
    {
        if ($this->patientDao === null) {
            throw new LogicException('AgendaService::getAgendaService() requiert un PatientDao.');
        }
        $response = [];

        $episodesOfCare = $this->episodeOfCareDao->findByHealthcareService($idHealthServiceId);
        foreach ($episodesOfCare as $episodeOfCare) {
            $idEpisodeOfCare = (int) $episodeOfCare->getIdEpisodeOfCare();
            $patient = $this->patientDao->findById($episodeOfCare->getIdPatient());
            $response[] = [
                'idEpisodeOfCare' => $idEpisodeOfCare,
                'lastName' => $patient?->getLastName() ?? '',
                'firstName' => $patient?->getFirstName() ?? '',
                'bed' => $episodeOfCare->getBedNumber(),
                'episodeNumber' => $episodeOfCare->getEpisodeNumber(),
                // null = allergies non lues (pas de DAO), [] = aucune allergie connue.
                'allergies' => $this->allergyIntoleranceDao === null ? null : array_map(
                    static fn (AllergyIntolerance $a): array => [
                        'label' => $a->getSubstance(),
                        'level' => AllergyIntolerance::criticalityLevel($a->getCriticality()),
                    ],
                    $this->allergyIntoleranceDao->findByPatient($episodeOfCare->getIdPatient())
                ),
                'carePlans' => $this->getAgenda($idEpisodeOfCare),
            ];
        }
        return $response;
    }

    /**
     * Vue-modèle de l'agenda du service (src/View/pages/service/consolidated-view.php), en vertical :
     * un créneau de SERVICE_SLOT_HOURS heures par ligne, un patient par colonne, et dans chaque case
     * les soins du créneau (libellé, horaire, statut). Les retards de tout le service sont aussi
     * listés en tête (AURA-32). Mêmes règles que presentDay() (retard = calcul d'affichage).
     * Pure : le jour affiché et l'heure courante sont injectés.
     *
     * @param list<array<string, mixed>> $episodes sortie de getAgendaService()
     * @return array{date: string, dateLabel: string, isToday: bool, previous: string, next: string,
     *               slots: list<string>, currentSlot: ?string, late: int,
     *               lateItems: list<array<string, mixed>>, patients: list<array<string, mixed>>}
     */
    public static function presentService(array $episodes, DateTimeImmutable $day, DateTimeImmutable $now): array
    {
        $day = $day->setTime(0, 0);
        $slots = [];
        for ($hour = 0; $hour < 24; $hour += self::SERVICE_SLOT_HOURS) {
            $slots[] = sprintf('%02d:00', $hour);
        }
        $slotOf = static fn (int $hour): string => $slots[intdiv($hour, self::SERVICE_SLOT_HOURS)];
        $isToday = $day->format('Y-m-d') === $now->format('Y-m-d');

        $patients = [];
        $lateItems = [];
        foreach ($episodes as $episode) {
            $agenda = self::presentDay($episode['carePlans'] ?? [], $day, $now);
            $items = $agenda['late'];
            foreach ($agenda['shifts'] as $shift) {
                $items = [...$items, ...$shift['items']];
            }
            usort(
                $items,
                static fn (array $a, array $b): int => [$a['datetime'], $a['label']] <=> [$b['datetime'], $b['label']]
            );

            $name = mb_strtoupper((string) $episode['lastName']) . ' ' . $episode['firstName'];
            $cells = [];
            $bySlot = [];
            foreach ($items as $item) {
                $slot = $slotOf((int) $item['hour']);
                $cells[$slot][$item['status']] = ($cells[$slot][$item['status']] ?? 0) + 1;
                $bySlot[$slot][] = $item;
                if ($item['status'] === 'late') {
                    $lateItems[] = $item + [
                        'idEpisodeOfCare' => (int) $episode['idEpisodeOfCare'],
                        'patient' => $name,
                        'bed' => $episode['bed'] ?? null,
                    ];
                }
            }
            // Ordre stable des effectifs d'une case : ce qui demande une action d'abord.
            foreach ($cells as &$cell) {
                uksort($cell, static fn (string $a, string $b): int => self::cellRank($a) <=> self::cellRank($b));
            }
            unset($cell);
            $patients[] = [
                'idEpisodeOfCare' => (int) $episode['idEpisodeOfCare'],
                'lastName' => (string) $episode['lastName'],
                'firstName' => (string) $episode['firstName'],
                'bed' => $episode['bed'] ?? null,
                'episodeNumber' => (string) $episode['episodeNumber'],
                'referent' => $episode['referent'] ?? null,
                'allergies' => $episode['allergies'] ?? null,
                'late' => $agenda['counts']['late'],
                'total' => $agenda['counts']['total'],
                'cells' => $cells,
                'items' => $bySlot,
            ];
        }
        usort($lateItems, static fn (array $a, array $b): int => $a['datetime'] <=> $b['datetime']);

        return [
            'date' => $day->format('Y-m-d'),
            'dateLabel' => self::dayLabel($day),
            'isToday' => $isToday,
            'previous' => $day->modify('-1 day')->format('Y-m-d'),
            'next' => $day->modify('+1 day')->format('Y-m-d'),
            'slots' => $slots,
            'currentSlot' => $isToday ? $slotOf((int) $now->format('G')) : null,
            'late' => count($lateItems),
            'lateItems' => $lateItems,
            'patients' => $patients,
        ];
    }

    public function getAgenda(int $idEpisodeOfCare): array
    {
        $response = [];
        // On récupère les prescriptions associées au Séjour
        $carePlans = $this->carePlanDao->findByEpisodeOfCare($idEpisodeOfCare);
        // Pour chaque prescription :
        foreach ($carePlans as $carePlan) {
            $idCarePlan = (int) $carePlan->getIdCarePlan();
            // on initialise la liste des soins et des medicaments
            $serviceAppointments = [];
            $medicationAppointments = [];

            // D'une part, on récupère les Soins associés
            $serviceRequests = $this->carePlanDao->findServiceByCarePlan($idCarePlan);
            foreach ($serviceRequests as $serviceRequest) {
                // Pour chaque soin, on récupère les SoinsPlanifiés (indexés par ligne)
                $serviceAppointments[$serviceRequest->getIdServiceRequest()] = $this->appointmentDao
                    ->findByRequest((string) $serviceRequest->getIdServiceRequest(), false);
                //ActivityDefinition
                $activity = $this->activityDefinitionDao->findById($serviceRequest->getIdActivityDefinition());
                $serviceRequest->setIdActivityDefinition($activity->getName());
            }

            // D'autre part, on récupère les Médicaments associés
            $medicationRequests = $this->carePlanDao->findMedicationByCarePlan($idCarePlan);
            foreach ($medicationRequests as $medicationRequest) {
                //Pour chaque Médicament, on récupère les SoinsPlanifiés associés (indexés par ligne)
                $medicationAppointments[$medicationRequest->getIdMedicationRequest()] = $this->appointmentDao
                    ->findByRequest((string) $medicationRequest->getIdMedicationRequest(), true);

                //Medication
                // $activity = $this->activityDefinitionDao->findById($medicationRequest->getIdActivityDefinition());
                $medication = $this->medicationDao->findById($medicationRequest->getIdMedication());
                $medicationRequest->setIdActivityDefinition($medication->getInn());
            }


            // Assemblage de la réponse HTTP
            $response[] = $this->serializeCarePlan(
                $carePlan,
                $serviceRequests,
                $serviceAppointments,
                $medicationRequests,
                $medicationAppointments
            );
        }
        return $this->withPerformers($response);
    }

    /**
     * Ajoute à chaque soin planifié l'auteur de sa validation (AURA-26), lu dans l'historique ;
     * sans AuditEventDao, la réponse est rendue telle quelle.
     *
     * @param list<array<string, mixed>> $carePlans
     * @return list<array<string, mixed>>
     */
    private function withPerformers(array $carePlans): array
    {
        if ($this->auditEventDao === null) {
            return $carePlans;
        }
        $ids = [];
        foreach ($carePlans as $plan) {
            foreach ([...($plan['serviceRequests'] ?? []), ...($plan['medicationRequests'] ?? [])] as $line) {
                foreach ($line['appointments'] ?? [] as $appointment) {
                    $ids[] = (string) $appointment['idAppointment'];
                }
            }
        }
        $authors = $this->auditEventDao->appointmentAuthors($ids);
        foreach ($carePlans as &$plan) {
            foreach (['serviceRequests', 'medicationRequests'] as $kind) {
                foreach ($plan[$kind] ?? [] as $l => $line) {
                    foreach ($line['appointments'] ?? [] as $a => $appointment) {
                        $plan[$kind][$l]['appointments'][$a]['performedBy'] =
                            $authors[(string) $appointment['idAppointment']] ?? null;
                    }
                }
            }
        }
        unset($plan);

        return $carePlans;
    }

    public function serializeCarePlan(
        CarePlan $carePlan,
        array $serviceRequests,
        array $serviceAppointments,
        array $medicationRequests,
        array $medicationAppointments
    ): array {
        return [
            'idCarePlan' => $carePlan->getIdCarePlan(),
            'idEpisodeOfCare' => $carePlan->getIdEpisodeOfCare(),
            'idPrescriber' => $carePlan->getIdPrescriber(),
            'mode' => $carePlan->getMode(),
            'recordedAt' => $carePlan->getRecordedAt(),
            'idValidator' => $carePlan->getIdValidator(),
            'validatedAt' => $carePlan->getValidatedAt(),
            'serviceRequests' => $this->serializeService($serviceRequests, $serviceAppointments),
            'medicationRequests' => $this->serializeMedication($medicationRequests, $medicationAppointments),
        ];
    }

    public function serializeService(array $requests, array $appointments): ?array
    {
        $res = [];
        foreach ($requests as $request) {
            $res[] = [
                'idServiceRequest' => $request->getIdServiceRequest(),
                'idCarePlan' => $request->getIdCarePlan(),
                'idActivityDefinition' => $request->getIdActivityDefinition(),
                'startDate' => $request->getStartDate(),
                'endDate' => $request->getEndDate(),
                'frequencyHours' => $request->getFrequencyHours(),
                'timeConstraint' => $request->getTimeConstraint(),
                'idStoppedBy' => $request->getIdStoppedBy(),
                'stoppedAt' => $request->getStoppedAt(),
                'appointments' => $this->serializeAppointment($appointments[$request->getIdServiceRequest()] ?? []),
            ];
        }
        return $res;
    }

    public function serializeMedication(array $requests, array $appointments): ?array
    {
        $res = [];
        foreach ($requests as $request) {
            $res[] = [
                'idMedicationRequest' => $request->getIdMedicationRequest(),
                'idCarePlan' => $request->getIdCarePlan(),
                'idActivityDefinition' => $request->getIdActivityDefinition(),
                'idMedication' => $request->getIdMedication(),
                'dose' => $request->getDose(),
                'route' => $request->getRoute(),
                'startDate' => $request->getStartDate(),
                'endDate' => $request->getEndDate(),
                'frequencyHours' => $request->getFrequencyHours(),
                'timeConstraint' => $request->getTimeConstraint(),
                'idStoppedBy' => $request->getIdStoppedBy(),
                'stoppedAt' => $request->getStoppedAt(),
                'appointments' => $this->serializeAppointment(
                    $appointments[$request->getIdMedicationRequest()] ?? []
                ),

            ];
        }
        return $res;
    }

    public function serializeAppointment(array $appointments): ?array
    {
        $res = [];
        foreach ($appointments as $appointment) {
            $res[] = [
                'idAppointment' => $appointment->getIdAppointment(),
                'idServiceRequest' => $appointment->getIdServiceRequest(),
                'idMedicationRequest' => $appointment->getIdMedicationRequest(),
                'plannedTime' => $appointment->getPlannedTime(),
                'status' => $appointment->getStatus(),
                'timeOfCompletion' => $appointment->getTimeOfCompletion(),
                'timeRecorded' => $appointment->getTimeRecorded(),
                'comment' => $appointment->getComment(),
            ];
        }
        return $res;
    }

    /**
     * Jour demandé dans l'URL (AAAA-MM-JJ) ; toute autre valeur (absente, mal formée, 2026-02-30)
     * ramène à aujourd'hui. Pure : la date du jour est injectée.
     */
    public static function parseDay(mixed $value, DateTimeImmutable $today): DateTimeImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $today->getTimezone());
            if ($day !== false && $day->format('Y-m-d') === $value) {
                return $day;
            }
        }

        return $today->setTime(0, 0);
    }

    /**
     * Vue-modèle de l'agenda d'un jour (src/View/pages/episode/patient-agenda.php) à partir du
     * résultat de getAgenda() : soins et médicaments du jour triés par heure, les retards épinglés
     * en tête, le reste réparti par rotation. Pure : le jour affiché et l'heure courante sont injectés.
     *
     * « En retard » = statut PLANIFIE dont l'heure prévue est dépassée : c'est un calcul d'affichage,
     * pas une donnée enregistrée (aucun statut EN_RETARD en base).
     *
     * @param list<array<string, mixed>> $carePlans sortie de getAgenda()
     * @return array{date: string, dateLabel: string, isToday: bool, previous: string, next: string,
     *               counts: array{total: int, late: int, done: int, planned: int},
     *               late: list<array<string, mixed>>,
     *               shifts: list<array{key: string, label: string, hours: string, items: list<array<string, mixed>>}>}
     */
    public static function presentDay(array $carePlans, DateTimeImmutable $day, DateTimeImmutable $now): array
    {
        $day = $day->setTime(0, 0);
        $items = [];
        foreach ($carePlans as $plan) {
            foreach ($plan['serviceRequests'] ?? [] as $line) {
                foreach ($line['appointments'] ?? [] as $appointment) {
                    $items[] = self::presentItem($appointment, $line, false, $day, $now);
                }
            }
            foreach ($plan['medicationRequests'] ?? [] as $line) {
                foreach ($line['appointments'] ?? [] as $appointment) {
                    $items[] = self::presentItem($appointment, $line, true, $day, $now);
                }
            }
        }
        $items = array_values(array_filter($items));
        usort(
            $items,
            static fn (array $a, array $b): int => [$a['datetime'], $a['label']] <=> [$b['datetime'], $b['label']]
        );

        $late = array_values(array_filter($items, static fn (array $i): bool => $i['status'] === 'late'));
        $shifts = [];
        foreach (self::SHIFTS as $shift) {
            $shifts[] = $shift + ['items' => array_values(array_filter(
                $items,
                static fn (array $i): bool => $i['status'] !== 'late'
                    && $i['hour'] >= $shift['from'] && $i['hour'] < $shift['to']
            ))];
        }

        $countBy = static fn (string $status): int => count(
            array_filter($items, static fn (array $i): bool => $i['status'] === $status)
        );

        return [
            'date' => $day->format('Y-m-d'),
            'dateLabel' => self::dayLabel($day),
            'isToday' => $day->format('Y-m-d') === $now->format('Y-m-d'),
            'previous' => $day->modify('-1 day')->format('Y-m-d'),
            'next' => $day->modify('+1 day')->format('Y-m-d'),
            'counts' => [
                'total' => count($items),
                'late' => count($late),
                'done' => $countBy('done'),
                'planned' => $countBy('planned'),
            ],
            'late' => $late,
            'shifts' => $shifts,
        ];
    }

    /** Rang d'un statut dans une cellule de la vue du service ; un statut inconnu passe en dernier. */
    private static function cellRank(string $status): int
    {
        $rank = array_search($status, ['late', 'planned', 'done', 'not_done', 'cancelled'], true);

        return $rank === false ? 99 : $rank;
    }

    /**
     * Vue-modèle de la semaine (lundi → dimanche) qui contient le jour demandé : une ligne par soin
     * ou médicament prescrit, une colonne par jour, et dans chaque case les horaires du jour avec leur
     * statut (mêmes règles que presentDay()). Pure : le jour et l'heure courante sont injectés.
     *
     * @param list<array<string, mixed>> $carePlans sortie de getAgenda()
     * @return array{start: string, label: string, previous: string, next: string,
     *               days: list<array{date: string, label: string, isToday: bool}>,
     *               rows: list<array{label: string, details: ?string, kindLabel: string,
     *                                cells: array<string, list<array<string, mixed>>>}>,
     *               counts: array{total: int, late: int, done: int, planned: int}}
     */
    public static function presentWeek(array $carePlans, DateTimeImmutable $day, DateTimeImmutable $now): array
    {
        $day = $day->setTime(0, 0);
        $monday = $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
        $days = [];
        $rows = [];
        $counts = ['total' => 0, 'late' => 0, 'done' => 0, 'planned' => 0];
        for ($i = 0; $i < 7; $i++) {
            $date = $monday->modify('+' . $i . ' days');
            $key = $date->format('Y-m-d');
            $days[] = [
                'date' => $key,
                'label' => sprintf(
                    '%s %d',
                    mb_substr(self::DAYS_FR[(int) $date->format('w')], 0, 3),
                    $date->format('j')
                ),
                'isToday' => $key === $now->format('Y-m-d'),
            ];
            $agenda = self::presentDay($carePlans, $date, $now);
            $items = $agenda['late'];
            foreach ($agenda['shifts'] as $shift) {
                $items = [...$items, ...$shift['items']];
            }
            usort($items, static fn (array $a, array $b): int => $a['datetime'] <=> $b['datetime']);
            foreach ($items as $item) {
                // Une ligne par soin prescrit : même libellé, même nature, mêmes précisions.
                $rowKey = $item['kind'] . '|' . $item['label'] . '|' . ($item['details'] ?? '');
                $rows[$rowKey] ??= [
                    'label' => $item['label'],
                    'details' => $item['details'],
                    'kindLabel' => $item['kindLabel'],
                    'cells' => [],
                ];
                $rows[$rowKey]['cells'][$key][] = $item;
            }
            foreach (['total', 'late', 'done', 'planned'] as $count) {
                $counts[$count] += $agenda['counts'][$count];
            }
        }
        ksort($rows);
        $sunday = $monday->modify('+6 days');

        return [
            'start' => $monday->format('Y-m-d'),
            'label' => sprintf(
                'du %s au %s',
                self::DAYS_FR[1] . ' ' . (int) $monday->format('j')
                    . ($monday->format('n') !== $sunday->format('n')
                        ? ' ' . self::MONTHS_FR[(int) $monday->format('n')] : ''),
                self::dayLabel($sunday)
            ),
            'previous' => $monday->modify('-7 days')->format('Y-m-d'),
            'next' => $monday->modify('+7 days')->format('Y-m-d'),
            'days' => $days,
            'rows' => array_values($rows),
            'counts' => $counts,
        ];
    }

    /** « mardi 14 octobre 2026 », sans dépendre de l'extension intl. */
    public static function dayLabel(DateTimeInterface $day): string
    {
        return sprintf(
            '%s %d %s %s',
            self::DAYS_FR[(int) $day->format('w')],
            (int) $day->format('j'),
            self::MONTHS_FR[(int) $day->format('n')],
            $day->format('Y')
        );
    }

    /**
     * Une ligne de l'agenda ; null si le soin n'est pas prévu ce jour-là.
     *
     * @param array<string, mixed> $appointment
     * @param array<string, mixed> $line ServiceRequest ou MedicationRequest sérialisé
     * @return array<string, mixed>|null
     */
    private static function presentItem(
        array $appointment,
        array $line,
        bool $isMedication,
        DateTimeImmutable $day,
        DateTimeImmutable $now
    ): ?array {
        $planned = $appointment['plannedTime'] ?? null;
        if (!$planned instanceof DateTimeInterface || $planned->format('Y-m-d') !== $day->format('Y-m-d')) {
            return null;
        }

        $raw = trim((string) ($appointment['status'] ?? ''));
        // Valeur inconnue en base : transmise telle quelle, le badge l'affiche en neutre plutôt que la cacher.
        $status = self::STATUS_KEYS[$raw] ?? ($raw === '' ? 'planned' : $raw);
        if ($status === 'planned' && $planned < $now) {
            $status = 'late';
        }

        $details = [];
        if ($isMedication) {
            foreach (['dose', 'route'] as $field) {
                $value = trim((string) ($line[$field] ?? ''));
                if ($value !== '') {
                    $details[] = $value;
                }
            }
        }
        $constraint = trim((string) ($line['timeConstraint'] ?? ''));
        if ($constraint !== '') {
            $details[] = $constraint;
        }

        $label = trim((string) ($line['idActivityDefinition'] ?? ''));
        $completion = $appointment['timeOfCompletion'] ?? null;
        $recorded = $appointment['timeRecorded'] ?? null;
        $comment = trim((string) ($appointment['comment'] ?? ''));

        return [
            'id' => (string) ($appointment['idAppointment'] ?? ''),
            'time' => $planned->format('H:i'),
            'datetime' => $planned->format('Y-m-d\TH:i'),
            'hour' => (int) $planned->format('G'),
            'kind' => $isMedication ? 'MEDICATION' : 'CARE',
            'kindLabel' => $isMedication ? 'Médicament' : 'Soin',
            'label' => $label !== '' ? $label : ($isMedication ? 'Médicament' : 'Soin'),
            'details' => $details === [] ? null : implode(' · ', $details),
            // Catégorie de personnel : non stockée en base ; l'administration d'un médicament est un
            // acte IDE, un soin peut revenir à l'IDE ou à l'aide-soignant(e). Hypothèse documentée.
            'responsible' => $isMedication ? 'IDE' : 'IDE / AS',
            'status' => $status,
            'completedAt' => $completion instanceof DateTimeInterface ? $completion->format('H:i') : null,
            'recordedAt' => $recorded instanceof DateTimeInterface ? $recorded->format('d/m H:i') : null,
            'comment' => $comment !== '' ? $comment : null,
            // Qui a validé ou marqué non effectué (historique, AURA-26) ; null tant que personne.
            'performedBy' => isset($appointment['performedBy']) ? (string) $appointment['performedBy'] : null,
        ];
    }

// format des données renvoyées à la page web
// {
//     careplans: [
//         {
//             idCarePlan: 1;
//             idEpisodeOfCare: ;
//             idPrescriber: ;
//             mode: ,
//             recordedAt: ,
//             idValidator: ,
//             validatedAt: null,
//             serviceRequests : [
//                 {
//                     idServiceRequest:null,
//                     idCarePlan,
//                     idActivityDefinition,
//                     startDate,
//                     endDate,
//                     frequencyHours,
//                     timeConstraint,
//                     idStoppedBy,
//                     stoppedAt;
//                     appointments: [
//                         {
//                             idAppointment,
//                             idServiceRequest,
//                             idMedicationRequest,
//                             plannedTime,
//                             status,
//                             timeOfCompletion,
//                             timeRecorded,
//                             comment
//                         }
//                     ]
//                 }
//             ],
//             medicationRequest: [
//                 {
//                     idMedicationRequest,
//                     idCarePlan,
//                     idActivityDefinition,
//                     idMedication,
//                     dose,
//                     route,
//                     startDate,
//                     endDate,
//                     frequencyHours,
//                     timeConstraint,
//                     idStoppedBy,
//                     stoppedAt,
//                     appointments: [
//                         {
//                             idAppointment,
//                             idServiceRequest,
//                             idMedicationRequest,
//                             plannedTime,
//                             status,
//                             timeOfCompletion,
//                             timeRecorded,
//                             comment
//                         }
//                     ]
//                 }
//             ]
//         }
//     ]
// }
}
