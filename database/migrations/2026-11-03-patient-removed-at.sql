-- Migration additive (2026-11-03) : suppression logique d'un patient (Patient.removedAt).
-- À jouer sur une base EXISTANTE ; une installation neuve charge database/schema.sql.
-- Rejouable (IF NOT EXISTS). Aucun DROP. Proposition à valider par le responsable BDD.
--   mariadb -u aura -p aura < database/migrations/2026-11-03-patient-removed-at.sql

ALTER TABLE Patient ADD COLUMN IF NOT EXISTS removedAt DATETIME NULL;
