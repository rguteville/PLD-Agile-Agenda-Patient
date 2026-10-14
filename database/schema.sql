-- Schéma de la base AURA (V1).
-- Mettez ici les CREATE TABLE du modèle validé (section 8 du dossier de cadrage).
-- La CI charge ce fichier dans une base MariaDB vide : une erreur SQL fait échouer la CI.
-- Règles : moteur InnoDB, encodage utf8mb4, aucune donnée réelle de patient.

#CREATE DATABASE IF NOT EXISTS aura;
#USE aura;

DROP TABLE IF EXISTS Sejour;
DROP TABLE IF EXISTS Soin_planifie;
DROP TABLE IF EXISTS Allergie;
DROP TABLE IF EXISTS Prescription;
DROP TABLE IF EXISTS Patient;
DROP TABLE IF EXISTS Service;
DROP TABLE IF EXISTS Allergene;
DROP TABLE IF EXISTS Praticien;
DROP TABLE IF EXISTS Qualification;
DROP TABLE IF EXISTS Medicament;
DROP TABLE IF EXISTS Type_Soin;



CREATE TABLE Patient(
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
    address VARCHAR(100)

);

CREATE TABLE Service(
    idService VARCHAR(20) PRIMARY KEY,
    serviceName VARCHAR(50)
);

CREATE TABLE Sejour(
    idSejour VARCHAR(20) PRIMARY KEY,
    idPatient VARCHAR(20),
    idService VARCHAR(20),
    -- Numéro de séjour, généré par l'application (SejourDao::insert()), jamais saisi (RG1).
    -- Format AAAA-NNNNNN : année de admissionDate, tiret, compteur sur 6 chiffres qui
    -- repart à 000001 chaque année (ex. 2026-000108). UNIQUE refuse tout doublon.
    sejourNumber VARCHAR(20) NOT NULL UNIQUE,
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

    CONSTRAINT fk_patient_sejour FOREIGN KEY (idPatient) REFERENCES Patient(idPatient),
    CONSTRAINT fk_service_sejour FOREIGN KEY (idService) REFERENCES Service(idService)
);

CREATE TABLE Allergene(
    idAllergene VARCHAR(20) PRIMARY KEY,
    substance VARCHAR(50)
);

CREATE TABLE Allergie(
    idAllergie VARCHAR(20) PRIMARY KEY,
    idPatient VARCHAR(20),
    idAllergene VARCHAR(20),
    criticality VARCHAR(100),

    CONSTRAINT fk_patient_allergie FOREIGN KEY (idPatient) REFERENCES Patient(idPatient),
    CONSTRAINT fk_allergene_allergie FOREIGN KEY (idAllergene) REFERENCES Allergene(idAllergene)

);

CREATE TABLE Qualification(
    idQualification VARCHAR(20) PRIMARY KEY,
    label VARCHAR(50)
);

CREATE TABLE Praticien(
    idPraticien VARCHAR(20) PRIMARY KEY,
    firstName VARCHAR(50),
    lastName VARCHAR(50),
    phoneNumber VARCHAR(10),
    email VARCHAR(50),
    idQualification VARCHAR(20),

    CONSTRAINT fk_qualification_praticien FOREIGN KEY (idQualification) REFERENCES Qualification(idQualification)
);

CREATE TABLE Prescription(
    idPrescription VARCHAR(20) PRIMARY KEY,
    idPrescriber VARCHAR(20),
    idDoctor VARCHAR(20),
    recurrent BOOLEAN,
    verified BOOLEAN,
    startDate DATE,
    endDate DATE,
    frequency VARCHAR(50),

    CONSTRAINT fk_prescriber_prescription FOREIGN KEY (idPrescriber) REFERENCES Praticien(idPraticien),
    CONSTRAINT fk_doctor_prescription FOREIGN KEY (idDoctor) REFERENCES Praticien(idPraticien)
);

CREATE TABLE Soin_planifie(
    idSoinPlanifie VARCHAR(20) PRIMARY KEY,
    idPrescription VARCHAR(20),
    plannedTime DATETIME,
    status VARCHAR(20),
    timeOfCompletion DATETIME,
    timeRecorded DATETIME,
    comment VARCHAR(100),

    CONSTRAINT fk_prescription_soin_planifie FOREIGN KEY (idPrescription) REFERENCES Prescription(idPrescription)
);

CREATE TABLE Medicament(
    idMedicament VARCHAR(20) PRIMARY KEY,
    inn VARCHAR(100),
    form VARCHAR(100)
);

CREATE TABLE Type_Soin(
    idTypeSoin VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100),
    description VARCHAR(200)
);