-- Migration additive (2026-11-03) : note libre du prescripteur sur l'ordonnance (CarePlan.note), retours des
-- médecins sur la vue Prescription.
-- À jouer sur une base EXISTANTE ; une installation neuve charge database/schema.sql (qui contient déjà
-- la colonne).
-- Rejouable : ADD COLUMN IF NOT EXISTS (MariaDB). Aucun DROP.
--   mariadb -u aura -p aura < database/migrations/2026-11-03-note-ordonnance.sql
--
-- L'alerte allergie de l'écran B3 lit Medication.idSubstance (colonne déjà présente) : sur une base
-- existante, elle ne se déclenche que pour les médicaments dont l'allergène est renseigné. Les données de
-- démonstration (database/test.sql) relient l'amoxicilline à la pénicilline et le céfotaxime aux
-- céphalosporines ; rechargez test.sql pour en profiter.

ALTER TABLE CarePlan
    ADD COLUMN IF NOT EXISTS note TEXT NULL;
