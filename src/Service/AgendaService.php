<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\ActivityDefinitionDao;
use Aura\Dao\AppointmentDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\MedicationDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Domain\CarePlan;
use Aura\Domain\MedicationRequest;
use Aura\Domain\ServiceRequest;
use DateTimeImmutable;
use DateTimeInterface;

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
    ) {
    }

    public function getAgendaService(string $idHealthServiceId): array
    {
 // TODO
        $response = [];

        $episodesOfCare = $this->episodeOfCareDao->findByHealthcareService($idHealthServiceId);
        foreach ($episodesOfCare as $episodeOfCare) {
            $response[] = $this->getAgenda($episodeOfCare->getIdEpisodeOfCare());
        }
        return $response;
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
        return $response;
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
