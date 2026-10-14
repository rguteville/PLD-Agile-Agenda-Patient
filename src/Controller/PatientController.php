<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Dao\PatientDao;
use Aura\Model\Patient;
use DateTimeImmutable;

class PatientController
{
    public function __construct(
        private PatientDao $patientDao
    )
    {
    }

    public function list(): void
    {
        $patients = $this->patientDao->findAll();
        require dirname(__DIR__) . '/View/layout.html';
    }
    public function create(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $patient = new Patient(
                idPatient: null,
                ipp: $_POST['ipp'] ?: null,
                nom: trim($_POST['nom'] ?? ''),
                prenom: trim($_POST['prenom'] ?? ''),
                dateNaissance: new DateTimeImmutable($_POST['date_naissance'] ?? 'now'),
                sexe: $_POST['sexe'] ?? 'M',
                telephone: $_POST['telephone'] ?: null,
                email: $_POST['email'] ?: null
            );

            $this->patientDao->insert($patient);
        }

        require dirname(__DIR__) . '/View/admission_form.html';
    }
}

