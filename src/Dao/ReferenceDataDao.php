<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Practitioner;
use PDO;

/**
 * Référentiels en lecture seule, chargés par database/test.sql : médicaments, types de soin,
 * praticiens, services hospitaliers (admission). Ils remplissent les listes déroulantes
 * (écran B3, admission, médecin référent) et donnent les libellés affichés. Tables Medication,
 * ActivityDefinition, Practitioner, PractitionerRole, HealthcareService.
 *
 * Attention : PHP convertit en entier une clé de tableau faite de chiffres (« 1 » devient 1).
 * Les tableaux indexés par id peuvent donc avoir des clés int ; comparer après un cast (string).
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
     * Médicaments regroupés par DCI (listes « Médicament (DCI) » puis « Forme galénique » de l'écran B3) :
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
     * Allergène du principe actif de chaque médicament qui en a un (Medication.idSubstance) :
     * idMedication => idSubstance. Sert à l'alerte allergie de l'écran B3 (RG2).
     *
     * @return array<string, string>
     */
    public function medicationSubstances(): array
    {
        $rows = $this->pdo->query(
            'SELECT idMedication, idSubstance FROM Medication WHERE idSubstance IS NOT NULL ORDER BY idMedication'
        )->fetchAll(PDO::FETCH_ASSOC);

        $list = [];
        foreach ($rows as $r) {
            $list[(string) $r['idMedication']] = (string) $r['idSubstance'];
        }

        return $list;
    }

    /**
     * Services hospitaliers par ordre alphabétique : idHealthcareService => serviceName
     * (liste « Service hospitalier » de l'admission).
     *
     * @return array<string, string>
     */
    public function healthcareServices(): array
    {
        $rows = $this->pdo->query(
            'SELECT idHealthcareService, serviceName FROM HealthcareService ORDER BY serviceName'
        )->fetchAll(PDO::FETCH_ASSOC);

        $list = [];
        foreach ($rows as $r) {
            $list[(string) $r['idHealthcareService']] = (string) $r['serviceName'];
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
     * Praticiens par nom puis prénom, indexés par idPractitioner, avec leur rôle (LEFT JOIN :
     * un praticien sans rôle est gardé, sans droit de validation).
     *
     * @return array<string, Practitioner>
     */
    public function practitioners(): array
    {
        $rows = $this->pdo->query(
            'SELECT p.idPractitioner, p.lastName, p.firstName, r.title, r.canValidate
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

    /**
     * Médecins (praticiens habilités à valider, RG5) proposés comme référent du séjour :
     * idPractitioner => « Dr Prénom Nom », par nom puis prénom. Même définition du médecin que
     * la validation des prescriptions et la fenêtre d'authentification du dossier (canValidate).
     *
     * @return array<string, string>
     */
    public function doctors(): array
    {
        $list = [];
        foreach ($this->practitioners() as $id => $practitioner) {
            if ($practitioner->canValidate()) {
                $list[(string) $id] = $practitioner->getDoctorName();
            }
        }

        return $list;
    }

    /** Praticien par identifiant, avec son rôle (libellé, droit de validation RG5) ; null s'il n'existe pas. */
    public function practitioner(string $idPractitioner): ?Practitioner
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.idPractitioner, p.lastName, p.firstName, r.title, r.canValidate
             FROM Practitioner p
             LEFT JOIN PractitionerRole r ON r.idPractitionerRole = p.idPractitionerRole
             WHERE p.idPractitioner = :idPractitioner'
        );
        $stmt->execute(['idPractitioner' => $idPractitioner]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToPractitioner($r) : null;
    }

    /**
     * Ligne Practitioner jointe à PractitionerRole (title, canValidate) vers l'entité.
     * Un nom ou prénom NULL en base devient une chaîne vide.
     *
     * @param array<string, mixed> $r
     */
    private function mapToPractitioner(array $r): Practitioner
    {
        return new Practitioner(
            idPractitioner: (string) $r['idPractitioner'],
            lastName: (string) $r['lastName'],
            firstName: (string) $r['firstName'],
            role: $r['title'] !== null ? (string) $r['title'] : null,
            // Sans rôle (LEFT JOIN vide), canValidate est NULL : pas de droit de validation.
            canValidate: (bool) $r['canValidate']
        );
    }
}
