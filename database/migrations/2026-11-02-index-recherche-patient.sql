-- Migration additive (2026-11-02) : index pour la recherche d'un patient par nom / prénom à l'admission.
-- À jouer sur une base EXISTANTE après 2026-11-02-champs-admission-dossier.sql (birthLastName).
-- Rejouable (ADD INDEX IF NOT EXISTS). Aucun DROP.
--   mariadb -u aura -p aura < database/migrations/2026-11-02-index-recherche-patient.sql

ALTER TABLE Patient
    ADD INDEX IF NOT EXISTS idx_patient_lastname (lastName),
    ADD INDEX IF NOT EXISTS idx_patient_firstname (firstName),
    ADD INDEX IF NOT EXISTS idx_patient_birthlastname (birthLastName);
