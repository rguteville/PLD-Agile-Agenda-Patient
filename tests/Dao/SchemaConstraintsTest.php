<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contraintes de database/schema.sql vérifiées par la base elle-même : chaque instruction invalide
 * doit être refusée par MariaDB, avec le bon code d'erreur ET la bonne contrainte (un refus pour une
 * autre raison, table inexistante par exemple, ne compte pas). Quelques instructions valides de
 * contrôle vérifient que les contraintes ne refusent pas trop.
 *
 * Cas repris de database/test_contraintes.sql, Didac Espin, branche tests/AURA-16.2 (unicité et
 * bornes de l'IPP, unicité du numéro de séjour, clés étrangères du séjour, unicité et clés étrangères
 * des allergies, rôle du praticien, modes de prescription), adaptés au schéma actuel puis complétés
 * pour couvrir chaque contrainte : UNIQUE, CHECK, NOT NULL, FOREIGN KEY et RESTRICT à la suppression
 * ou à la modification d'une ligne référencée (suppression logique, règle n° 8).
 *
 * Codes MariaDB (tous en SQLSTATE 23000) : 1062 doublon, 1048 colonne NULL, 1452 parent absent,
 * 1451 parent encore référencé, 4025 CHECK. Les données de database/test.sql servent de parents ;
 * tout se passe dans une transaction annulée en tearDown. Ignoré sans DB_HOST/DB_NAME.
 */
final class SchemaConstraintsTest extends TestCase
{
    private const DUPLICATE = 1062;
    private const NOT_NULL = 1048;
    private const NO_PARENT = 1452;
    private const STILL_REFERENCED = 1451;
    private const CHECK = 4025;

    /** Patient et séjour « ZZ » réutilisés par plusieurs cas. */
    private const ZZ_PATIENT = "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP1', NULL, 'Zzcontrainte')";
    private const ZZ_EPISODE = "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService,
        episodeNumber, admissionDate) VALUES ('ZZE1', 'ZZP1', 'SRV01', 'ZZ-000001', '2030-01-01 08:00:00')";

    private PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Instructions invalides : [préparation, instruction refusée, code MariaDB, contrainte attendue
     * dans le message (insensible à la casse : MariaDB sous macOS met les noms de tables en minuscules)].
     *
     * @return array<string, array{list<string>, string, int, string}>
     */
    public static function invalidStatements(): array
    {
        return [
            // ----- Patient (Didac : unicité et bornes de l'IPP) -----
            'Patient : IPP en double (Didac)' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP1', 555555, 'Zzmartin'), ('ZZP2',
                    555555, 'Zzdupont')",
                self::DUPLICATE,
                "for key 'ipp'",
            ],
            'Patient : IPP déjà attribué à un patient de démo' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP1', 100000001, 'Zzdoublon')",
                self::DUPLICATE,
                "for key 'ipp'",
            ],
            'Patient : IPP sous la borne basse (Didac)' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP3', 10000, 'Zzjullien')",
                self::CHECK,
                'patient.ipp',
            ],
            'Patient : IPP juste sous la borne basse' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP3', 99999, 'Zzborne')",
                self::CHECK,
                'patient.ipp',
            ],
            'Patient : IPP au-dessus de la borne haute (Didac)' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP4', 1000000000, 'Zztoubon')",
                self::CHECK,
                'patient.ipp',
            ],
            'Patient : IPP négatif' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP4', -100000, 'Zznegatif')",
                self::CHECK,
                'patient.ipp',
            ],
            'Patient : identifiant en double' => [
                [],
                "INSERT INTO Patient (idPatient, lastName) VALUES ('1', 'Zzdoublon')",
                self::DUPLICATE,
                "for key 'PRIMARY'",
            ],
            'Patient : nom obligatoire' => [
                [],
                "INSERT INTO Patient (idPatient, lastName) VALUES ('ZZP5', NULL)",
                self::NOT_NULL,
                "'lastName'",
            ],
            'Patient : IPP modifié vers une valeur hors bornes' => [
                [],
                "UPDATE Patient SET ipp = 12 WHERE idPatient = '2'",
                self::CHECK,
                'patient.ipp',
            ],

            // ----- EpisodeOfCare (Didac : numéro de séjour unique, clés étrangères) -----
            'Séjour : numéro de séjour en double (Didac)' => [
                [],
                "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService, episodeNumber,
                    admissionDate)
                 VALUES ('ZZE1', '1', 'SRV01', 'ZZ-100000', '2026-10-10 08:15:00'),
                        ('ZZE2', '1', 'SRV01', 'ZZ-100000', '2026-10-11 08:15:00')",
                self::DUPLICATE,
                'episodeNumber',
            ],
            'Séjour : numéro d\'un séjour de démo réutilisé' => [
                [],
                "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService, episodeNumber,
                    admissionDate)
                 VALUES ('ZZE1', '1', 'SRV01', '2026-000101', '2026-10-10 08:15:00')",
                self::DUPLICATE,
                'episodeNumber',
            ],
            'Séjour : patient inexistant (Didac)' => [
                [],
                "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService, episodeNumber,
                    admissionDate)
                 VALUES ('ZZE3', 'ZZ_INCONNU', 'SRV01', 'ZZ-100000', '2026-10-10 08:15:00')",
                self::NO_PARENT,
                'fk_patient_episode',
            ],
            'Séjour : service inexistant (Didac)' => [
                [],
                "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService, episodeNumber,
                    admissionDate)
                 VALUES ('ZZE4', '1', 'ZZ_INCONNU', 'ZZ-100000', '2026-10-10 08:15:00')",
                self::NO_PARENT,
                'fk_healthcareservice_episode',
            ],
            'Séjour : médecin référent inexistant' => [
                [],
                "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService, episodeNumber,
                    admissionDate,
                    idReferringPractitioner) VALUES ('ZZE5', '1', 'SRV01', 'ZZ-100000', '2026-10-10 08:15:00',
                    'ZZ_INCONNU')",
                self::NO_PARENT,
                'fk_referringpractitioner_episode',
            ],
            'Séjour : numéro obligatoire' => [
                [],
                "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, episodeNumber, admissionDate)
                 VALUES ('ZZE6', '1', NULL, '2026-10-10 08:15:00')",
                self::NOT_NULL,
                "'episodeNumber'",
            ],
            'Séjour : date d\'admission obligatoire' => [
                [],
                "INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, episodeNumber, admissionDate)
                 VALUES ('ZZE7', '1', 'ZZ-100001', NULL)",
                self::NOT_NULL,
                "'admissionDate'",
            ],

            // ----- AllergyIntolerance (Didac : unicité patient/substance, clés étrangères) -----
            'Allergie : même substance deux fois pour un patient (Didac)' => [
                [],
                "INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance)
                 VALUES ('ZZA1', '1', 'ALG03'), ('ZZA2', '1', 'ALG03')",
                self::DUPLICATE,
                'uq_substance_patient',
            ],
            'Allergie : déjà connue dans les données de démo' => [
                [],
                "INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance) VALUES ('ZZA1', '1', 'ALG01')",
                self::DUPLICATE,
                'uq_substance_patient',
            ],
            'Allergie : patient inexistant (Didac)' => [
                [],
                "INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance) VALUES ('ZZA3', 'ZZ_INCONNU',
                    'ALG01')",
                self::NO_PARENT,
                'fk_patient_allergy',
            ],
            'Allergie : substance inexistante (Didac)' => [
                [],
                "INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance) VALUES ('ZZA4', '1', 'ZZ_INCONNU')",
                self::NO_PARENT,
                'fk_substance_allergy',
            ],
            'Allergie : patient obligatoire' => [
                [],
                "INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance) VALUES ('ZZA5', NULL, 'ALG01')",
                self::NOT_NULL,
                "'idPatient'",
            ],
            'Allergie : substance obligatoire' => [
                [],
                "INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance) VALUES ('ZZA6', '1', NULL)",
                self::NOT_NULL,
                "'idSubstance'",
            ],

            // ----- Substance, Medication -----
            'Substance : nom en double' => [
                [],
                "INSERT INTO Substance (idSubstance, name) VALUES ('ZZS1', 'Latex')",
                self::DUPLICATE,
                "for key 'name'",
            ],
            'Substance : nom obligatoire' => [
                [],
                "INSERT INTO Substance (idSubstance, name) VALUES ('ZZS2', NULL)",
                self::NOT_NULL,
                "'name'",
            ],
            'Médicament : même DCI et même forme' => [
                [],
                "INSERT INTO Medication (idMedication, inn, form) VALUES ('ZZM1', 'Paracétamol',
                    'Solution injectable')",
                self::DUPLICATE,
                'uq_inn_form',
            ],
            'Médicament : allergène inexistant' => [
                [],
                "INSERT INTO Medication (idMedication, inn, form, idSubstance) VALUES ('ZZM2', 'Zzdci', 'Zzforme',
                    'ZZ_INCONNU')",
                self::NO_PARENT,
                'fk_substance_medication',
            ],

            // ----- PractitionerRole, Practitioner (Didac : rôle inexistant) -----
            'Praticien : rôle inexistant (Didac)' => [
                [],
                "INSERT INTO Practitioner (idPractitioner, idPractitionerRole) VALUES ('ZZR1', 'ZZ_INCONNU')",
                self::NO_PARENT,
                'fk_practitionerrole_practitioner',
            ],
            'Rôle : droit de validation obligatoire' => [
                [],
                "INSERT INTO PractitionerRole (idPractitionerRole, title, canValidate) VALUES ('ZZQ1', 'Zzrole', NULL)",
                self::NOT_NULL,
                "'canValidate'",
            ],

            // ----- Pathology, PlanDefinition, PlanDefinitionAction -----
            'Pathologie : libellé en double' => [
                [],
                "INSERT INTO Pathology (idPathology, label) VALUES ('ZZPT1', 'AVC ischémique')",
                self::DUPLICATE,
                "for key 'label'",
            ],
            'Pathologie : libellé obligatoire' => [
                [],
                "INSERT INTO Pathology (idPathology, label) VALUES ('ZZPT2', NULL)",
                self::NOT_NULL,
                "'label'",
            ],
            'Protocole : titre obligatoire' => [
                [],
                "INSERT INTO PlanDefinition (idPlanDefinition, title) VALUES ('ZZPD1', NULL)",
                self::NOT_NULL,
                "'title'",
            ],
            'Protocole : pathologie inexistante' => [
                [],
                "INSERT INTO PlanDefinition (idPlanDefinition, title, idPathology) VALUES ('ZZPD2', 'Zz',
                    'ZZ_INCONNU')",
                self::NO_PARENT,
                'fk_pathology_plandefinition',
            ],
            'Ligne de protocole : nature inconnue' => [
                [],
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition)
                 VALUES ('ZZPA1', 'PLD01', 'ZZ', 'TYP02')",
                self::CHECK,
                // Refusée par chk_plandefinitionaction_kind et par chk_plandefinitionaction_medication (aucune
                // des deux natures) : MariaDB nomme la première qu'il évalue, l'ordre n'est pas garanti.
                'chk_plandefinitionaction_',
            ],
            'Ligne de protocole : médicament sans dose' => [
                [],
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition,
                    idMedication, dose, route) VALUES ('ZZPA2', 'PLD01', 'MEDICATION', 'TYP01', 'MED01', NULL, 'IV')",
                self::CHECK,
                'chk_plandefinitionaction_medication',
            ],
            'Ligne de protocole : soin avec un médicament' => [
                [],
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition,
                    idMedication) VALUES ('ZZPA3', 'PLD01', 'CARE', 'TYP02', 'MED01')",
                self::CHECK,
                'chk_plandefinitionaction_medication',
            ],
            'Ligne de protocole : décalage négatif' => [
                [],
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition,
                    startOffsetHours) VALUES ('ZZPA4', 'PLD01', 'CARE', 'TYP02', -1)",
                self::CHECK,
                'chk_plandefinitionaction_offset',
            ],
            'Ligne de protocole : fréquence nulle' => [
                [],
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition,
                    frequencyHours) VALUES ('ZZPA5', 'PLD01', 'CARE', 'TYP02', 0)",
                self::CHECK,
                'chk_plandefinitionaction_frequence',
            ],
            'Ligne de protocole : durée sans fréquence (RG3)' => [
                [],
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition,
                    durationHours, frequencyHours) VALUES ('ZZPA6', 'PLD01', 'CARE', 'TYP02', 24, NULL)",
                self::CHECK,
                'chk_plandefinitionaction_duree',
            ],
            'Ligne de protocole : durée nulle' => [
                [],
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition,
                    durationHours, frequencyHours) VALUES ('ZZPA7', 'PLD01', 'CARE', 'TYP02', 0, 4)",
                self::CHECK,
                'chk_plandefinitionaction_duree',
            ],
            'Ligne de protocole : protocole inexistant' => [
                [],
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition)
                 VALUES ('ZZPA8', 'ZZ_INCONNU', 'CARE', 'TYP02')",
                self::NO_PARENT,
                'fk_plandefinition_plandefinitionaction',
            ],
            'Ligne de protocole : type de soin inexistant' => [
                [],
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition)
                 VALUES ('ZZPA9', 'PLD01', 'CARE', 'ZZ_INCONNU')",
                self::NO_PARENT,
                'fk_activitydefinition_plandefinitionaction',
            ],
            'Ligne de protocole : médicament inexistant' => [
                [],
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition,
                    idMedication, dose, route) VALUES ('ZZPA10', 'PLD01', 'MEDICATION', 'TYP01', 'ZZ_INCONNU',
                    '1 g', 'IV')",
                self::NO_PARENT,
                'fk_medication_plandefinitionaction',
            ],

            // ----- CarePlan (Didac : modes ; noms anglais depuis AURA-16) -----
            'Ordonnance : ancien mode « MEDICALE » (Didac, renommé MEDICAL)' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode) VALUES ('ZZC1', '1',
                    'PRA01', 'MEDICALE')",
                self::CHECK,
                'chk_careplan_mode',
            ],
            'Ordonnance : ancien mode « PROTOCOLE » (Didac, retiré)' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode) VALUES ('ZZC2', '1',
                    'PRA01', 'PROTOCOLE')",
                self::CHECK,
                'chk_careplan_mode',
            ],
            'Ordonnance : valideur sans date de validation (RG5)' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idValidator, validatedAt)
                 VALUES ('ZZC4', '1', 'PRA03', 'MEDICAL', 'PRA01', NULL)",
                self::CHECK,
                'chk_careplan_validation',
            ],
            'Ordonnance : date de validation sans valideur (RG5)' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idValidator, validatedAt)
                 VALUES ('ZZC5', '1', 'PRA03', 'MEDICAL', NULL, '2030-01-01 08:00:00')",
                self::CHECK,
                'chk_careplan_validation',
            ],
            'Ordonnance : séjour inexistant' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode) VALUES ('ZZC6',
                    'ZZ_INCONNU', 'PRA01', 'MEDICAL')",
                self::NO_PARENT,
                'fk_episodeofcare_careplan',
            ],
            'Ordonnance : prescripteur inexistant' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode) VALUES ('ZZC7', '1',
                    'ZZ_INCONNU', 'MEDICAL')",
                self::NO_PARENT,
                'fk_prescriber_careplan',
            ],
            'Ordonnance : valideur inexistant' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idValidator, validatedAt)
                 VALUES ('ZZC8', '1', 'PRA03', 'MEDICAL', 'ZZ_INCONNU', '2030-01-01 08:00:00')",
                self::NO_PARENT,
                'fk_validator_careplan',
            ],
            'Ordonnance : protocole inexistant' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idPlanDefinition)
                 VALUES ('ZZC9', '1', 'PRA01', 'MEDICAL', 'ZZ_INCONNU')",
                self::NO_PARENT,
                'fk_plandefinition_careplan',
            ],
            'Ordonnance : prescripteur obligatoire' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode) VALUES ('ZZC10', '1', NULL,
                    'MEDICAL')",
                self::NOT_NULL,
                "'idPrescriber'",
            ],
            'Ordonnance : mode obligatoire' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode) VALUES ('ZZC11', '1',
                    'PRA01', NULL)",
                self::NOT_NULL,
                "'mode'",
            ],

            // ----- ServiceRequest -----
            'Ligne de soin : fréquence nulle' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate,
                    frequencyHours)
                 VALUES ('ZZSR1', '1', 'TYP02', '2030-01-01 08:00:00', 0)",
                self::CHECK,
                'chk_servicerequest_frequence',
            ],
            'Ligne de soin : fin avant le début' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, endDate)
                 VALUES ('ZZSR2', '1', 'TYP02', '2030-01-02 08:00:00', '2030-01-01 08:00:00')",
                self::CHECK,
                'chk_servicerequest_dates',
            ],
            'Ligne de soin : arrêtée sans auteur (RG10)' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, stoppedAt)
                 VALUES ('ZZSR3', '1', 'TYP02', '2030-01-01 08:00:00', '2030-01-01 09:00:00')",
                self::CHECK,
                'chk_servicerequest_arret',
            ],
            'Ligne de soin : auteur d\'arrêt sans date' => [
                [],
                "UPDATE ServiceRequest SET idStoppedBy = 'PRA01' WHERE idServiceRequest = '1'",
                self::CHECK,
                'chk_servicerequest_arret',
            ],
            'Ligne de soin : ordonnance inexistante' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate)
                 VALUES ('ZZSR4', 'ZZ_INCONNU', 'TYP02', '2030-01-01 08:00:00')",
                self::NO_PARENT,
                'fk_careplan_servicerequest',
            ],
            'Ligne de soin : type de soin inexistant (RG6)' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate)
                 VALUES ('ZZSR5', '1', 'ZZ_INCONNU', '2030-01-01 08:00:00')",
                self::NO_PARENT,
                'fk_activitydefinition_servicerequest',
            ],
            'Ligne de soin : arrêtée par un praticien inexistant' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate,
                    idStoppedBy, stoppedAt)
                 VALUES ('ZZSR6', '1', 'TYP02', '2030-01-01 08:00:00', 'ZZ_INCONNU', '2030-01-01 09:00:00')",
                self::NO_PARENT,
                'fk_stoppedby_servicerequest',
            ],
            'Ligne de soin : date de début obligatoire' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate)
                 VALUES ('ZZSR7', '1', 'TYP02', NULL)",
                self::NOT_NULL,
                "'startDate'",
            ],

            // ----- MedicationRequest -----
            'Ligne de médicament : fréquence négative' => [
                [],
                "INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition,
                    idMedication, dose, route,
                    startDate, frequencyHours) VALUES ('ZZMR1', '1', 'TYP01', 'MED01', '1 g', 'IV',
                    '2030-01-01 08:00:00', -6)",
                self::CHECK,
                'chk_medicationrequest_frequence',
            ],
            'Ligne de médicament : fin avant le début' => [
                [],
                "INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition,
                    idMedication, dose, route,
                    startDate, endDate) VALUES ('ZZMR2', '1', 'TYP01', 'MED01', '1 g', 'IV', '2030-01-02 08:00:00',
                    '2030-01-01 08:00:00')",
                self::CHECK,
                'chk_medicationrequest_dates',
            ],
            'Ligne de médicament : auteur d\'arrêt sans date' => [
                [],
                "INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition,
                    idMedication, dose, route,
                    startDate, idStoppedBy) VALUES ('ZZMR3', '1', 'TYP01', 'MED01', '1 g', 'IV',
                    '2030-01-01 08:00:00', 'PRA01')",
                self::CHECK,
                'chk_medicationrequest_arret',
            ],
            'Ligne de médicament : médicament inexistant' => [
                [],
                "INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition,
                    idMedication, dose, route,
                    startDate) VALUES ('ZZMR4', '1', 'TYP01', 'ZZ_INCONNU', '1 g', 'IV', '2030-01-01 08:00:00')",
                self::NO_PARENT,
                'fk_medication_medicationrequest',
            ],
            'Ligne de médicament : ordonnance inexistante' => [
                [],
                "INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition,
                    idMedication, dose, route,
                    startDate) VALUES ('ZZMR5', 'ZZ_INCONNU', 'TYP01', 'MED01', '1 g', 'IV', '2030-01-01 08:00:00')",
                self::NO_PARENT,
                'fk_careplan_medicationrequest',
            ],
            'Ligne de médicament : type de soin inexistant' => [
                [],
                "INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition,
                    idMedication, dose, route,
                    startDate) VALUES ('ZZMR6', '1', 'ZZ_INCONNU', 'MED01', '1 g', 'IV', '2030-01-01 08:00:00')",
                self::NO_PARENT,
                'fk_activitydefinition_medicationrequest',
            ],
            'Ligne de médicament : arrêtée par un praticien inexistant' => [
                [],
                "INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition,
                    idMedication, dose, route,
                    startDate, idStoppedBy, stoppedAt)
                 VALUES ('ZZMR7', '1', 'TYP01', 'MED01', '1 g', 'IV', '2030-01-01 08:00:00', 'ZZ_INCONNU',
                 '2030-01-01 09:00:00')",
                self::NO_PARENT,
                'fk_stoppedby_medicationrequest',
            ],
            'Ligne de médicament : dose obligatoire' => [
                [],
                "INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition,
                    idMedication, dose, route,
                    startDate) VALUES ('ZZMR8', '1', 'TYP01', 'MED01', NULL, 'IV', '2030-01-01 08:00:00')",
                self::NOT_NULL,
                "'dose'",
            ],
            'Ligne de médicament : voie obligatoire' => [
                [],
                "INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition,
                    idMedication, dose, route,
                    startDate) VALUES ('ZZMR9', '1', 'TYP01', 'MED01', '1 g', NULL, '2030-01-01 08:00:00')",
                self::NOT_NULL,
                "'route'",
            ],
            'Ligne de médicament : médicament obligatoire' => [
                [],
                "INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition,
                    idMedication, dose, route,
                    startDate) VALUES ('ZZMR10', '1', 'TYP01', NULL, '1 g', 'IV', '2030-01-01 08:00:00')",
                self::NOT_NULL,
                "'idMedication'",
            ],

            // ----- Appointment -----
            'Soin planifié : rattaché à aucune ligne' => [
                [],
                "INSERT INTO Appointment (idAppointment, idServiceRequest, idMedicationRequest, plannedTime)
                 VALUES ('ZZAP1', NULL, NULL, '2030-01-01 08:00:00')",
                self::CHECK,
                'chk_appointment_ligne',
            ],
            'Soin planifié : rattaché aux deux lignes à la fois' => [
                [],
                "INSERT INTO Appointment (idAppointment, idServiceRequest, idMedicationRequest, plannedTime)
                 VALUES ('ZZAP2', '1', '1', '2030-01-01 08:00:00')",
                self::CHECK,
                'chk_appointment_ligne',
            ],
            'Soin planifié : deux fois la même heure sur une ligne de soin' => [
                [],
                "INSERT INTO Appointment (idAppointment, idServiceRequest, plannedTime) VALUES ('ZZAP3', '4',
                    '2026-10-14 08:00:00')",
                self::DUPLICATE,
                'uq_appointment_service_time',
            ],
            'Soin planifié : deux fois la même heure sur une ligne de médicament' => [
                [],
                "INSERT INTO Appointment (idAppointment, idMedicationRequest, plannedTime) VALUES ('ZZAP4', '1',
                    '2026-10-14 06:00:00')",
                self::DUPLICATE,
                'uq_appointment_medication_time',
            ],
            'Soin planifié : ligne de soin inexistante' => [
                [],
                "INSERT INTO Appointment (idAppointment, idServiceRequest, plannedTime) VALUES ('ZZAP5',
                    'ZZ_INCONNU', '2030-01-01 08:00:00')",
                self::NO_PARENT,
                'fk_servicerequest_appointment',
            ],
            'Soin planifié : ligne de médicament inexistante' => [
                [],
                "INSERT INTO Appointment (idAppointment, idMedicationRequest, plannedTime) VALUES ('ZZAP6',
                    'ZZ_INCONNU', '2030-01-01 08:00:00')",
                self::NO_PARENT,
                'fk_medicationrequest_appointment',
            ],
            'Soin planifié : heure prévue obligatoire' => [
                [],
                "INSERT INTO Appointment (idAppointment, idServiceRequest, plannedTime) VALUES ('ZZAP7', '1', NULL)",
                self::NOT_NULL,
                "'plannedTime'",
            ],

            // ----- AuditEvent -----
            'Historique : auteur obligatoire' => [
                [],
                "INSERT INTO AuditEvent (entity, entityId, field, author) VALUES ('ZZ', 'ZZ', 'zz', NULL)",
                self::NOT_NULL,
                "'author'",
            ],
            'Historique : champ obligatoire' => [
                [],
                "INSERT INTO AuditEvent (entity, entityId, field, author) VALUES ('ZZ', 'ZZ', NULL, 'Zz')",
                self::NOT_NULL,
                "'field'",
            ],

            // ----- CareTeam, CareTeamMember, CareTeamPatient -----
            'Équipe : vacation inconnue' => [
                [],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2030-01-01',
                    'SOIR', 'Zzéquipe')",
                self::CHECK,
                'chk_careteam_shift',
            ],
            'Équipe : même nom parmi les équipes actives de la vacation' => [
                [],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2026-10-14',
                    'MATIN', 'Équipe A')",
                self::DUPLICATE,
                'uq_careteam_name',
            ],
            'Équipe : service inexistant' => [
                [],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('ZZ_INCONNU',
                    '2030-01-01', 'MATIN', 'Zz')",
                self::NO_PARENT,
                'fk_healthcareservice_careteam',
            ],
            'Équipe : nom obligatoire' => [
                [],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2030-01-01',
                    'MATIN', NULL)",
                self::NOT_NULL,
                "'name'",
            ],
            'Membre d\'équipe : en double' => [
                [],
                "INSERT INTO CareTeamMember (idCareTeam, idPractitioner) VALUES (1, 'PRA04')",
                self::DUPLICATE,
                "for key 'PRIMARY'",
            ],
            'Membre d\'équipe : praticien inexistant' => [
                [],
                "INSERT INTO CareTeamMember (idCareTeam, idPractitioner) VALUES (1, 'ZZ_INCONNU')",
                self::NO_PARENT,
                'fk_practitioner_member',
            ],
            'Membre d\'équipe : équipe inexistante' => [
                [],
                "INSERT INTO CareTeamMember (idCareTeam, idPractitioner) VALUES (999999, 'PRA01')",
                self::NO_PARENT,
                'fk_careteam_member',
            ],
            'Patient d\'équipe : en double' => [
                [],
                "INSERT INTO CareTeamPatient (idCareTeam, idEpisodeOfCare) VALUES (1, '1')",
                self::DUPLICATE,
                "for key 'PRIMARY'",
            ],
            'Patient d\'équipe : séjour inexistant' => [
                [],
                "INSERT INTO CareTeamPatient (idCareTeam, idEpisodeOfCare) VALUES (1, 'ZZ_INCONNU')",
                self::NO_PARENT,
                'fk_episode_careteam',
            ],
            'Patient d\'équipe : équipe inexistante' => [
                [],
                "INSERT INTO CareTeamPatient (idCareTeam, idEpisodeOfCare) VALUES (999999, '1')",
                self::NO_PARENT,
                'fk_careteam_patient',
            ],

            // ----- RESTRICT : une ligne référencée ne se supprime pas (ni ne change d'identifiant) -----
            'Suppression : patient qui a un séjour' => [
                [self::ZZ_PATIENT, self::ZZ_EPISODE],
                "DELETE FROM Patient WHERE idPatient = 'ZZP1'",
                self::STILL_REFERENCED,
                'fk_patient_episode',
            ],
            'Suppression : patient qui a une allergie' => [
                [self::ZZ_PATIENT, "INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance)
                VALUES ('ZZA1', 'ZZP1', 'ALG01')"],
                "DELETE FROM Patient WHERE idPatient = 'ZZP1'",
                self::STILL_REFERENCED,
                'fk_patient_allergy',
            ],
            'Modification : identifiant d\'un patient qui a un séjour' => [
                [],
                "UPDATE Patient SET idPatient = 'ZZP9' WHERE idPatient = '4'",
                self::STILL_REFERENCED,
                'fk_patient_episode',
            ],
            'Suppression : séjour qui a une ordonnance' => [
                [],
                "DELETE FROM EpisodeOfCare WHERE idEpisodeOfCare = '5'",
                self::STILL_REFERENCED,
                'fk_episodeofcare_careplan',
            ],
            'Suppression : séjour attribué à une équipe' => [
                [],
                "DELETE FROM EpisodeOfCare WHERE idEpisodeOfCare = '8'",
                self::STILL_REFERENCED,
                'fk_episode_careteam',
            ],
            'Suppression : ordonnance qui a une ligne de soin' => [
                [],
                "DELETE FROM CarePlan WHERE idCarePlan = '4'",
                self::STILL_REFERENCED,
                'fk_careplan_servicerequest',
            ],
            'Suppression : ordonnance qui a une ligne de médicament' => [
                [],
                "DELETE FROM CarePlan WHERE idCarePlan = '8'",
                self::STILL_REFERENCED,
                'fk_careplan_medicationrequest',
            ],
            'Suppression : ligne de soin qui a des soins planifiés' => [
                [],
                "DELETE FROM ServiceRequest WHERE idServiceRequest = '4'",
                self::STILL_REFERENCED,
                'fk_servicerequest_appointment',
            ],
            'Suppression : ligne de médicament qui a des soins planifiés' => [
                [],
                "DELETE FROM MedicationRequest WHERE idMedicationRequest = '6'",
                self::STILL_REFERENCED,
                'fk_medicationrequest_appointment',
            ],
            'Suppression : substance allergène d\'un patient' => [
                [],
                "DELETE FROM Substance WHERE idSubstance = 'ALG03'",
                self::STILL_REFERENCED,
                'fk_substance_allergy',
            ],
            'Suppression : médicament prescrit' => [
                [],
                "DELETE FROM Medication WHERE idMedication = 'MED04'",
                self::STILL_REFERENCED,
                'fk_medication_medicationrequest',
            ],
            'Suppression : médicament d\'un protocole' => [
                [],
                "DELETE FROM Medication WHERE idMedication = 'MED08'",
                self::STILL_REFERENCED,
                'fk_medication_plandefinitionaction',
            ],
            'Suppression : type de soin prescrit' => [
                [],
                "DELETE FROM ActivityDefinition WHERE idActivityDefinition = 'TYP04'",
                self::STILL_REFERENCED,
                'fk_activitydefinition_servicerequest',
            ],
            'Suppression : rôle porté par un praticien' => [
                [],
                "DELETE FROM PractitionerRole WHERE idPractitionerRole = 'QUA02'",
                self::STILL_REFERENCED,
                'fk_practitionerrole_practitioner',
            ],
            'Suppression : praticien membre d\'une équipe' => [
                [],
                "DELETE FROM Practitioner WHERE idPractitioner = 'PRA06'",
                self::STILL_REFERENCED,
                'fk_practitioner_member',
            ],
            'Suppression : service qui a des séjours' => [
                [],
                "DELETE FROM HealthcareService WHERE idHealthcareService = 'SRV03'",
                self::STILL_REFERENCED,
                'fk_healthcareservice_episode',
            ],
            'Suppression : protocole qui a des lignes' => [
                [],
                "DELETE FROM PlanDefinition WHERE idPlanDefinition = 'PLD03'",
                self::STILL_REFERENCED,
                'fk_plandefinition_plandefinitionaction',
            ],
            'Suppression : pathologie d\'un protocole' => [
                [],
                "DELETE FROM Pathology WHERE idPathology = 'PTH03'",
                self::STILL_REFERENCED,
                'fk_pathology_plandefinition',
            ],
            'Suppression : équipe qui a des membres' => [
                [
                    "INSERT INTO CareTeam (idCareTeam, idHealthcareService, shiftDate, shift, name)
                     VALUES (999901, 'SRV01', '2030-01-01', 'NUIT', 'Zzéquipe')",
                    "INSERT INTO CareTeamMember (idCareTeam, idPractitioner) VALUES (999901, 'PRA04')",
                ],
                'DELETE FROM CareTeam WHERE idCareTeam = 999901',
                self::STILL_REFERENCED,
                'fk_careteam_member',
            ],
        ];
    }

    /** @param list<string> $setup */
    #[DataProvider('invalidStatements')]
    public function testInvalidStatementIsRejectedByTheRightConstraint(
        array $setup,
        string $sql,
        int $driverCode,
        string $constraint
    ): void {
        foreach ($setup as $statement) {
            $this->pdo->exec($statement);
        }

        try {
            $this->pdo->exec($sql);
            self::fail("L'instruction devait être refusée par la contrainte « $constraint ».");
        } catch (PDOException $e) {
            self::assertSame('23000', $e->errorInfo[0] ?? null, 'SQLSTATE : violation de contrainte d\'intégrité');
            self::assertSame($driverCode, $e->errorInfo[1] ?? null, 'code MariaDB : ' . $e->getMessage());
            self::assertStringContainsStringIgnoringCase(
                $constraint,
                $e->getMessage(),
                'refusée, mais pas par la contrainte attendue'
            );
        }
    }

    /**
     * Valeurs codées qui ne diffèrent d'une valeur permise que par la casse ou une espace finale.
     * Le PHP compare strictement (in_array(..., true), match) : la base devrait les refuser aussi.
     *
     * @return array<string, array{string, string}>
     */
    public static function caseVariantCodes(): array
    {
        return [
            'mode de prescription en minuscules' => [
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode) VALUES ('ZZC3', '1',
                    'PRA01', 'medical')",
                'chk_careplan_mode',
            ],
            'mode de prescription suivi d\'une espace' => [
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode) VALUES ('ZZC4', '1',
                    'PRA01', 'MEDICAL ')",
                'chk_careplan_mode',
            ],
            'vacation en minuscules' => [
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2030-01-01',
                    'matin', 'Zz')",
                'chk_careteam_shift',
            ],
            'nature de ligne de protocole en minuscules' => [
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition)
                 VALUES ('ZZPA1', 'PLD01', 'care', 'TYP02')",
                'chk_plandefinitionaction_kind',
            ],
        ];
    }

    #[DataProvider('caseVariantCodes')]
    public function testCheckOnCodedValueIsCaseAndSpaceSensitive(string $sql, string $constraint): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            self::assertSame(self::CHECK, $e->errorInfo[1] ?? null, $e->getMessage());
            self::assertStringContainsStringIgnoringCase($constraint, $e->getMessage());

            return;
        }

        $this->markTestIncomplete(
            "CONTRAINTE INCOMPLÈTE ($constraint, database/schema.sql) : la base est en utf8mb4_unicode_ci, "
            . 'insensible à la casse et aux espaces finales, donc IN (...) accepte « medical », « MEDICAL  » '
            . 'ou « jour », que le code PHP (comparaisons strictes) ne reconnaît pas. Proposition au '
            . 'responsable BDD : comparer en binaire, ex. CHECK (mode COLLATE utf8mb4_bin IN (...)) '
            . 'ou CHECK (BINARY mode IN (...)).'
        );
    }

    /**
     * Instructions valides de contrôle : [préparation, instruction, nombre de lignes touchées].
     *
     * @return array<string, array{list<string>, string, int}>
     */
    public static function validStatements(): array
    {
        return [
            'IPP aux deux bornes (incluses)' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP1', 100000, 'Zzbas'), ('ZZP2',
                    999999999, 'Zzhaut')",
                2,
            ],
            'plusieurs patients sans IPP (admission en urgence, AURA-05)' => [
                [],
                "INSERT INTO Patient (idPatient, ipp, lastName) VALUES ('ZZP1', NULL, 'Zzun'), ('ZZP2', NULL,
                    'Zzdeux')",
                2,
            ],
            'séjour minimal' => [[self::ZZ_PATIENT], self::ZZ_EPISODE, 1],
            'les trois modes de prescription' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode) VALUES
                    ('ZZC1', '1', 'PRA01', 'MEDICAL'), ('ZZC2', '1', 'PRA04', 'PARAMEDICAL'), ('ZZC3', '1',
                    'PRA05', 'EMERGENCY')",
                3,
            ],
            'ordonnance validée (valideur et date ensemble)' => [
                [],
                "INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idValidator, validatedAt)
                 VALUES ('ZZC4', '1', 'PRA03', 'MEDICAL', 'PRA01', '2030-01-01 08:00:00')",
                1,
            ],
            'ligne de soin unique, fin égale au début, arrêtée avec auteur et date' => [
                [],
                "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, endDate,
                    frequencyHours, idStoppedBy, stoppedAt)
                 VALUES ('ZZSR1', '1', 'TYP02', '2030-01-01 08:00:00', '2030-01-01 08:00:00', NULL, 'PRA01',
                 '2030-01-01 09:00:00')",
                1,
            ],
            'lignes de protocole soin et médicament bien formées' => [
                [],
                "INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition,
                    idMedication, dose, route, startOffsetHours, durationHours, frequencyHours) VALUES
                    ('ZZPA1', 'PLD01', 'CARE', 'TYP02', NULL, NULL, NULL, 0, NULL, NULL),
                    ('ZZPA2', 'PLD01', 'MEDICATION', 'TYP01', 'MED01', '1 g', 'IV', 2, 48, 6)",
                2,
            ],
            'même heure sur une ligne de soin et une ligne de médicament homonymes' => [
                [],
                "INSERT INTO Appointment (idAppointment, idServiceRequest, idMedicationRequest, plannedTime) VALUES
                    ('ZZAP1', '1', NULL, '2030-01-01 08:00:00'), ('ZZAP2', NULL, '1', '2030-01-01 08:00:00')",
                2,
            ],
            'même DCI sous une autre forme' => [
                [],
                "INSERT INTO Medication (idMedication, inn, form) VALUES ('ZZM1', 'Paracétamol', 'Comprimé')",
                1,
            ],
            'nom d\'équipe réutilisé après retrait de l\'ancienne (suppression logique)' => [
                ["UPDATE CareTeam SET removedAt = '2026-10-14 09:00:00' WHERE idCareTeam = 1"],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2026-10-14',
                    'MATIN', 'Équipe A')",
                1,
            ],
            'même nom d\'équipe sur une autre rotation' => [
                ["INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2030-01-02',
                    'MATIN', 'Zz même nom')"],
                "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name) VALUES ('SRV01', '2030-01-02',
                    'NUIT', 'Zz même nom')",
                1,
            ],
            'suppression d\'un patient sans aucune dépendance' => [
                [self::ZZ_PATIENT],
                "DELETE FROM Patient WHERE idPatient = 'ZZP1'",
                1,
            ],
        ];
    }

    /** @param list<string> $setup */
    #[DataProvider('validStatements')]
    public function testValidStatementIsAccepted(array $setup, string $sql, int $affectedRows): void
    {
        foreach ($setup as $statement) {
            $this->pdo->exec($statement);
        }

        self::assertSame($affectedRows, $this->pdo->exec($sql));
    }

    public function testRetiredTeamsDoNotBlockEachOther(): void
    {
        // activeKey = NULL pour une équipe retirée : plusieurs équipes retirées peuvent porter le même nom.
        $this->pdo->exec("UPDATE CareTeam SET removedAt = '2026-10-14 09:00:00' WHERE idCareTeam = 1");
        $this->pdo->exec(
            "INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name, removedAt)
             VALUES ('SRV01', '2026-10-14', 'MATIN', 'Équipe A', '2026-10-14 10:00:00')"
        );

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM CareTeam WHERE shiftDate = '2026-10-14' AND shift = 'MATIN' AND name = 'Équipe A'"
        )->fetchColumn();

        self::assertSame(2, $count);
    }
}
