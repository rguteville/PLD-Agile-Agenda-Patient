<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Médicament du référentiel (ressource HL7 Medication, table Medication), lu par MedicationDao :
 * DCI (inn) et forme galénique (form). Medication.idSubstance (allergène du principe actif) n'est
 * pas porté ici : voir ReferenceDataDao::medicationSubstances().
 */
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
