-- Migration additive (2026-11-02) : champs complémentaires de l'admission et du dossier patient.
-- À jouer sur une base EXISTANTE qui a déjà le schéma précédent ; une installation neuve
-- charge directement database/schema.sql (qui contient déjà ces colonnes).
-- Rejouable : ADD COLUMN IF NOT EXISTS (MariaDB ≥ 10.0). Aucun DROP, aucune modification de colonne.
--   mariadb -u aura -p aura < database/migrations/2026-11-02-champs-admission-dossier.sql

ALTER TABLE Patient
    ADD COLUMN IF NOT EXISTS birthLastName VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS birthFirstName VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS insNumber VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS trustedPersonName VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS trustedPersonPhone VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS trustedPersonEmail VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS dmpIdentifier VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS advanceDirectives TEXT NULL,
    ADD COLUMN IF NOT EXISTS allergyNote TEXT NULL,
    ADD COLUMN IF NOT EXISTS medicalHistory TEXT NULL,
    ADD COLUMN IF NOT EXISTS surgicalHistory TEXT NULL,
    ADD COLUMN IF NOT EXISTS obstetricHistory TEXT NULL,
    ADD COLUMN IF NOT EXISTS transfusionHistory TEXT NULL,
    ADD COLUMN IF NOT EXISTS vaccinationHistory TEXT NULL,
    ADD COLUMN IF NOT EXISTS anestheticHistory TEXT NULL,
    ADD COLUMN IF NOT EXISTS toxicHistory TEXT NULL,
    ADD COLUMN IF NOT EXISTS familySocialHistory TEXT NULL,
    ADD COLUMN IF NOT EXISTS lifestyle TEXT NULL,
    ADD COLUMN IF NOT EXISTS foreignTravel TEXT NULL,
    ADD COLUMN IF NOT EXISTS comorbidities TEXT NULL,
    ADD COLUMN IF NOT EXISTS currentTreatment TEXT NULL;

ALTER TABLE EpisodeOfCare
    ADD COLUMN IF NOT EXISTS specialStatus VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS whitePlan TEXT NULL,
    ADD COLUMN IF NOT EXISTS diseaseHistory1 TEXT NULL,
    ADD COLUMN IF NOT EXISTS diseaseHistory2 TEXT NULL,
    ADD COLUMN IF NOT EXISTS diseaseHistory3 TEXT NULL,
    ADD COLUMN IF NOT EXISTS diseaseHistory4 TEXT NULL,
    ADD COLUMN IF NOT EXISTS diseaseHistory5 TEXT NULL,
    ADD COLUMN IF NOT EXISTS asaScore VARCHAR(5) NULL,
    ADD COLUMN IF NOT EXISTS nyhaClass VARCHAR(5) NULL,
    ADD COLUMN IF NOT EXISTS mcCabeScore VARCHAR(5) NULL,
    ADD COLUMN IF NOT EXISTS knausScore VARCHAR(5) NULL,
    ADD COLUMN IF NOT EXISTS girScore VARCHAR(5) NULL,
    ADD COLUMN IF NOT EXISTS intermacsProfile VARCHAR(5) NULL,
    ADD COLUMN IF NOT EXISTS intubationCriteria TEXT NULL,
    ADD COLUMN IF NOT EXISTS surgeryDate DATE NULL,
    ADD COLUMN IF NOT EXISTS materialLeftInPlace TEXT NULL,
    ADD COLUMN IF NOT EXISTS removableProstheses VARCHAR(200) NULL,
    ADD COLUMN IF NOT EXISTS completedBy VARCHAR(100) NULL;
