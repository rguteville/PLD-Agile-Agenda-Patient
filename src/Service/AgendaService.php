<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AppointmentDao;
use Aura\Dao\CarePlanDao;
use Aura\Domain\CarePlan;
use Aura\Domain\MedicationRequest;
use Aura\Domain\ServiceRequest;

final class AgendaService
{
    public function __construct(
        private CarePlanDao $carePlanDao,
        private AppointmentDao $appointmentDao,
    ) {
    }

    public function getAgenda(string $id): array
    {
        error_log("Appel Service getAgenda");
        $response = [];
        // On récupère les prescriptions associées au Séjour
        $carePlans = $this->carePlanDao->findByEpisodeOfCare($id);
        // Pour chaque prescription :
        foreach ($carePlans as $carePlan) {
            // D'une part, on récupère leq Soins associés
            $serviceRequests = $this->carePlanDao->findServiceByCarePlan($carePlan->getIdCarePlan());
            foreach ($serviceRequests as $serviceRequest) {
                // Pour chaque soin, on récupère les SoinsPlanifiés
                $serviceAppointments = $this->appointmentDao->findByRequest($serviceRequest->getIdServiceRequest());
            }
            // D'autre part, on récupère les Médicaments associés
            $medicationRequests = $this->carePlanDao->findMedicationByCarePlan($carePlan->getIdCarePlan());
            foreach ($medicationRequests as $medicationRequest) {
                //Pour chaque Médicament, on récupère les SoinsPlanifiés associés
                $medicationAppointments = $this->appointmentDao->
                    findByRequest($medicationRequest->getIdMedicationRequest());
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
        array $services,
        array $serviceAppointments,
        array $medication,
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
            'serviceRequests' => $this->serializeService($services, $serviceAppointments),
            'medicationRequests' => $this->serializeMedication($medication, $medicationAppointments),
        ];
    }

    public function serializeService(array $services, array $appointments): ?array
    {
        foreach ($services as $service) {
            $res[] = [
                'idServiceRequest' => $service->getIdServiceRequest(),
                'idCarePlan' => $service->getIdCarePlan(),
                'idActivityDefinition' => $service->getIdActivityDefinition(),
                'startDate' => $service->getStartDate(),
                'endDate' => $service->getEndDate(),
                'frequencyHours' => $service->getFrequencyHours(),
                'timeConstraint' => $service->getTimeConstraint(),
                'idStoppedBy' => $service->getIdStoppedBy(),
                'stoppedAt' => $service->getStoppedAt(),
                'appointments' => $this->serializeAppointment($appointments),
            ];
        }
        return $res;
    }

    public function serializeMedication(array $medications, array $appointments): ?array
    {
        foreach ($medications as $medication) {
            $res[] = [
                'idMedicationRequest' => $medication->getIdMedicationRequest(),
                'idCarePlan' => $medication->getIdCarePlan(),
                'idActivityDefinition' => $medication->getIdActivityDefinition(),
                'dose' => $medication->getDose(),
                'route' => $medication->getRoute(),
                'startDate' => $medication->getStartDate(),
                'endDate' => $medication->getEndDate(),
                'frequencyHours' => $medication->getFrequencyHours(),
                'timeConstraint' => $medication->getTimeConstraint(),
                'idStoppedBy' => $medication->getIdStoppedBy(),
                'stoppedAt' => $medication->getStoppedAt(),
                'appointments' => $this->serializeAppointment($appointments),
            ];
        }
        return $res;
    }

    public function serializeAppointment(array $appointments): ?array
    {

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
