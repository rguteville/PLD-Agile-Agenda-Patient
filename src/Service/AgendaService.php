<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AppointmentDao;
use Aura\Dao\CarePlanDao;

final class AgendaService
{
    public function __construct(
        private CarePlanDao $carePlanDao,
        private AppointmentDao $appointmentDao,
    )
    {
    }

    public function getAgenda(string $id) : array
    {
        error_log("Appel Service getAgenda");
        $response = [];
        // On récupère les prescriptions associées au Séjour
        $carePlans = $this->carePlanDao->findByEpisodeOfCare($id);
        // Pour chaque prescription :
        foreach ($carePlans as $carePlan) {
            // D'une part, on récupère leq Soins associés
            $serviceRequests = $this->carePlanDao->findServiceByCarePlan($carePlan->getIdCarePlan());
            $service = $serviceRequests;
            foreach ($serviceRequests as $serviceRequest) {
                // Pour chaque soin, on récupère les SoinsPlanifiés
                $serviceAppointments = $this->appointmentDao->findByRequest($serviceRequest->getIdServiceRequest());
                $service["appointments"] = $serviceAppointments;
            }
            // D'autre part, on récupère les Médicaments associés
            $medicationRequests = $this->carePlanDao->findMedicationByCarePlan($carePlan->getIdCarePlan());
            $medic = $medicationRequests;
            foreach ($medicationRequests as $medicationRequest) {
                //Pour chaque Médicament, on récupère les SoinsPlanifiés associés
                $medicationAppointments = $this->appointmentDao->findByRequest($medicationRequest->getIdMedicationRequest());
                $medic["appointments"] = $medicationAppointments;
            }
            // Assemblage de la réponse HTTP
            var_dump($carePlan->getIdCarePlan());
            $response[] = ['carePlan' => $carePlan->getIdCarePlan(), 'serviceRequests' => $service, 'medicationRequests'=> $medic];
        }
        return $response;
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