-- Migration additive (2026-11-03, AURA-24.2 / 24.3) : un seul soin planifié par ligne et par heure prévue.
-- À jouer sur une base EXISTANTE ; une installation neuve charge database/schema.sql.
-- Rejouable (IF NOT EXISTS). Aucun DROP. Proposition à valider par le responsable BDD.
-- Échoue si la base contient déjà des doublons ; pour les lister :
--   SELECT idServiceRequest, idMedicationRequest, plannedTime, COUNT(*) FROM Appointment
--   GROUP BY idServiceRequest, idMedicationRequest, plannedTime HAVING COUNT(*) > 1;
--   mariadb -u aura -p aura < database/migrations/2026-11-03-appointment-unique-time.sql

ALTER TABLE Appointment
    ADD CONSTRAINT uq_appointment_service_time UNIQUE IF NOT EXISTS (idServiceRequest, plannedTime);
ALTER TABLE Appointment
    ADD CONSTRAINT uq_appointment_medication_time UNIQUE IF NOT EXISTS (idMedicationRequest, plannedTime);
