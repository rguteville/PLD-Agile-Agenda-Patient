<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PatientDao;
use Aura\Domain\Patient;

class PatientService
{
    public function __construct(private PatientDao $patientDao)
    {
    }

    /** @return Patient[] */
    public function getAllPatients(): array
    {
        return $this->patientDao->findAll();
    }

    public function getPatient(int $id): Patient
    {
        $patient = $this->patientDao->findById($id);

        if ($patient === null) {
            throw new \RuntimeException("Utilisateur $id introuvable");
        }

        return $patient;
    }
}
