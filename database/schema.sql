-- Active: 1791357831839@@127.0.0.1@3306@aura
-- Schéma de la base AURA (V1).
-- Mettez ici les CREATE TABLE du modèle validé (section 8 du dossier de cadrage).
-- La CI charge ce fichier dans une base MariaDB vide : une erreur SQL fait échouer la CI.
-- Règles : moteur InnoDB, encodage utf8mb4, aucune donnée réelle de patient.
CREATE DATABASE DBaura;
use DBaura;
CREATE TABLE Patient(
    id_patient VARCHAR(20) PRIMARY KEY,
    ipp INT CHECK (100000<=ipp<1000000000),
    nom VARCHAR(50),
    prenom VARCHAR(50),
    date_de_naissance DATE,
    sexe VARCHAR(1),
    telephone VARCHAR(10),
    email VARCHAR(100)


);
CREATE TABLE Sejour (
     id_sejour VARCHAR(20) PRIMARY KEY,
     id_patient VARCHAR(20),
     num_sejour VARCHAR(20),
     date_admission DATETIME NOT NULL,
     date_sortie DATETIME,
     num_lit VARCHAR(20),
     num_box VARCHAR(20),
     id_service VARCHAR(20),

    CONSTRAINT fk_patient FOREIGN KEY (id_patient) REFERENCES Patient(id_patient)

    

);

