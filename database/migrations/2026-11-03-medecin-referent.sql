-- Migration additive (2026-11-03) : médecin référent du séjour (EpisodeOfCare.idReferringPractitioner).
-- À jouer sur une base EXISTANTE ; une installation neuve charge database/schema.sql (qui contient déjà
-- la colonne et sa clé étrangère).
-- Rejouable : ADD COLUMN IF NOT EXISTS, FOREIGN KEY IF NOT EXISTS (MariaDB). Aucun DROP.
--   mariadb -u aura -p aura < database/migrations/2026-11-03-medecin-referent.sql

ALTER TABLE EpisodeOfCare
    ADD COLUMN IF NOT EXISTS idReferringPractitioner VARCHAR(20) NULL;

ALTER TABLE EpisodeOfCare
    ADD CONSTRAINT fk_referringpractitioner_episode
        FOREIGN KEY IF NOT EXISTS (idReferringPractitioner) REFERENCES Practitioner(idPractitioner);
