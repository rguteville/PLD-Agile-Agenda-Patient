<?php

declare(strict_types=1);

namespace Aura\Domain;

class Medication
{
    public function __construct(
        private ?string $idMedication,
        private ?string $inn, // DCI
        private ?string $form,
    ) {
    }

    public function getIdMedication(): ?string
    {
        return $this->idMedication;
    }
    public function getInn(): ?string
    {
        return $this->inn;
    }
    public function getForm(): ?string
    {
        return $this->form;
    }
}
