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
        $useName = trim($_POST['useName'] ?? '');
        $birthName = trim($_POST['birthName'] ?? '');

        // TODO
        // $patients = $this->patientService->search($useName, $birthName);
        $patients = [];

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => $patients]);
    }
}
