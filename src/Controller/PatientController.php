<?php
declare(strict_types=1);

namespace Aura\Controller;

class PatientController
{
    public function __construct() {}

    public function list(): void
    {
        require dirname(__DIR__) . '/View/layout.php';
    }
}