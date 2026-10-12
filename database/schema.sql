-- Active: 1791788651216@@127.0.0.1@3306@aura
-- Schéma de la base AURA (V1).
-- Mettez ici les CREATE TABLE du modèle validé (section 8 du dossier de cadrage).
-- La CI charge ce fichier dans une base MariaDB vide : une erreur SQL fait échouer la CI.
-- Règles : moteur InnoDB, encodage utf8mb4, aucune donnée réelle de patient.
CREATE DATABASE aura;
USE aura;

DROP TABLE Sejour;
DROP TABLE Patient;
CREATE TABLE Patient(
    idPatient VARCHAR(20) PRIMARY KEY,
    ipp INT CHECK (100000<=ipp<1000000000),
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
    sejourNumber VARCHAR(20),
    admissionDate DATETIME NOT NULL,
    dischargeDate DATETIME,
    bedNumber VARCHAR(20),
    boxNumber VARCHAR(20),
    admissionReason VARCHAR(1000),

    CONSTRAINT fk_patient FOREIGN KEY (idPatient) REFERENCES Patient(idPatient),
    CONSTRAINT fk_service FOREIGN KEY (idService) REFERENCES Service(idService)
);

CREATE TABLE Allergene(
    idAllergene VARCHAR(20) PRIMARY KEY,
    substance VARCHAR(50)
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

    CONSTRAINT fk_qualification FOREIGN KEY (idQualification) REFERENCES Service(idQualification)
);

CREATE TABLE Prescription(
    idPrescription VARCHAR(20) PRIMARY KEY,
    recurrent BOOLEAN,
    verified BOOLEAN,
    startDate DATE,
    endDate DATE,
    frequency VARCHAR(50)
);

CREATE TABLE Soin_planifie(
    idSoinPlanifie VARCHAR(20) PRIMARY KEY,
    idPrescription,
    #...
)