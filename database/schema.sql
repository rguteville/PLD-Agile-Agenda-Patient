-- Schéma de la base AURA (V1).
-- Mettez ici les CREATE TABLE du modèle validé (section 8 du dossier de cadrage).
-- La CI charge ce fichier dans une base MariaDB vide : une erreur SQL fait échouer la CI.
-- Règles : moteur InnoDB, encodage utf8mb4, aucune donnée réelle de patient.

#DROP DATABASE aura;
#CREATE DATABASE IF NOT EXISTS aura;
#USE aura;

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
    bloodType VARCHAR(3)
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

    CONSTRAINT chk_careplan_mode CHECK (mode IN ('MEDICALE', 'PARAMEDICALE', 'PROTOCOLE', 'URGENCE')),
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