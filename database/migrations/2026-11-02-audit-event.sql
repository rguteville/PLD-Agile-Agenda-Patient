-- Migration additive (2026-11-02) : historique des modifications du dossier patient (AuditEvent).
-- À jouer sur une base EXISTANTE ; une installation neuve charge database/schema.sql.
-- Rejouable (CREATE TABLE IF NOT EXISTS). Aucun DROP.
--   mariadb -u aura -p aura < database/migrations/2026-11-02-audit-event.sql

CREATE TABLE IF NOT EXISTS AuditEvent(
    idAuditEvent INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity VARCHAR(20) NOT NULL,
    entityId VARCHAR(20) NOT NULL,
    field VARCHAR(50) NOT NULL,
    oldValue TEXT NULL,
    newValue TEXT NULL,
    author VARCHAR(100) NOT NULL,
    -- Précision libre (ex. mode de recherche ayant conduit à un rattachement : « recherche par nom »).
    detail VARCHAR(200) NULL,
    recordedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_auditevent_entity (entity, entityId, recordedAt)
);
