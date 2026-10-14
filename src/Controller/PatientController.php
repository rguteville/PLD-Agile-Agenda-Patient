<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\View;

class PatientController
{
    public function __construct()
    {
    }

    public function list(): void
    {
        // Liste des patients : à implémenter dans AURA-12.1 (lecture via PatientDao).
        View::page('patient/list', [], 'AURA - Patients');
    }

    public function create(): void
    {
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname = trim($_POST['lastname'] ?? '');
        $birthDate = trim($_POST['birthDate'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $phonenumber = trim($_POST['phonenumber'] ?? '');
        $email = trim($_POST['email'] ?? '');

        // TODO AURA-10.2 (contrôle serveur des champs obligatoires)
        // et AURA-10.4 (insertion patient + séjour via la couche d'accès AURA-11).
        $patients = [];

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => $patients]);
    }
}
