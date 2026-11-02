-- Schéma de la base AURA (V1).
-- Mettez ici les CREATE TABLE du modèle validé (section 8 du dossier de cadrage).
-- La CI charge ce fichier dans une base MariaDB vide : une erreur SQL fait échouer la CI.
-- Règles : moteur InnoDB, encodage utf8mb4, aucune donnée réelle de patient.

#DROP DATABASE aura;
#CREATE DATABASE IF NOT EXISTS aura;
#USE aura;

DROP TABLE IF EXISTS AuditEvent; -- Historique des modifications du dossier (aucune clé étrangère)
DROP TABLE IF EXISTS Appointment; -- SoinPlanifié ->[ServiceRequest,MedicationRequest]
DROP TABLE IF EXISTS ServiceRequest; -- PrescriptionSoin ->[ActivityDefinition,CarePlan,Practitioner]
DROP TABLE IF EXISTS MedicationRequest; -- PrescriptionMedicament ->[Medication,CarePlan,Practitioner]
DROP TABLE IF EXISTS CarePlan; -- Prescription ->[Practitioner,EpisodeOfCare]
DROP TABLE IF EXISTS EpisodeOfCare; -- Séjour ->[Patient,HealthcareService]
DROP TABLE IF EXISTS Practitioner; -- Praticien ->[PractitionerRole]
DROP TABLE IF EXISTS AllergyIntolerance; -- Allergie ->[Patient,Substance]
DROP TABLE IF EXISTS Substance; -- Allergène
DROP TABLE IF EXISTS PractitionerRole; -- RôlePraticien
DROP TABLE IF EXISTS Medication; -- Médicament
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
    birthLastName VARCHAR(50) NULL,
    birthFirstName VARCHAR(50) NULL,
    insNumber VARCHAR(20) NULL,
    trustedPersonName VARCHAR(100) NULL,
    trustedPersonPhone VARCHAR(20) NULL,
    trustedPersonEmail VARCHAR(100) NULL,
    dmpIdentifier VARCHAR(100) NULL,
    advanceDirectives TEXT NULL,
    allergyNote TEXT NULL,
    medicalHistory TEXT NULL,
    surgicalHistory TEXT NULL,
    obstetricHistory TEXT NULL,
    transfusionHistory TEXT NULL,
    vaccinationHistory TEXT NULL,
    anestheticHistory TEXT NULL,
    toxicHistory TEXT NULL,
    familySocialHistory TEXT NULL,
    lifestyle TEXT NULL,
    foreignTravel TEXT NULL,
    comorbidities TEXT NULL,
    currentTreatment TEXT NULL
);

CREATE TABLE HealthcareService( -- Service Hospitalier
    idHealthcareService VARCHAR(20) PRIMARY KEY,
    serviceName VARCHAR(50)
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
    -- Mesures relevées à l'admission (AURA-04, AURA-10.1), facultatives : poids en kg,
    -- taille en cm, précautions en texte libre (isolement, risque de chute…).
    weight DECIMAL(5,2) NULL,
    height DECIMAL(5,2) NULL,
    precautions VARCHAR(500) NULL,
    -- Champs complémentaires de l'admission (PR « admission-champs-complets », 2026-11-02), tous
    -- facultatifs et propres à l'hospitalisation : statut spécial, plan blanc, histoire de la maladie
    -- (5 zones), scores à l'admission (valeurs contrôlées par Aura\Domain\ClinicalScales), chirurgie.
    specialStatus VARCHAR(100) NULL,
    whitePlan TEXT NULL,
    diseaseHistory1 TEXT NULL,
    diseaseHistory2 TEXT NULL,
    diseaseHistory3 TEXT NULL,
    diseaseHistory4 TEXT NULL,
    diseaseHistory5 TEXT NULL,
    asaScore VARCHAR(5) NULL,
    nyhaClass VARCHAR(5) NULL,
    mcCabeScore VARCHAR(5) NULL,
    knausScore VARCHAR(5) NULL,
    girScore VARCHAR(5) NULL,
    intermacsProfile VARCHAR(5) NULL,
    intubationCriteria TEXT NULL,
    surgeryDate DATE NULL,
    materialLeftInPlace TEXT NULL,
    removableProstheses VARCHAR(200) NULL,
    completedBy VARCHAR(100) NULL,

    CONSTRAINT fk_patient_episode FOREIGN KEY (idPatient) REFERENCES Patient(idPatient) ON DELETE RESTRICT,
    CONSTRAINT fk_healthcareservice_episode FOREIGN KEY (idHealthcareService) REFERENCES HealthcareService(idHealthcareService) ON DELETE RESTRICT
);

-- Référentiel des allergènes (AURA-13.1) : une substance n'y figure qu'une fois.
CREATE TABLE Substance( -- Allergène
    idSubstance VARCHAR(20) PRIMARY KEY,
    description VARCHAR(100) NOT NULL UNIQUE
);

-- Allergie connue d'un patient (AURA-13.1, RG2). idAllergie reste la clé (lue par
-- AllergieDao). Suppression logique (règle n° 8 du README) : retirer une allergie
-- remplit removedAt, la ligne reste en base. ON DELETE RESTRICT : on ne supprime ni un
-- patient ni un allergène qui a des allergies enregistrées, même retirées.
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
    -- elle était connue au moment d'un soin (AURA-20/25).
    recordedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Date de retrait (suppression logique) ; NULL = allergie active.
    #removedAt DATETIME NULL,
    -- 1 si l'allergie est active, NULL si elle est retirée. Calculée par la base, jamais
    -- écrite. Comme UNIQUE ignore les NULL, la contrainte ci-dessous refuse deux
    -- allergies ACTIVES pour le même couple patient / allergène, mais permet de saisir
    -- à nouveau un allergène retiré (MariaDB n'a pas d'index partiel).
    #activeKey TINYINT GENERATED ALWAYS AS (IF(removedAt IS NULL, 1, NULL)) STORED,

    CONSTRAINT uq_substance_patient UNIQUE (idPatient, idSubstance),
    CONSTRAINT fk_patient_allergy FOREIGN KEY (idPatient) REFERENCES Patient(idPatient)
        ON DELETE RESTRICT,
    CONSTRAINT fk_substance_allergy FOREIGN KEY (idSubstance) REFERENCES Substance(idSubstance)
        ON DELETE RESTRICT
);

CREATE TABLE PractitionerRole( -- RôlePraticien
    idPractitionerRole VARCHAR(20) PRIMARY KEY,
    description VARCHAR(50),
    canValidate BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE Practitioner( -- Praticien 
    idPractitioner VARCHAR(20) PRIMARY KEY,
    idPractitionerRole VARCHAR(20),
    firstName VARCHAR(50),
    lastName VARCHAR(50),
    phoneNumber VARCHAR(10),
    email VARCHAR(50),

    CONSTRAINT fk_practitionerrole_practitioner FOREIGN KEY (idPractitionerRole) REFERENCES PractitionerRole(idPractitionerRole) ON DELETE RESTRICT
);


CREATE TABLE CarePlan( -- Prescription
    idCarePlan VARCHAR(20) PRIMARY KEY,
    idEpisodeOfCare VARCHAR(20) NOT NULL,
    idPrescriber VARCHAR(20) NOT NULL,
    mode VARCHAR(20) NOT NULL,
    recordedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- RG5 / RG10 : médecin qui a validé et quand (NULL = non validée). La validation porte
    -- sur toute l'ordonnance.
    idValidator VARCHAR(20) NULL,
    validatedAt DATETIME NULL,

    CONSTRAINT chk_careplan_mode CHECK (mode IN ('MEDICAL', 'PARAMEDICAL', 'EMERGENCY')),
    CONSTRAINT chk_careplan_validation CHECK ((idValidator IS NULL) = (validatedAt IS NULL)),

    CONSTRAINT fk_episodeofcare_careplan FOREIGN KEY (idEpisodeOfCare) REFERENCES EpisodeOfCare(idEpisodeOfCare) ON DELETE RESTRICT,
    CONSTRAINT fk_prescriber_careplan FOREIGN KEY (idPrescriber) REFERENCES Practitioner(idPractitioner) ON DELETE RESTRICT,
    CONSTRAINT fk_validator_careplan FOREIGN KEY (idValidator) REFERENCES Practitioner(idPractitioner) ON DELETE RESTRICT
);

CREATE TABLE Medication( -- Medicament
    idMedication VARCHAR(20) PRIMARY KEY,
    inn VARCHAR(100), -- DCI
    form VARCHAR(100),

    CONSTRAINT uq_inn_form UNIQUE (inn,form)
);

CREATE TABLE ActivityDefinition( -- TypeSoin
    idActivityDefinition VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100),
    description VARCHAR(200)
);


CREATE TABLE ServiceRequest( -- LignePrescriptionSoin
    idServiceRequest VARCHAR(20) PRIMARY KEY,
    idCarePlan VARCHAR(20) NOT NULL,
    -- RG6 : type de soin.
    idActivityDefinition VARCHAR(20) NOT NULL,
    startDate DATETIME NOT NULL,
    endDate DATETIME NULL,
    frequencyHours SMALLINT NULL,
    -- Contrainte horaire en texte libre (« à jeun », « 8 h et 20 h »).
    timeConstraint VARCHAR(200) NULL,
    -- AURA-22 : arrêt de la ligne (suppression logique, règle n° 8), par qui et quand (RG10).
    idStoppedBy VARCHAR(20) NULL,
    stoppedAt DATETIME NULL,

    CONSTRAINT chk_servicerequest_frequence CHECK (frequencyHours IS NULL OR frequencyHours > 0),
    CONSTRAINT chk_servicerequest_dates CHECK (endDate IS NULL OR endDate >= startDate),
    CONSTRAINT chk_servicerequest_arret CHECK ((idStoppedBy IS NULL) = (stoppedAt IS NULL)),

    CONSTRAINT fk_careplan_servicerequest FOREIGN KEY (idCarePlan) REFERENCES CarePlan(idCarePlan) ON DELETE RESTRICT,
    CONSTRAINT fk_activitydefinition_servicerequest FOREIGN KEY (idActivityDefinition) REFERENCES ActivityDefinition(idActivityDefinition) ON DELETE RESTRICT,
    CONSTRAINT fk_stoppedby_servicerequest FOREIGN KEY (idStoppedBy) REFERENCES Practitioner(idPractitioner) ON DELETE RESTRICT
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
    important VARCHAR(200) NULL,
    nurseInstructions VARCHAR(200) NULL,
    -- Écran B3 (figure 9) : « (Dose max) », « Volume de dilution », « Solvant », facultatifs.
    maxDose VARCHAR(50) NULL,
    dilutionVolume VARCHAR(50) NULL,
    solvent VARCHAR(200) NULL,
    -- Écran B3 (figure 9), facultatifs : durée d'administration (« 30 min »), motif et germe
    -- (si antibiotique), adaptation rénale et son critère, motif si hors GHS.
    administrationDuration VARCHAR(50) NULL,
    antibioticReason VARCHAR(200) NULL,
    germ VARCHAR(200) NULL,
    renalAdjustment VARCHAR(200) NULL,
    adjustmentCriterion VARCHAR(200) NULL,
    offGhsReason VARCHAR(200) NULL,
    startDate DATETIME NOT NULL,
    endDate DATETIME NULL,
    frequencyHours SMALLINT NULL,
    timeConstraint VARCHAR(200) NULL,
    idStoppedBy VARCHAR(20) NULL,
    stoppedAt DATETIME NULL,

    CONSTRAINT chk_medicationrequest_frequence CHECK (frequencyHours IS NULL OR frequencyHours > 0),
    CONSTRAINT chk_medicationrequest_dates CHECK (endDate IS NULL OR endDate >= startDate),
    CONSTRAINT chk_medicationrequest_arret CHECK ((idStoppedBy IS NULL) = (stoppedAt IS NULL)),

    CONSTRAINT fk_careplan_medicationrequest FOREIGN KEY (idCarePlan) REFERENCES CarePlan(idCarePlan) ON DELETE RESTRICT,
    CONSTRAINT fk_activitydefinition_medicationrequest FOREIGN KEY (idActivityDefinition) REFERENCES ActivityDefinition(idActivityDefinition) ON DELETE RESTRICT,
    CONSTRAINT fk_medication_medicationrequest FOREIGN KEY (idMedication) REFERENCES Medication(idMedication) ON DELETE RESTRICT,
    CONSTRAINT fk_stoppedby_medicationrequest FOREIGN KEY (idStoppedBy) REFERENCES Practitioner(idPractitioner) ON DELETE RESTRICT
);


CREATE TABLE Appointment( -- SoinPlanifie
    idAppointment VARCHAR(20) PRIMARY KEY,
    idServiceRequest VARCHAR(20) NULL,
    idMedicationRequest VARCHAR(20) NULL,
    plannedTime DATETIME NOT NULL,
    status VARCHAR(20),
    timeOfCompletion DATETIME,
    timeRecorded DATETIME,
    comment VARCHAR(100),

    CONSTRAINT chk_appointment_ligne CHECK ((idServiceRequest IS NULL) <> (idMedicationRequest IS NULL)),

    CONSTRAINT fk_servicerequest_appointment FOREIGN KEY (idServiceRequest) REFERENCES ServiceRequest(idServiceRequest) ON DELETE RESTRICT,
    CONSTRAINT fk_medicationrequest_appointment FOREIGN KEY (idMedicationRequest) REFERENCES MedicationRequest(idMedicationRequest) ON DELETE RESTRICT
);

-- Historique des modifications champ par champ du dossier patient (PR « dossier-patient-edition-inline »,
-- 2026-11-02, exigence de traçabilité du cahier des charges). Nom FHIR : AuditEvent. Une ligne par
-- modification enregistrée depuis le dossier : entité (Patient ou EpisodeOfCare), identifiant, champ
-- (nom de colonne), ancienne et nouvelle valeur, auteur (« non identifié » tant qu'AURA-18 n'existe pas),
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
