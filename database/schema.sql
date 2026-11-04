-- Schéma de la base AURA : structure complète, rejouée sur une base vide (CI, installation neuve).
-- Modèle validé en section 8 du dossier de cadrage, noms HL7 (voir docs/ARCHITECTURE.md pour le diagramme).
-- Base existante : les évolutions additives sont dans database/migrations/ (à rejouer dans l'ordre des dates).
-- La CI charge ce fichier dans une base MariaDB vide : une erreur SQL fait échouer la CI.
-- Règles : moteur InnoDB, encodage utf8mb4, aucune donnée réelle de patient.

#DROP DATABASE aura;
#CREATE DATABASE IF NOT EXISTS aura;
#USE aura;

DROP TABLE IF EXISTS AuditEvent; -- Historique des modifications du dossier (aucune clé étrangère)
DROP TABLE IF EXISTS CareTeamPatient; -- PatientÉquipe ->[CareTeam,EpisodeOfCare]
DROP TABLE IF EXISTS CareTeamMember; -- MembreÉquipe ->[CareTeam,Practitioner]
DROP TABLE IF EXISTS CareTeam; -- Équipe d'une rotation ->[HealthcareService]
DROP TABLE IF EXISTS Appointment; -- SoinPlanifié ->[ServiceRequest,MedicationRequest]
DROP TABLE IF EXISTS MedicationRequest; -- PrescriptionMedicament ->[Medication,ActivityDefinition,CarePlan,Practitioner]
DROP TABLE IF EXISTS ServiceRequest; -- PrescriptionSoin ->[ActivityDefinition,CarePlan,Practitioner]
DROP TABLE IF EXISTS CarePlan; -- Prescription ->[Practitioner,EpisodeOfCare,PlanDefinition]
DROP TABLE IF EXISTS PlanDefinitionAction; -- LigneProtocole ->[PlanDefinition,ActivityDefinition,Medication]
DROP TABLE IF EXISTS PlanDefinition; -- Protocole ->[Pathology]
DROP TABLE IF EXISTS Pathology; -- Pathologie
DROP TABLE IF EXISTS EpisodeOfCare; -- Séjour ->[Patient,HealthcareService,Practitioner]
DROP TABLE IF EXISTS Practitioner; -- Praticien ->[PractitionerRole]
DROP TABLE IF EXISTS AllergyIntolerance; -- Allergie ->[Patient,Substance]
DROP TABLE IF EXISTS Medication; -- Médicament ->[Substance]
DROP TABLE IF EXISTS Substance; -- Substance (Allergène)
DROP TABLE IF EXISTS PractitionerRole; -- RôlePraticien
DROP TABLE IF EXISTS ActivityDefinition; -- TypeSoin
DROP TABLE IF EXISTS HealthcareService; -- Service Hospitalier
DROP TABLE IF EXISTS Patient; -- Patient

CREATE TABLE Patient( -- Patient
    idPatient VARCHAR(20) PRIMARY KEY,
    ipp INT UNIQUE CHECK (ipp BETWEEN 100000 AND 999999999),
    firstName VARCHAR(50),
    lastName VARCHAR(50) NOT NULL,
    phoneNumber VARCHAR(10),
    email VARCHAR(50),
    sex VARCHAR(1),
    birthDate DATE,
    deceased BOOLEAN,
    generalPractitioner VARCHAR(100),
    address VARCHAR(100),
    bloodType VARCHAR(3),
    -- Champs complémentaires du dossier patient (PR « admission-champs-complets », 2026-11-02),
    -- tous facultatifs : identité de naissance, INS, personne de confiance, DMP, directives
    -- anticipées, note libre sur les allergies, antécédents (ATCD) en texte libre.
    -- Stables au fil des séjours : ils vivent sur Patient, pas sur EpisodeOfCare.
    birthLastName VARCHAR(50),
    birthFirstName VARCHAR(50),
    insNumber VARCHAR(20),
    trustedPersonName VARCHAR(100),
    trustedPersonPhone VARCHAR(20),
    trustedPersonEmail VARCHAR(100),
    dmpIdentifier VARCHAR(100),
    advanceDirectives TEXT,
    allergyNote TEXT,
    medicalHistory TEXT,
    surgicalHistory TEXT,
    obstetricHistory TEXT,
    transfusionHistory TEXT,
    vaccinationHistory TEXT,
    anestheticHistory TEXT,
    toxicHistory TEXT,
    familySocialHistory TEXT,
    lifestyle TEXT,
    foreignTravel TEXT,
    comorbidities TEXT,
    currentTreatment TEXT,
    -- Suppression logique d'un patient (convention « Suppression » du README). NULL = patient actif ; sinon
    -- date de la suppression.
    -- Un patient supprimé n'apparaît plus dans la liste ni dans les recherches (les DAO filtrent
    -- removedAt IS NULL) ; sa ligne, ses séjours et son IPP restent en base (traçabilité, RG10).
    removedAt DATETIME NULL,

    -- Recherche d'un patient existant à l'admission (PR « admission-recherche-patient ») : index pour le tri
    -- (ORDER BY lastName, firstName), l'égalité « nom exact » et findByIdentite() ; le filtre par début de mot
    -- (expression REPLACE/CONCAT) ne les utilise pas.
    INDEX idx_patient_lastname (lastName),
    INDEX idx_patient_firstname (firstName),
    INDEX idx_patient_birthlastname (birthLastName)
);

CREATE TABLE HealthcareService( -- Service Hospitalier
    idHealthcareService VARCHAR(20) PRIMARY KEY,
    serviceName VARCHAR(50)
);

CREATE TABLE ActivityDefinition( -- TypeSoin
    idActivityDefinition VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100),
    description VARCHAR(200)
);

CREATE TABLE PractitionerRole( -- RôlePraticien
    idPractitionerRole VARCHAR(20) PRIMARY KEY,
    title VARCHAR(50),
    canValidate BOOLEAN NOT NULL DEFAULT FALSE
);

-- Référentiel des allergènes : une substance n'y figure qu'une fois.
CREATE TABLE Substance( -- Allergène
    idSubstance VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE Medication( -- Medicament
    idMedication VARCHAR(20) PRIMARY KEY,
    inn VARCHAR(100), -- DCI
    form VARCHAR(100),
    idSubstance VARCHAR(20), -- Si le composé actif est un allergène connu

    CONSTRAINT uq_inn_form UNIQUE (inn,form),
    CONSTRAINT fk_substance_medication FOREIGN KEY (idSubstance) REFERENCES Substance(idSubstance)
);

-- Allergie connue d'un patient (RG2).
CREATE TABLE AllergyIntolerance( -- Allergie
    idAllergy VARCHAR(20) PRIMARY KEY,
    idPatient VARCHAR(20) NOT NULL,
    idSubstance VARCHAR(20) NOT NULL,
    manifestation VARCHAR(100),
    criticality VARCHAR(100),
    -- Date à laquelle l'allergie a été enregistrée dans AURA, remplie par la base à
    -- l'insertion. Ce n'est pas la date d'apparition de l'allergie. L'allergie est liée
    -- au patient et non au séjour : la date distingue une allergie connue depuis un
    -- séjour antérieur d'une allergie découverte pendant le séjour en cours, et dira si
    -- elle était connue au moment d'un soin.
    recordedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT uq_substance_patient UNIQUE (idPatient, idSubstance),
    CONSTRAINT fk_patient_allergy FOREIGN KEY (idPatient) REFERENCES Patient(idPatient),
    CONSTRAINT fk_substance_allergy FOREIGN KEY (idSubstance) REFERENCES Substance(idSubstance)
);

CREATE TABLE Practitioner( -- Praticien 
    idPractitioner VARCHAR(20) PRIMARY KEY,
    idPractitionerRole VARCHAR(20),
    firstName VARCHAR(50),
    lastName VARCHAR(50),
    phoneNumber VARCHAR(10),
    email VARCHAR(50),

    CONSTRAINT fk_practitionerrole_practitioner FOREIGN KEY (idPractitionerRole) REFERENCES PractitionerRole(idPractitionerRole)
);

CREATE TABLE EpisodeOfCare( -- Séjour
    idEpisodeOfCare VARCHAR(20) PRIMARY KEY,
    idPatient VARCHAR(20),
    idHealthcareService VARCHAR(20),
    -- Numéro de séjour, généré par l'application (SejourDao::insert()), jamais saisi (RG1).
    -- Format AAAA-NNNNNN : année de admissionDate, tiret, compteur sur 6 chiffres qui
    -- repart à 000001 chaque année (ex. 2026-000108). UNIQUE refuse tout doublon.
    episodeNumber VARCHAR(20) NOT NULL UNIQUE,
    admissionDate DATETIME NOT NULL,
    dischargeDate DATETIME,
    bedNumber VARCHAR(20),
    boxNumber VARCHAR(20),
    admissionReason VARCHAR(1000),
    -- Mesures relevées à l'admission, facultatives : poids en kg,
    -- taille en cm, précautions en texte libre (isolement, risque de chute…).
    weight DECIMAL(5,2),
    height DECIMAL(5,2),
    precautions VARCHAR(500),
    -- Champs complémentaires de l'admission (PR « admission-champs-complets », 2026-11-02), tous
    -- facultatifs et propres à l'hospitalisation : statut spécial, plan blanc, histoire de la maladie
    -- (5 zones), scores à l'admission (valeurs contrôlées par Aura\Domain\ClinicalScales), chirurgie.
    specialStatus VARCHAR(100),
    whitePlan TEXT,
    diseaseHistory1 TEXT,
    diseaseHistory2 TEXT,
    diseaseHistory3 TEXT,
    diseaseHistory4 TEXT,
    diseaseHistory5 TEXT,
    asaScore VARCHAR(5),
    nyhaClass VARCHAR(5),
    mcCabeScore VARCHAR(5),
    knausScore VARCHAR(5),
    girScore VARCHAR(5),
    intermacsProfile VARCHAR(5),
    intubationCriteria TEXT,
    surgeryDate DATE,
    materialLeftInPlace TEXT,
    removableProstheses VARCHAR(200),
    completedBy VARCHAR(100),
    -- Médecin référent du séjour (responsable de soin, retours des médecins du 2026-11-03) : le médecin
    -- de jour qui fait l'admission ou reprend le patient. NULL = « à désigner » (admission de garde :
    -- un médecin de jour le désigne ensuite depuis le dossier).
    idReferringPractitioner VARCHAR(20),

    CONSTRAINT fk_patient_episode FOREIGN KEY (idPatient) REFERENCES Patient(idPatient),
    CONSTRAINT fk_healthcareservice_episode FOREIGN KEY (idHealthcareService) REFERENCES HealthcareService(idHealthcareService),
    CONSTRAINT fk_referringpractitioner_episode FOREIGN KEY (idReferringPractitioner) REFERENCES Practitioner(idPractitioner)
);

-- Référentiel des pathologies auxquelles un protocole s'applique. Pas de nom HL7 : en FHIR,
-- Condition est le diagnostic d'UN patient, pas un référentiel de maladies.
CREATE TABLE Pathology( -- Pathologie
    idPathology VARCHAR(20) PRIMARY KEY,
    label VARCHAR(100) NOT NULL UNIQUE
);

-- Protocole (« groupe de prescriptions » de l'écran B3) : un modèle d'ordonnance dont les
-- lignes (PlanDefinitionAction) pré-remplissent une nouvelle prescription. Un protocole
-- retiré passe à active = FALSE (suppression logique) : les ordonnances qui en sont issues
-- gardent leur lien.
CREATE TABLE PlanDefinition( -- Protocole
    idPlanDefinition VARCHAR(20) PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    idPathology VARCHAR(20),
    active BOOLEAN NOT NULL DEFAULT TRUE,

    CONSTRAINT fk_pathology_plandefinition FOREIGN KEY (idPathology) REFERENCES Pathology(idPathology)
);

-- Ligne modèle d'un protocole : les mêmes champs qu'une ligne de soin (kind = 'CARE') ou de
-- médicament (kind = 'MEDICATION'), mais des durées à la place des dates. Appliquer le
-- protocole à une date D donne startDate = D + startOffsetHours et, si durationHours est
-- renseignée, endDate = startDate + durationHours (NULL = pas de date d'arrêt).
CREATE TABLE PlanDefinitionAction( -- LigneProtocole
    idPlanDefinitionAction VARCHAR(20) PRIMARY KEY,
    idPlanDefinition VARCHAR(20) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    -- RG6 : type de soin (« Administration médicamenteuse » pour un médicament).
    idActivityDefinition VARCHAR(20) NOT NULL,
    -- Médicament, dose et voie : obligatoires pour un médicament, vides pour un soin.
    idMedication VARCHAR(20),
    dose VARCHAR(50),
    route VARCHAR(50),
    important VARCHAR(200),
    nurseInstructions VARCHAR(200),
    maxDose VARCHAR(50),
    dilutionVolume VARCHAR(50),
    solvent VARCHAR(200),
    administrationDuration VARCHAR(50),
    antibioticReason VARCHAR(200),
    germ VARCHAR(200),
    renalAdjustment VARCHAR(200),
    adjustmentCriterion VARCHAR(200),
    offGhsReason VARCHAR(200),
    -- Heures entre l'application du protocole et le début de la ligne (0 = tout de suite).
    startOffsetHours SMALLINT NOT NULL DEFAULT 0,
    durationHours SMALLINT,
    frequencyHours SMALLINT,
    timeConstraint VARCHAR(200),

    CONSTRAINT chk_plandefinitionaction_kind CHECK (BINARY kind IN ('MEDICATION', 'CARE')),
    CONSTRAINT chk_plandefinitionaction_medication CHECK (
        (kind = 'MEDICATION' AND idMedication IS NOT NULL AND dose IS NOT NULL AND route IS NOT NULL)
        OR (kind = 'CARE' AND idMedication IS NULL AND dose IS NULL AND route IS NULL)
    ),
    CONSTRAINT chk_plandefinitionaction_offset CHECK (startOffsetHours >= 0),
    CONSTRAINT chk_plandefinitionaction_frequence CHECK (frequencyHours IS NULL OR frequencyHours > 0),
    -- RG3 : une ligne « une fois » n'a pas de date d'arrêt, donc pas de durée.
    CONSTRAINT chk_plandefinitionaction_duree CHECK (
        durationHours IS NULL OR (durationHours > 0 AND frequencyHours IS NOT NULL)
    ),

    CONSTRAINT fk_plandefinition_plandefinitionaction FOREIGN KEY (idPlanDefinition) REFERENCES PlanDefinition(idPlanDefinition),
    CONSTRAINT fk_activitydefinition_plandefinitionaction FOREIGN KEY (idActivityDefinition) REFERENCES ActivityDefinition(idActivityDefinition),
    CONSTRAINT fk_medication_plandefinitionaction FOREIGN KEY (idMedication) REFERENCES Medication(idMedication)
);

CREATE TABLE CarePlan( -- Prescription
    idCarePlan VARCHAR(20) PRIMARY KEY,
    idEpisodeOfCare VARCHAR(20) NOT NULL,
    idPrescriber VARCHAR(20) NOT NULL,
    mode VARCHAR(20) NOT NULL,
    recordedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- RG5 / RG10 : médecin qui a validé et quand (NULL = non validée). La validation porte
    -- sur toute l'ordonnance.
    idValidator VARCHAR(20),
    validatedAt DATETIME,
    -- Traçabilité : protocole d'où vient l'ordonnance ; NULL = saisie ligne par ligne.
    idPlanDefinition VARCHAR(20),
    -- Note libre du prescripteur sur l'ordonnance (retours médecins du 2026-11-03) ; NULL = aucune.
    -- Longueur bornée par CarePlanService::NOTE_MAX_LENGTH.
    note TEXT,

    CONSTRAINT chk_careplan_mode CHECK (BINARY mode IN ('MEDICAL', 'PARAMEDICAL', 'EMERGENCY')),
    CONSTRAINT chk_careplan_validation CHECK ((idValidator IS NULL) = (validatedAt IS NULL)),

    CONSTRAINT fk_episodeofcare_careplan FOREIGN KEY (idEpisodeOfCare) REFERENCES EpisodeOfCare(idEpisodeOfCare),
    CONSTRAINT fk_prescriber_careplan FOREIGN KEY (idPrescriber) REFERENCES Practitioner(idPractitioner),
    CONSTRAINT fk_validator_careplan FOREIGN KEY (idValidator) REFERENCES Practitioner(idPractitioner),
    CONSTRAINT fk_plandefinition_careplan FOREIGN KEY (idPlanDefinition) REFERENCES PlanDefinition(idPlanDefinition)
);

CREATE TABLE ServiceRequest( -- LignePrescriptionSoin
    idServiceRequest VARCHAR(20) PRIMARY KEY,
    idCarePlan VARCHAR(20) NOT NULL,
    -- RG6 : type de soin.
    idActivityDefinition VARCHAR(20) NOT NULL,
    startDate DATETIME NOT NULL,
    endDate DATETIME,
    frequencyHours SMALLINT,
    -- Contrainte horaire en texte libre (« à jeun », « 8 h et 20 h »).
    timeConstraint VARCHAR(200),
    -- Arrêt de la ligne (suppression logique, convention « Suppression » du README), par qui et quand (RG10).
    idStoppedBy VARCHAR(20),
    stoppedAt DATETIME,

    CONSTRAINT chk_servicerequest_frequence CHECK (frequencyHours IS NULL OR frequencyHours > 0),
    CONSTRAINT chk_servicerequest_dates CHECK (endDate IS NULL OR endDate >= startDate),
    CONSTRAINT chk_servicerequest_arret CHECK ((idStoppedBy IS NULL) = (stoppedAt IS NULL)),

    CONSTRAINT fk_careplan_servicerequest FOREIGN KEY (idCarePlan) REFERENCES CarePlan(idCarePlan),
    CONSTRAINT fk_activitydefinition_servicerequest FOREIGN KEY (idActivityDefinition) REFERENCES ActivityDefinition(idActivityDefinition),
    CONSTRAINT fk_stoppedby_servicerequest FOREIGN KEY (idStoppedBy) REFERENCES Practitioner(idPractitioner)
);

CREATE TABLE MedicationRequest( -- LignePrescriptionMedicament
    idMedicationRequest VARCHAR(20) PRIMARY KEY,
    idCarePlan VARCHAR(20) NOT NULL,
    -- RG6 : type de soin (en général « Administration médicamenteuse »).
    idActivityDefinition VARCHAR(20) NOT NULL,
    idMedication VARCHAR(20) NOT NULL,
    -- Texte libre (« 1 g », « 0,5 µg/kg/min ») et voie (IV, PO, SC…).
    dose VARCHAR(50) NOT NULL,
    route VARCHAR(50) NOT NULL,
    -- Écran B3 (figure 9) : « Important -> » et « Consignes pour IDE », facultatifs.
    important VARCHAR(200),
    nurseInstructions VARCHAR(200),
    -- Écran B3 (figure 9) : « (Dose max) », « Volume de dilution », « Solvant », facultatifs.
    maxDose VARCHAR(50),
    dilutionVolume VARCHAR(50),
    solvent VARCHAR(200),
    -- Écran B3 (figure 9), facultatifs : durée d'administration (« 30 min »), motif et germe
    -- (si antibiotique), adaptation rénale et son critère, motif si hors GHS.
    administrationDuration VARCHAR(50),
    antibioticReason VARCHAR(200),
    germ VARCHAR(200),
    renalAdjustment VARCHAR(200),
    adjustmentCriterion VARCHAR(200),
    offGhsReason VARCHAR(200),
    startDate DATETIME NOT NULL,
    endDate DATETIME,
    frequencyHours SMALLINT,
    timeConstraint VARCHAR(200),
    idStoppedBy VARCHAR(20),
    stoppedAt DATETIME,

    CONSTRAINT chk_medicationrequest_frequence CHECK (frequencyHours IS NULL OR frequencyHours > 0),
    CONSTRAINT chk_medicationrequest_dates CHECK (endDate IS NULL OR endDate >= startDate),
    CONSTRAINT chk_medicationrequest_arret CHECK ((idStoppedBy IS NULL) = (stoppedAt IS NULL)),

    CONSTRAINT fk_careplan_medicationrequest FOREIGN KEY (idCarePlan) REFERENCES CarePlan(idCarePlan),
    CONSTRAINT fk_activitydefinition_medicationrequest FOREIGN KEY (idActivityDefinition) REFERENCES ActivityDefinition(idActivityDefinition),
    CONSTRAINT fk_medication_medicationrequest FOREIGN KEY (idMedication) REFERENCES Medication(idMedication),
    CONSTRAINT fk_stoppedby_medicationrequest FOREIGN KEY (idStoppedBy) REFERENCES Practitioner(idPractitioner)
);

CREATE TABLE Appointment( -- SoinPlanifie
    idAppointment VARCHAR(20) PRIMARY KEY,
    idServiceRequest VARCHAR(20),
    idMedicationRequest VARCHAR(20),
    plannedTime DATETIME NOT NULL,
    status VARCHAR(20),
    timeOfCompletion DATETIME,
    timeRecorded DATETIME,
    comment VARCHAR(100),

    CONSTRAINT chk_appointment_ligne CHECK ((idServiceRequest IS NULL) <> (idMedicationRequest IS NULL)),
    -- Une ligne n'a qu'un soin planifié par heure prévue. La génération quotidienne de
    -- l'agenda (bin/generate-agenda.php) écarte déjà les heures existantes ; ces clés en sont la garantie
    -- en base (NULL n'entre pas en conflit : chaque soin ne remplit qu'une des deux colonnes).
    CONSTRAINT uq_appointment_service_time UNIQUE (idServiceRequest, plannedTime),
    CONSTRAINT uq_appointment_medication_time UNIQUE (idMedicationRequest, plannedTime),

    CONSTRAINT fk_servicerequest_appointment FOREIGN KEY (idServiceRequest) REFERENCES ServiceRequest(idServiceRequest),
    CONSTRAINT fk_medicationrequest_appointment FOREIGN KEY (idMedicationRequest) REFERENCES MedicationRequest(idMedicationRequest)
);

-- Historique des modifications champ par champ du dossier patient (PR « dossier-patient-edition-inline »,
-- 2026-11-02, exigence de traçabilité du cahier des charges). Nom FHIR : AuditEvent. Une ligne par
-- modification enregistrée depuis le dossier : entité (Patient ou EpisodeOfCare), identifiant, champ
-- (nom de colonne), ancienne et nouvelle valeur, auteur (« non identifié » tant que le praticien n'est pas identifié),
-- horodatage. Pas de clé étrangère : l'historique survit à toute évolution des tables tracées et
-- ne bloque jamais une écriture. AUTO_INCREMENT : table de journal, jamais lue par un id calculé.
CREATE TABLE AuditEvent(
    idAuditEvent INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity VARCHAR(20) NOT NULL,
    entityId VARCHAR(20) NOT NULL,
    field VARCHAR(50) NOT NULL,
    oldValue TEXT NULL,
    newValue TEXT NULL,
    author VARCHAR(100) NOT NULL,
    -- Précision libre (ex. mode de recherche ayant conduit à un rattachement : « recherche par nom »).
    detail VARCHAR(200) NULL,
    recordedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_auditevent_entity (entity, entityId, recordedAt)
);

-- Équipes de rotation (PROPOSITION 2026-11-03 à valider par le responsable BDD) : au début de chaque
-- rotation (MATIN 6 h à 14 h, APRES_MIDI 14 h à 22 h, NUIT 22 h à 6 h, bornes à confirmer avec le service),
-- le cadre compose les équipes et leur attribue des patients. Une équipe retirée garde sa ligne (removedAt) ;
-- activeKey rend le nom unique parmi les équipes actives de la rotation (convention « Suppression » du
-- README). Un patient n'appartient qu'à une équipe par rotation : contrôlé par le service (CareTeamService),
-- la base ne pouvant l'exprimer sans dupliquer la rotation dans CareTeamPatient.
CREATE TABLE CareTeam( -- Équipe d'une rotation
    idCareTeam INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    idHealthcareService VARCHAR(20) NOT NULL,
    shiftDate DATE NOT NULL,
    -- Rotation (2026-11-04 : les trois rotations de l'agenda font foi) : MATIN 6 h à 14 h, APRES_MIDI 14 h à
    -- 22 h, NUIT 22 h à 6 h (datée de son début). Voir CareTeam::SHIFTS.
    shift VARCHAR(10) NOT NULL,
    name VARCHAR(30) NOT NULL,
    removedAt DATETIME,
    activeKey TINYINT AS (IF(removedAt IS NULL, 1, NULL)) STORED,

    CONSTRAINT chk_careteam_shift CHECK (BINARY shift IN ('MATIN', 'APRES_MIDI', 'NUIT')),
    CONSTRAINT uq_careteam_name UNIQUE (idHealthcareService, shiftDate, shift, name, activeKey),
    CONSTRAINT fk_healthcareservice_careteam FOREIGN KEY (idHealthcareService)
        REFERENCES HealthcareService(idHealthcareService)
);

CREATE TABLE CareTeamMember( -- Soignant d'une équipe
    idCareTeam INT UNSIGNED NOT NULL,
    idPractitioner VARCHAR(20) NOT NULL,

    PRIMARY KEY (idCareTeam, idPractitioner),
    CONSTRAINT fk_careteam_member FOREIGN KEY (idCareTeam) REFERENCES CareTeam(idCareTeam),
    CONSTRAINT fk_practitioner_member FOREIGN KEY (idPractitioner) REFERENCES Practitioner(idPractitioner)
);

CREATE TABLE CareTeamPatient( -- Patient attribué à une équipe
    idCareTeam INT UNSIGNED NOT NULL,
    idEpisodeOfCare VARCHAR(20) NOT NULL,

    PRIMARY KEY (idCareTeam, idEpisodeOfCare),
    CONSTRAINT fk_careteam_patient FOREIGN KEY (idCareTeam) REFERENCES CareTeam(idCareTeam),
    CONSTRAINT fk_episode_careteam FOREIGN KEY (idEpisodeOfCare) REFERENCES EpisodeOfCare(idEpisodeOfCare)
);
