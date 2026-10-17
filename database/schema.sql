-- Schéma de la base AURA (V1).
-- Mettez ici les CREATE TABLE du modèle validé (section 8 du dossier de cadrage).
-- La CI charge ce fichier dans une base MariaDB vide : une erreur SQL fait échouer la CI.
-- Règles : moteur InnoDB, encodage utf8mb4, aucune donnée réelle de patient.

#DROP DATABASE aura;
#CREATE DATABASE IF NOT EXISTS aura;
#USE aura;

DROP TABLE IF EXISTS Appointment; -- SoinPlanifié ->[CarePlan]
DROP TABLE IF EXISTS ServiceRequest; -- PrescriptionSoin ->[ActivityDefinition,CarePlan]
DROP TABLE IF EXISTS MedicationDispense; -- PrescriptionMedicament ->[Medication,CarePlan]
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
    lastName VARCHAR(50),
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
    description VARCHAR(50)
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
    idEpisodeOfCare VARCHAR(20),
    idPrescriber VARCHAR(20),
    idDoctor VARCHAR(20),
    recurrent BOOLEAN,
    verified BOOLEAN,
    startDate DATE,
    endDate DATE,
    frequency VARCHAR(50), -- Type à créer ???

    CONSTRAINT fk_episodeofcare_careplan FOREIGN KEY (idEpisodeOfCare) REFERENCES EpisodeOfCare(idEpisodeOfCare) ON DELETE RESTRICT,
    CONSTRAINT fk_prescriber_careplan FOREIGN KEY (idPrescriber) REFERENCES Practitioner(idPractitioner) ON DELETE RESTRICT,
    CONSTRAINT fk_doctor_careplan FOREIGN KEY (idDoctor) REFERENCES Practitioner(idPractitioner) ON DELETE RESTRICT
);

CREATE TABLE Appointment( -- SoinPlanifié
    idAppointment VARCHAR(20) PRIMARY KEY,
    idCareplan VARCHAR(20),
    plannedTime DATETIME,
    status VARCHAR(20),
    timeOfCompletion DATETIME,
    timeRecorded DATETIME,
    comment VARCHAR(100),

    CONSTRAINT fk_careplan_appointment FOREIGN KEY (idCareplan) REFERENCES Careplan(idCareplan) ON DELETE RESTRICT
);

CREATE TABLE Medication( -- Medicament
    idMedication VARCHAR(20) PRIMARY KEY,
    inn VARCHAR(100),
    form VARCHAR(100)
);

CREATE TABLE ActivityDefinition( -- TypeSoin
    idActivityDefinition VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100),
    description VARCHAR(200)
);

CREATE TABLE ServiceRequest( -- PrescriptionSoin
    idServiceRequest VARCHAR(20) PRIMARY KEY,
    idActivityDefinition VARCHAR(20),
    idCarePlan VARCHAR(20),

    CONSTRAINT fk_activitydefinition_servicerequest FOREIGN KEY (idActivityDefinition) REFERENCES ActivityDefinition(idActivityDefinition) ON DELETE RESTRICT,
    CONSTRAINT fk_careplan_servicerequest FOREIGN KEY (idCarePlan) REFERENCES CarePlan(idCarePlan) ON DELETE RESTRICT
);

CREATE TABLE MedicationDispense( -- PrescriptionMedicament
    idMedicationDispense VARCHAR(20) PRIMARY KEY,
    idMedication VARCHAR(20),
    idCarePlan VARCHAR(20),

    CONSTRAINT fk_medication_medicationdispense FOREIGN KEY (idMedication) REFERENCES Medication(idMedication) ON DELETE RESTRICT,
    CONSTRAINT fk_careplan_medicationdispense FOREIGN KEY (idCarePlan) REFERENCES CarePlan(idCarePlan) ON DELETE RESTRICT
);