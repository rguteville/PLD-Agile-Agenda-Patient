<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;
use Aura\Service\PatientService;

class PatientController
{
    public function __construct()
    {
        $this->patientService = new PatientService();
    }

    public function list(): void
    {
        // Liste des patients : à implémenter dans AURA-12.1 (lecture via PatientDao).
        View::page('patient/list', [], 'AURA - Patients');
    }

    public function create(): void
    {
        error_log('Appel createPatient');
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname = trim($_POST['lastname'] ?? '');
        $birthDate = trim($_POST['birthDate'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $phonenumber = trim($_POST['phonenumber'] ?? '');
        $email = trim($_POST['email'] ?? 'mail');

        error_log($firstname);
        error_log($lastname);
        error_log($birthDate);
        error_log($gender);
        error_log($phonenumber);
        error_log($email);

        // TODO AURA-10.2 (contrôle serveur des champs obligatoires)
        // et AURA-10.4 (insertion patient + séjour via la couche d'accès AURA-11).
        $patients = $this->patientService->inscrirePatient($_POST);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => $patients]);
    }
}
