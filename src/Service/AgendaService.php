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
use Aura\Domain\CareTeam;
use Aura\Domain\AllergyIntolerance;
use Aura\Domain\CarePlan;
use Aura\Domain\MedicationRequest;
use Aura\Domain\ServiceRequest;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;

/**
 * Agendas des soins (AURA-24 agenda du patient, AURA-29 agenda du service, AURA-31 « Mon planning »).
 *
 * Deux étages :
 *  - lecture en base : getAgenda() (un séjour) et getAgendaService() (tous les séjours en cours d'un
 *    service) assemblent ordonnances, lignes et soins planifiés en tableaux ;
 *  - présentation, méthodes statiques PURES (jour et heure courante injectés) : presentDay(), presentWeek(),
 *    presentService(), presentBoard(), presentShift() construisent les vues-modèles des écrans.
 *
 * « En retard » est un calcul d'affichage (soin PLANIFIE dont l'heure est passée), jamais stocké.
 * Un soin est validable jusqu'à la fin de la rotation en cours (shiftEnd()), même règle que
 * AppointmentService::apply() côté serveur.
 */
final class AgendaService
{
    /**
     * Rotations de l'équipe soignante, en heures (début inclus, fin exclue), dans l'ordre
     * chronologique de la journée civile : la nuit chevauche minuit, elle apparaît donc en deux
     * tranches. Mêmes bornes que CareTeam::SHIFTS (6 h, 14 h, 22 h : HYPOTHÈSE à confirmer avec le service),
     * découpées au jour civil pour l'affichage d'une journée.
     */
    public const SHIFTS = [
        ['key' => 'night-end', 'label' => 'Nuit (fin)', 'hours' => '0 h à 6 h', 'from' => 0, 'to' => 6],
        ['key' => 'morning', 'label' => 'Matin', 'hours' => '6 h à 14 h', 'from' => 6, 'to' => 14],
        ['key' => 'afternoon', 'label' => 'Après-midi', 'hours' => '14 h à 22 h', 'from' => 14, 'to' => 22],
        ['key' => 'night-start', 'label' => 'Nuit (début)', 'hours' => '22 h à 0 h', 'from' => 22, 'to' => 24],
    ];

    /**
     * Fin de la rotation en cours à l'instant $now (6 h, 14 h, 22 h, ou 6 h le lendemain pour la nuit
     * commencée à 22 h). Un soin prévu avant cette heure peut être validé ou déclaré non effectué ; un
     * soin d'une rotation à venir, non. Bornes lues dans CareTeam::SHIFTS, la seule référence des rotations
     * (équipes comprises).
     */
    public static function shiftEnd(DateTimeImmutable $now): DateTimeImmutable
    {
        $hour = (int) $now->format('G');
        $starts = array_column(CareTeam::SHIFTS, 'from');
        sort($starts);
        foreach ($starts as $end) {
            if ($hour < $end) {
                return $now->setTime($end, 0);
            }
        }

        return $now->modify('+1 day')->setTime($starts[0], 0);
    }

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
        private ?PatientDao $patientDao = null,
        private ?AuditEventDao $auditEventDao = null,
        private ?AllergyIntoleranceDao $allergyIntoleranceDao = null,
        /** Noms des praticiens (idPractitioner => « Prénom Nom (rôle) ») pour la carte de prescription. */
        private array $practitionerNames = [],
    ) {
    }

    /**
     * Agenda du service (AURA-29) : un élément par séjour en cours du service, avec l'identité du
     * patient, son décès éventuel, ses allergies (RG2), son médecin référent et ses prescriptions au
     * format de getAgenda(). Requiert le PatientDao ; les allergies demandent l'AllergyIntoleranceDao.
     * Sert aussi aux équipes et à « Mon planning » (TeamController).
     *
     * @param string $idHealthServiceId identifiant du service (HealthcareService)
     * @return list<array{idEpisodeOfCare: int, lastName: string, firstName: string, bed: ?string,
     *                    episodeNumber: string, deceased: ?string,
     *                    allergies: ?list<array{label: string, level: string}>, referent: ?string,
     *                    carePlans: list<array<string, mixed>>}>
     * @throws LogicException si le service a été construit sans PatientDao
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
                // Décès déclaré : visible sur tous les écrans de soins, comme dans le bandeau.
                'deceased' => $patient !== null && PatientRecordService::isDeceased($patient)
                    ? PatientRecordService::deceasedLabel($patient->getSex()) : null,
                // null = allergies non lues (pas de DAO), [] = aucune allergie connue.
                'allergies' => $this->allergyIntoleranceDao === null ? null : array_map(
                    static fn (AllergyIntolerance $a): array => [
                        'label' => $a->getSubstance(),
                        'level' => AllergyIntolerance::criticalityLevel($a->getCriticality()),
                    ],
                    $this->allergyIntoleranceDao->findByPatient($episodeOfCare->getIdPatient())
                ),
                // Médecin référent (« Dr Prénom Nom »), null = à désigner.
                'referent' => PatientRecordService::referent($episodeOfCare),
                'carePlans' => $this->getAgenda($idEpisodeOfCare),
            ];
        }
        return $response;
    }

    /**
     * Vue-modèle du jour de l'agenda du service : jour affiché et navigation, et pour chaque patient
     * ses soins du jour par ordre chronologique (retards de la veille compris). L'écran
     * (consolidated-view.php) lit ici le jour et la navigation ; presentBoard() construit le tableau des
     * lits et la liste de travail à partir de cette sortie. Mêmes règles que presentDay().
     * Pure : le jour affiché et l'heure courante sont injectés.
     *
     * @param list<array<string, mixed>> $episodes sortie de getAgendaService()
     * @return array{date: string, dateLabel: string, isToday: bool, previous: string, next: string,
     *               patients: list<array<string, mixed>>}
     */
    public static function presentService(array $episodes, DateTimeImmutable $day, DateTimeImmutable $now): array
    {
        $day = $day->setTime(0, 0);
        $patients = [];
        foreach ($episodes as $episode) {
            // Retards de la veille portés sur aujourd'hui (une dose de 23 h ne disparaît pas à minuit).
            $agenda = self::presentDay($episode['carePlans'] ?? [], $day, $now, carryOverdue: true);
            $items = $agenda['late'];
            foreach ($agenda['shifts'] as $shift) {
                $items = [...$items, ...$shift['items']];
            }
            usort(
                $items,
                static fn (array $a, array $b): int => [$a['datetime'], $a['label']] <=> [$b['datetime'], $b['label']]
            );
            $patients[] = [
                'idEpisodeOfCare' => (int) $episode['idEpisodeOfCare'],
                'lastName' => (string) $episode['lastName'],
                'firstName' => (string) $episode['firstName'],
                'bed' => $episode['bed'] ?? null,
                'episodeNumber' => (string) $episode['episodeNumber'],
                'referent' => $episode['referent'] ?? null,
                'deceased' => $episode['deceased'] ?? null,
                // Le référent est-il connu de cette source ? (sinon la vue ne dit pas « à désigner » à tort)
                'referentKnown' => array_key_exists('referent', $episode),
                'allergies' => $episode['allergies'] ?? null,
                'items' => $items,
            ];
        }

        return [
            'date' => $day->format('Y-m-d'),
            'dateLabel' => self::dayLabel($day),
            'isToday' => $day->format('Y-m-d') === $now->format('Y-m-d'),
            'previous' => $day->modify('-1 day')->format('Y-m-d'),
            'next' => $day->modify('+1 day')->format('Y-m-d'),
            'patients' => $patients,
        ];
    }

    /**
     * Agenda du service, construit sur la sortie de presentService() :
     *  - un TABLEAU DES LITS (une ligne par patient, du plus urgent au moins urgent) avec un PANNEAU DE
     *    DÉTAIL pour le patient choisi ;
     *  - une LISTE DE TRAVAIL du service, façon dossier de soins infirmiers : en retard, doses de lignes
     *    arrêtées à tracer, maintenant (deux heures, dans la rotation), plus tard dans la rotation, réalisés.
     * Pur (sans base ni HTTP).
     *
     * Urgence (HYPOTHÈSE à valider avec les médecins) : nombre de retards, puis le plus ancien retard, puis
     * nombre de soins à faire dans la rotation, puis le prochain soin, puis le lit (sans lit en dernier).
     *
     * La rotation est bornée au jour civil affiché : presentService() ne lit qu'un jour, la nuit est donc
     * « 22 h à 0 h » le soir et « 0 h à 6 h » après minuit (les soins de l'autre côté de minuit sont sur le
     * jour voisin). Jour qui n'est pas aujourd'hui : pas de rotation ; « à faire » = tous les soins prévus du
     * jour, le panneau montre toute la journée. Les retards sont toujours listés, quel que soit le jour.
     *
     * RG2 : les allergies de chaque patient suivent ses lignes et ses soins, mais l'écran ne les affiche
     * que dans le panneau du patient choisi.
     *
     * @param array<string, mixed> $service sortie de presentService()
     * @param int|null $selected séjour choisi pour le panneau, paramètre ?sejour= de l'URL (null : le premier
     *                           du tableau ; inconnu dans ce service : pas de panneau, selectionMissing = true)
     * @return array{isToday: bool, rotation: ?array{label: string, until: string},
     *               totals: array{patients: int, late: int, todo: int}, rows: list<array<string, mixed>>,
     *               selected: ?array<string, mixed>, selectionMissing: bool,
     *               worklist: array{late: list<array<string, mixed>>, stopped: list<array<string, mixed>>,
     *                               now: list<array<string, mixed>>, later: list<array<string, mixed>>,
     *                               done: list<array<string, mixed>>}}
     */
    public static function presentBoard(array $service, DateTimeImmutable $now, ?int $selected = null): array
    {
        $isToday = (bool) ($service['isToday'] ?? false);
        $dayStart = new DateTimeImmutable(($service['date'] ?? $now->format('Y-m-d')) . ' 00:00', $now->getTimezone());
        $dayEnd = $dayStart->modify('+1 day');
        $end = min(self::shiftEnd($now), $dayEnd);
        $start = max(self::shiftEnd($now)->modify('-8 hours'), $dayStart);
        $soon = min($now->modify('+2 hours'), $end);
        $fmt = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d\TH:i');
        [$startKey, $endKey, $soonKey] = [$fmt($start), $fmt($end), $fmt($soon)];
        $inShift = static fn (array $item): bool => !$isToday
            || ($item['datetime'] >= $startKey && $item['datetime'] < $endKey);
        $byTime = static fn (array $a, array $b): int => $a['datetime'] <=> $b['datetime'];

        $rows = [];
        $worklist = ['late' => [], 'stopped' => [], 'now' => [], 'later' => [], 'done' => []];
        $panels = [];
        foreach ($service['patients'] ?? [] as $patient) {
            $items = $patient['items'] ?? [];
            usort($items, $byTime);
            $id = (int) $patient['idEpisodeOfCare'];
            // Chaque soin de la liste de travail porte son patient (lit, nom, décès, allergies). La vue
            // n'affiche les allergies que dans le panneau du patient choisi (voir le docblock).
            $who = [
                'idEpisodeOfCare' => $id,
                'bed' => $patient['bed'] ?? null,
                'patient' => mb_strtoupper((string) $patient['lastName']) . ' ' . $patient['firstName'],
                'allergies' => $patient['allergies'] ?? null,
                'deceased' => $patient['deceased'] ?? null,
            ];
            [$late, $todo, $oldestLate, $next, $panel] = [0, 0, null, null, []];
            foreach ($items as $item) {
                $entry = $item + $who;
                switch ($item['status']) {
                    case 'late':
                        $late++;
                        $oldestLate ??= $item['datetime'];
                        $worklist['late'][] = $entry;
                        $panel[] = $entry;
                        break;
                    case 'stopped':
                        // Dose prévue d'une ligne arrêtée, ni faite ni annulée : à tracer ou à laisser.
                        $todo++;
                        $worklist['stopped'][] = $entry;
                        $panel[] = $entry;
                        break;
                    case 'planned':
                        $next ??= $item;
                        if (!$isToday) {
                            $todo++;
                            $worklist['later'][] = $entry;
                        } elseif ($item['datetime'] < $endKey) {
                            $todo++;
                            $worklist[$item['datetime'] < $soonKey ? 'now' : 'later'][] = $entry;
                        }
                        if ($inShift($item)) {
                            $panel[] = $entry;
                        }
                        break;
                    default:
                        if (in_array($item['status'], ['done', 'not_done'], true) && $inShift($item)) {
                            $worklist['done'][] = $entry;
                        }
                        if ($inShift($item)) {
                            $panel[] = $entry;
                        }
                }
            }
            $rows[] = $who + [
                'episodeNumber' => $patient['episodeNumber'] ?? null,
                'referent' => $patient['referent'] ?? null,
                'referentKnown' => $patient['referentKnown'] ?? false,
                'late' => $late,
                'todo' => $todo,
                'next' => $next === null ? null : ['time' => $next['time'], 'label' => $next['label']],
                'urgency' => [-$late, $oldestLate ?? '~', -$todo, $next['datetime'] ?? '~',
                    $who['bed'] === null ? 1 : 0],
            ];
            $panels[$id] = $panel;
        }
        usort($rows, static fn (array $a, array $b): int => $a['urgency'] <=> $b['urgency']
            ?: strnatcmp((string) $a['bed'], (string) $b['bed']));
        foreach ($worklist as $key => $list) {
            usort($list, $byTime);
            $worklist[$key] = $key === 'done' ? array_reverse($list) : $list;
        }

        $chosen = null;
        foreach ($rows as $row) {
            if ($row['idEpisodeOfCare'] === ($selected ?? $rows[0]['idEpisodeOfCare'])) {
                $chosen = $row;
            }
        }

        return [
            'isToday' => $isToday,
            'rotation' => $isToday ? [
                'label' => sprintf('%d h à %d h', (int) $start->format('G'), (int) $end->format('G')),
                'until' => $soon->format('H:i'),
            ] : null,
            'totals' => [
                'patients' => count($rows),
                'late' => array_sum(array_column($rows, 'late')),
                'todo' => array_sum(array_column($rows, 'todo')),
            ],
            'rows' => $rows,
            'selected' => $chosen === null ? null : $chosen + ['items' => $panels[$chosen['idEpisodeOfCare']]],
            'selectionMissing' => $selected !== null && $chosen === null,
            'worklist' => $worklist,
        ];
    }

    /**
     * Agenda d'un séjour (AURA-24) : ses ordonnances, avec pour chaque ligne de soin ou de médicament
     * ses soins planifiés (Appointment). Le champ idActivityDefinition de chaque ligne est remplacé par
     * son libellé : nom du type de soin (ActivityDefinition.name) ou DCI du médicament (Medication.inn).
     * Avec l'AuditEventDao, chaque soin planifié porte aussi performedBy et noteBy (withPerformers()).
     *
     * @return list<array{idCarePlan: ?int, idEpisodeOfCare: int, idPrescriber: string, mode: string,
     *                    recordedAt: ?DateTimeImmutable, idValidator: ?string, validatedAt: ?DateTimeImmutable,
     *                    note: ?string,
     *                    prescriberName: ?string, validatorName: ?string,
     *                    serviceRequests: list<array<string, mixed>>,
     *                    medicationRequests: list<array<string, mixed>>}>
     *         voir serializeService(), serializeMedication() et serializeAppointment() pour les lignes
     */
    public function getAgenda(int $idEpisodeOfCare): array
    {
        $response = [];
        $carePlans = $this->carePlanDao->findByEpisodeOfCare($idEpisodeOfCare);
        foreach ($carePlans as $carePlan) {
            $idCarePlan = (int) $carePlan->getIdCarePlan();
            // Soins planifiés indexés par identifiant de ligne.
            $serviceAppointments = [];
            $medicationAppointments = [];

            // Lignes de soin : le libellé affiché est le nom du type de soin.
            $serviceRequests = $this->carePlanDao->findServiceByCarePlan($idCarePlan);
            foreach ($serviceRequests as $serviceRequest) {
                $serviceAppointments[$serviceRequest->getIdServiceRequest()] = $this->appointmentDao
                    ->findByRequest((string) $serviceRequest->getIdServiceRequest(), false);
                $activity = $this->activityDefinitionDao->findById($serviceRequest->getIdActivityDefinition());
                // Référentiel incomplet (type de soin supprimé ou sans nom) : on garde l'identifiant plutôt
                // que de planter.
                $serviceRequest->setIdActivityDefinition(
                    (string) ($activity?->getName() ?? $serviceRequest->getIdActivityDefinition())
                );
            }

            // Lignes de médicament : le libellé affiché est la DCI (repli sur l'identifiant du médicament).
            $medicationRequests = $this->carePlanDao->findMedicationByCarePlan($idCarePlan);
            foreach ($medicationRequests as $medicationRequest) {
                $medicationAppointments[$medicationRequest->getIdMedicationRequest()] = $this->appointmentDao
                    ->findByRequest((string) $medicationRequest->getIdMedicationRequest(), true);
                $medication = $this->medicationDao->findById($medicationRequest->getIdMedication());
                $medicationRequest->setIdActivityDefinition(
                    (string) ($medication?->getInn() ?? $medicationRequest->getIdMedication())
                );
            }

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
        $noteAuthors = $this->auditEventDao->appointmentNoteAuthors($ids);
        foreach ($carePlans as &$plan) {
            foreach (['serviceRequests', 'medicationRequests'] as $kind) {
                foreach ($plan[$kind] ?? [] as $l => $line) {
                    foreach ($line['appointments'] ?? [] as $a => $appointment) {
                        $plan[$kind][$l]['appointments'][$a]['performedBy'] =
                            $authors[(string) $appointment['idAppointment']] ?? null;
                        $plan[$kind][$l]['appointments'][$a]['noteBy'] =
                            $noteAuthors[(string) $appointment['idAppointment']] ?? null;
                    }
                }
            }
        }
        unset($plan);

        return $carePlans;
    }

    /**
     * Ordonnance en tableau pour l'agenda : ses champs, les noms du prescripteur et du validateur (lus
     * dans $practitionerNames, null si inconnus) et ses lignes avec leurs soins planifiés.
     *
     * @param list<ServiceRequest> $serviceRequests lignes de soin de l'ordonnance
     * @param array<int|string, list<\Aura\Domain\Appointment>> $serviceAppointments soins par idServiceRequest
     * @param list<MedicationRequest> $medicationRequests lignes de médicament de l'ordonnance
     * @param array<int|string, list<\Aura\Domain\Appointment>> $medicationAppointments soins par
     *        idMedicationRequest
     * @return array<string, mixed> voir getAgenda()
     */
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
            // Note du prescripteur (CarePlan.note) : affichée dans la carte « + » de chaque soin.
            'note' => $carePlan->getNote(),
            'prescriberName' => $this->practitionerNames[(string) $carePlan->getIdPrescriber()] ?? null,
            'validatorName' => $carePlan->getIdValidator() !== null
                ? ($this->practitionerNames[(string) $carePlan->getIdValidator()] ?? null) : null,
            'serviceRequests' => $this->serializeService($serviceRequests, $serviceAppointments),
            'medicationRequests' => $this->serializeMedication($medicationRequests, $medicationAppointments),
        ];
    }

    /**
     * Lignes de soin (ServiceRequest) en tableaux, chacune avec ses soins planifiés.
     *
     * @param list<ServiceRequest> $requests
     * @param array<int|string, list<\Aura\Domain\Appointment>> $appointments soins par idServiceRequest
     * @return list<array<string, mixed>> jamais null en pratique (liste vide sans ligne)
     */
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

    /**
     * Lignes de médicament (MedicationRequest) en tableaux, avec dose, voie et soins planifiés.
     *
     * @param list<MedicationRequest> $requests
     * @param array<int|string, list<\Aura\Domain\Appointment>> $appointments soins par idMedicationRequest
     * @return list<array<string, mixed>> jamais null en pratique (liste vide sans ligne)
     */
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

    /**
     * Soins planifiés en tableaux (heure prévue, statut en base, réalisation, note).
     *
     * @param list<\Aura\Domain\Appointment> $appointments
     * @return list<array{idAppointment: string, idServiceRequest: ?string, idMedicationRequest: ?string,
     *                    plannedTime: ?DateTimeImmutable, status: ?string,
     *                    timeOfCompletion: ?DateTimeImmutable, comment: ?string}> jamais null en pratique
     */
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
     * $carryOverdue : quand le jour affiché est aujourd'hui, les doses de la veille encore prévues et
     * dépassées sont ajoutées aux retards (heure suivie de « (veille) »), pour qu'une dose de 23 h non faite
     * ne disparaisse pas de l'agenda à minuit. Désactivé pour la vue Semaine, qui montre déjà la veille.
     *
     * @param list<array<string, mixed>> $carePlans sortie de getAgenda()
     * @return array{date: string, dateLabel: string, isToday: bool, previous: string, next: string,
     *               counts: array{total: int, late: int, done: int, planned: int},
     *               late: list<array<string, mixed>>,
     *               shifts: list<array{key: string, label: string, hours: string, items: list<array<string, mixed>>}>}
     */
    public static function presentDay(
        array $carePlans,
        DateTimeImmutable $day,
        DateTimeImmutable $now,
        bool $carryOverdue = false
    ): array {
        $day = $day->setTime(0, 0);
        $carry = $carryOverdue && $day->format('Y-m-d') === $now->format('Y-m-d');
        $yesterday = $day->modify('-1 day');
        $items = [];
        foreach ($carePlans as $plan) {
            foreach (['serviceRequests' => false, 'medicationRequests' => true] as $key => $isMedication) {
                foreach ($plan[$key] ?? [] as $line) {
                    foreach ($line['appointments'] ?? [] as $appointment) {
                        $items[] = self::presentItem($appointment, $line, $isMedication, $day, $now, $plan);
                        if ($carry) {
                            $overdue = self::presentItem($appointment, $line, $isMedication, $yesterday, $now, $plan);
                            if ($overdue !== null && $overdue['status'] === 'late') {
                                // L'heure dit que la dose date de la veille : « 23:00 (veille) ».
                                $items[] = ['time' => $overdue['time'] . ' (veille)'] + $overdue;
                            }
                        }
                    }
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

    /**
     * Vue-modèle d'une vacation pour un soignant (AURA-31, « Mon planning ») : les soins des patients
     * de son équipe dont l'heure prévue tombe dans la vacation [début, fin[, par ordre chronologique,
     * avec le patient de chaque soin, et un résumé par patient (allergies RG2, retards, à faire).
     * Mêmes règles que presentDay(). Pure : la fenêtre et l'heure courante sont injectées.
     *
     * @param list<array<string, mixed>> $episodes sortie de getAgendaService(), restreinte à l'équipe
     * @return array{items: list<array<string, mixed>>, patients: list<array<string, mixed>>,
     *               counts: array{total: int, late: int, done: int, planned: int}}
     */
    public static function presentShift(
        array $episodes,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        DateTimeImmutable $now
    ): array {
        $items = [];
        $patients = [];
        foreach ($episodes as $episode) {
            $name = mb_strtoupper((string) $episode['lastName']) . ' ' . $episode['firstName'];
            $own = [];
            // Une vacation de nuit chevauche deux jours civils : on lit chaque jour qu'elle touche.
            // Un retard de la vacation précédente (avant $start) est repris en tête, sinon il n'est plus
            // visible de personne à la relève. Dédoublonné par soin.
            $seen = [];
            for ($day = $start->setTime(0, 0); $day < $end; $day = $day->modify('+1 day')) {
                $agenda = self::presentDay($episode['carePlans'] ?? [], $day, $now, carryOverdue: true);
                foreach ([$agenda['late'], ...array_column($agenda['shifts'], 'items')] as $list) {
                    foreach ($list as $item) {
                        $at = new DateTimeImmutable($item['datetime']);
                        $inShift = $at >= $start && $at < $end;
                        $earlierLate = $item['status'] === 'late' && $at < $start;
                        if (($inShift || $earlierLate) && !isset($seen[$item['id']])) {
                            $seen[$item['id']] = true;
                            $own[] = $item + [
                                'idEpisodeOfCare' => (int) $episode['idEpisodeOfCare'],
                                'patient' => $name,
                                'bed' => $episode['bed'] ?? null,
                                // RG2 : reprises dans la fenêtre de validation (care_buttons).
                                'allergies' => $episode['allergies'] ?? null,
                            ];
                        }
                    }
                }
            }
            $patients[] = [
                'idEpisodeOfCare' => (int) $episode['idEpisodeOfCare'],
                'name' => $name,
                'bed' => $episode['bed'] ?? null,
                'allergies' => $episode['allergies'] ?? null,
                'deceased' => $episode['deceased'] ?? null,
                'late' => count(array_filter($own, static fn (array $i): bool => $i['status'] === 'late')),
                'planned' => count(array_filter($own, static fn (array $i): bool => $i['status'] === 'planned')),
                'done' => count(array_filter($own, static fn (array $i): bool => $i['status'] === 'done')),
            ];
            $items = [...$items, ...$own];
        }
        usort(
            $items,
            static fn (array $a, array $b): int => [$a['datetime'], $a['patient']] <=> [$b['datetime'], $b['patient']]
        );
        $countBy = static fn (string $status): int => count(
            array_filter($items, static fn (array $i): bool => $i['status'] === $status)
        );

        return [
            'items' => $items,
            'patients' => $patients,
            'counts' => [
                'total' => count($items),
                'late' => $countBy('late'),
                'done' => $countBy('done'),
                'planned' => $countBy('planned'),
            ],
        ];
    }

    /**
     * Éléments de la prescription d'un soin, pour sa carte dans l'agenda : qui a prescrit, dans quel
     * mode, validée ou non, fréquence, période, dose et voie, consignes, arrêt.
     *
     * @param array<string, mixed> $line ServiceRequest ou MedicationRequest sérialisé
     * @param array<string, mixed> $plan ordonnance sérialisée (serializeCarePlan())
     * @return list<array{label: string, value: string}>
     */
    private static function presentPrescription(array $line, array $plan, bool $isMedication): array
    {
        $date = static fn (mixed $d): ?string => $d instanceof DateTimeInterface ? $d->format('d/m/Y H:i') : null;
        $rows = [];
        $add = static function (string $label, mixed $value) use (&$rows): void {
            $value = is_string($value) ? trim($value) : $value;
            if ($value !== null && $value !== '') {
                $rows[] = ['label' => $label, 'value' => (string) $value];
            }
        };
        if ($isMedication) {
            $add('Dose', $line['dose'] ?? null);
            $add('Voie', $line['route'] ?? null);
        }
        $add('Fréquence', CarePlanService::frequencyLabel(
            isset($line['frequencyHours']) ? (int) $line['frequencyHours'] : null
        ));
        $start = $date($line['startDate'] ?? null);
        if ($start !== null) {
            $once = !isset($line['frequencyHours']) || $line['frequencyHours'] === null;
            $add($once ? 'Date' : 'Période', CarePlanService::period($start, $date($line['endDate'] ?? null), $once));
        }
        $add('Consigne', $line['timeConstraint'] ?? null);
        $add('Mode', CarePlanService::MODE_LABELS[$plan['mode'] ?? ''] ?? null);
        $add('Prescrit par', $plan['prescriberName'] ?? $plan['idPrescriber'] ?? null);
        $add('Prescrit le', $date($plan['recordedAt'] ?? null));
        if (array_key_exists('validatedAt', $plan)) {
            $validated = $date($plan['validatedAt']);
            $add('Validation', $validated === null ? 'en attente de validation par un médecin'
                : 'par ' . ($plan['validatorName'] ?? $plan['idValidator'] ?? 'un médecin') . ', le ' . $validated);
        }
        $add('Note', $plan['note'] ?? null);
        $add('Arrêtée le', $date($line['stoppedAt'] ?? null));

        return $rows;
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
        DateTimeImmutable $now,
        array $plan = []
    ): ?array {
        $planned = $appointment['plannedTime'] ?? null;
        if (!$planned instanceof DateTimeInterface || $planned->format('Y-m-d') !== $day->format('Y-m-d')) {
            return null;
        }

        $raw = trim((string) ($appointment['status'] ?? ''));
        // Valeur inconnue en base : transmise telle quelle, le badge l'affiche en neutre plutôt que la cacher.
        $status = self::STATUS_KEYS[$raw] ?? ($raw === '' ? 'planned' : $raw);
        // Ligne arrêtée (AURA-22) : ses soins futurs sont annulés à l'arrêt ; un soin passé encore prévu
        // n'est plus « à faire » ni « en retard » : il s'affiche « Arrêtée » (badge stopped).
        $lineStopped = ($line['stoppedAt'] ?? null) !== null;
        if ($status === 'planned' && $lineStopped) {
            $status = 'stopped';
        } elseif ($status === 'planned' && $planned < $now) {
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
        $comment = trim((string) ($appointment['comment'] ?? ''));

        return [
            'id' => (string) ($appointment['idAppointment'] ?? ''),
            'time' => $planned->format('H:i'),
            'datetime' => $planned->format('Y-m-d\TH:i'),
            'hour' => (int) $planned->format('G'),
            'kind' => $isMedication ? 'MEDICATION' : 'CARE',
            'kindLabel' => $isMedication ? 'Médicament' : 'Soin',
            'label' => $label !== '' ? $label : ($isMedication ? 'Médicament' : 'Soin'),
            'details' => $details === [] ? null : implode(', ', $details),
            'status' => $status,
            'completedAt' => $completion instanceof DateTimeInterface ? $completion->format('H:i') : null,
            'comment' => $comment !== '' ? $comment : null,
            // Qui a validé ou marqué non effectué (historique, AURA-26) ; null tant que personne.
            'performedBy' => isset($appointment['performedBy']) ? (string) $appointment['performedBy'] : null,
            // Auteur de la note (AURA-28) ; null pour une note sans trace (données de démo).
            'noteBy' => isset($appointment['noteBy']) ? (string) $appointment['noteBy'] : null,
            // Carte de la prescription (bouton « + » de chaque soin de l'agenda).
            'prescription' => self::presentPrescription($line, $plan, $isMedication),
            // RG5 / AURA-23 : ordonnance pas encore validée par un médecin (urgence, paramédicale) :
            // ses soins entrent dans l'agenda avec un marqueur visible.
            'notValidated' => $plan !== [] && array_key_exists('validatedAt', $plan) && $plan['validatedAt'] === null,
            // Ordonnance du soin : le badge « Ordonnance non validée » mène à sa validation.
            'idCarePlan' => isset($plan['idCarePlan']) ? (string) $plan['idCarePlan'] : null,
            // Validable : en retard, ou prévu avant la fin de la rotation en cours (un soin fait en avance
            // doit pouvoir être validé ; la nuit, la dose de minuit aussi), pas un soin d'une rotation à venir ;
            // une ligne arrêtée se trace si la dose a été faite avant l'arrêt.
            // Même règle au serveur (AppointmentService::apply()).
            'canValidate' => in_array($status, ['late', 'stopped'], true)
                || ($status === 'planned' && $planned < self::shiftEnd($now)),
        ];
    }
}
