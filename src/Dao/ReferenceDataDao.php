<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Practitioner;
use PDO;

/**
 * Référentiels utiles aux prescriptions (Epic 4) : médicaments, types de soin, praticiens.
 * Ils remplissent les listes déroulantes de l'écran B3 et donnent les libellés affichés dans la
 * liste des prescriptions. Tables Medication, ActivityDefinition, Practitioner, PractitionerRole.
 */
class ReferenceDataDao
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Médicaments par ordre alphabétique : idMedication => « DCI (forme) ».
     *
     * @return array<string, string>
     */
    public function medications(): array
    {
        $rows = $this->pdo->query('SELECT idMedication, inn, form FROM Medication ORDER BY inn, form')
            ->fetchAll(PDO::FETCH_ASSOC);

        $list = [];
        foreach ($rows as $r) {
            $form = trim((string) $r['form']);
            $list[(string) $r['idMedication']] = $form !== ''
                ? sprintf('%s (%s)', $r['inn'], $form)
                : (string) $r['inn'];
        }

        return $list;
    }

    /**
     * Médicaments regroupés par DCI (listes « 2. » puis « 3. » de l'écran B3) :
     * DCI => [idMedication => forme]. Exemple : 'Paracétamol' => ['MED01' => 'Solution injectable'].
     *
     * @return array<string, array<string, string>>
     */
    public function medicationsByInn(): array
    {
        $rows = $this->pdo->query('SELECT idMedication, inn, form FROM Medication ORDER BY inn, form')
            ->fetchAll(PDO::FETCH_ASSOC);

        $list = [];
        foreach ($rows as $r) {
            $form = trim((string) $r['form']);
            $list[(string) $r['inn']][(string) $r['idMedication']] = $form !== '' ? $form : (string) $r['inn'];
        }

        return $list;
    }

    /**
     * Types de soin par ordre alphabétique : idActivityDefinition => name.
     *
     * @return array<string, string>
     */
    public function activityDefinitions(): array
    {
        $rows = $this->pdo->query('SELECT idActivityDefinition, name FROM ActivityDefinition ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC);

        $list = [];
        foreach ($rows as $r) {
            $list[(string) $r['idActivityDefinition']] = (string) $r['name'];
        }

        return $list;
    }

    /**
     * Praticiens par nom puis prénom, indexés par idPractitioner.
     *
     * @return array<string, Practitioner>
     */
    public function practitioners(): array
    {
        $rows = $this->pdo->query(
            'SELECT p.idPractitioner, p.lastName, p.firstName, r.description, r.canValidate
             FROM Practitioner p
             LEFT JOIN PractitionerRole r ON r.idPractitionerRole = p.idPractitionerRole
             ORDER BY p.lastName, p.firstName'
        )->fetchAll(PDO::FETCH_ASSOC);

        $list = [];
        foreach ($rows as $r) {
            $practitioner = $this->mapToPractitioner($r);
            $list[$practitioner->getIdPractitioner()] = $practitioner;
        }

        return $list;
    }

    public function practitioner(string $idPractitioner): ?Practitioner
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.idPractitioner, p.lastName, p.firstName, r.description, r.canValidate
             FROM Practitioner p
             LEFT JOIN PractitionerRole r ON r.idPractitionerRole = p.idPractitionerRole
             WHERE p.idPractitioner = :idPractitioner'
        );
        $stmt->execute(['idPractitioner' => $idPractitioner]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToPractitioner($r) : null;
    }

    private function mapToPractitioner(array $r): Practitioner
    {
        return new Practitioner(
            idPractitioner: (string) $r['idPractitioner'],
            lastName: (string) $r['lastName'],
            firstName: (string) $r['firstName'],
            role: $r['description'] !== null ? (string) $r['description'] : null,
            // Sans rôle (LEFT JOIN vide), canValidate est NULL : pas de droit de validation.
            canValidate: (bool) $r['canValidate']
        );
    }
}
