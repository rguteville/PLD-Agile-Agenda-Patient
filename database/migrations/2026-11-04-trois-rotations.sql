-- Migration (2026-11-04) : les équipes suivent les trois rotations de l'agenda (décision de Raphaël : un seul
-- découpage fait foi). MATIN 6 h à 14 h, APRES_MIDI 14 h à 22 h, NUIT 22 h à 6 h. À jouer sur une base EXISTANTE
-- qui a déjà les tables d'équipes (2026-11-03-equipes-vacations.sql) ; une installation neuve charge
-- database/schema.sql. Proposition à valider par le responsable de la base (Didac).
-- Rejouable : la contrainte est supprimée si elle existe, puis recréée.
--   mariadb -u aura -p aura < database/migrations/2026-11-04-trois-rotations.sql
--
-- Les anciennes équipes de JOUR (8 h à 20 h) deviennent des équipes du MATIN ; les équipes de NUIT gardent leur
-- code (la nuit va désormais de 22 h à 6 h).

ALTER TABLE CareTeam DROP CONSTRAINT IF EXISTS chk_careteam_shift;
ALTER TABLE CareTeam MODIFY shift VARCHAR(10) NOT NULL;
UPDATE CareTeam SET shift = 'MATIN' WHERE shift = 'JOUR';
ALTER TABLE CareTeam ADD CONSTRAINT chk_careteam_shift CHECK (BINARY shift IN ('MATIN', 'APRES_MIDI', 'NUIT'));
