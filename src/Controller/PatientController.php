<?php

declare(strict_types=1);

namespace Aura\Controller;

class PatientController
{
    public function __construct()
    {
    }

    public function list(): void
    {
        require dirname(__DIR__) . '/View/layout.html';
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

        // TODO
        // $patients = $this->patientService->search($useName, $birthName);
        $patients = [];

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => $patients]);
    }
}
