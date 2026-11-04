-- Migration additive (2026-11-03) : équipes de rotation, tables CareTeam, CareTeamMember, CareTeamPatient.
-- À jouer sur une base EXISTANTE ; une installation neuve charge database/schema.sql.
-- Rejouable : CREATE TABLE IF NOT EXISTS. Aucun DROP.
-- Le CHECK ci-dessous est celui de l'époque (JOUR, NUIT) : 2026-11-04-trois-rotations.sql, jouée ensuite,
-- le remplace par les trois rotations MATIN, APRES_MIDI et NUIT.
--   mariadb -u aura -p aura < database/migrations/2026-11-03-equipes-vacations.sql

CREATE TABLE IF NOT EXISTS CareTeam(
    idCareTeam INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    idHealthcareService VARCHAR(20) NOT NULL,
    shiftDate DATE NOT NULL,
    shift VARCHAR(5) NOT NULL,
    name VARCHAR(30) NOT NULL,
    removedAt DATETIME,
    activeKey TINYINT AS (IF(removedAt IS NULL, 1, NULL)) STORED,

    CONSTRAINT chk_careteam_shift CHECK (BINARY shift IN ('JOUR', 'NUIT')),
    CONSTRAINT uq_careteam_name UNIQUE (idHealthcareService, shiftDate, shift, name, activeKey),
    CONSTRAINT fk_healthcareservice_careteam FOREIGN KEY (idHealthcareService)
        REFERENCES HealthcareService(idHealthcareService)
);

CREATE TABLE IF NOT EXISTS CareTeamMember(
    idCareTeam INT UNSIGNED NOT NULL,
    idPractitioner VARCHAR(20) NOT NULL,

    PRIMARY KEY (idCareTeam, idPractitioner),
    CONSTRAINT fk_careteam_member FOREIGN KEY (idCareTeam) REFERENCES CareTeam(idCareTeam),
    CONSTRAINT fk_practitioner_member FOREIGN KEY (idPractitioner) REFERENCES Practitioner(idPractitioner)
);

CREATE TABLE IF NOT EXISTS CareTeamPatient(
    idCareTeam INT UNSIGNED NOT NULL,
    idEpisodeOfCare VARCHAR(20) NOT NULL,

    PRIMARY KEY (idCareTeam, idEpisodeOfCare),
    CONSTRAINT fk_careteam_patient FOREIGN KEY (idCareTeam) REFERENCES CareTeam(idCareTeam),
    CONSTRAINT fk_episode_careteam FOREIGN KEY (idEpisodeOfCare) REFERENCES EpisodeOfCare(idEpisodeOfCare)
);
