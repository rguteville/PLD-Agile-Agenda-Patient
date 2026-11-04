-- Migration (2026-11-03) : les CHECK sur valeurs codées comparent en binaire. La base est en
-- utf8mb4_unicode_ci, insensible à la casse et aux espaces finales : « medical » ou « MEDICAL  » passaient,
-- alors que le code PHP compare strictement (tests/Dao/SchemaConstraintsTest.php). Rejouable.
-- Échoue si une ligne existante porte déjà une variante (à corriger d'abord en UPDATE).
-- Le CHECK binaire de CareTeam.shift n'est pas ici : la table n'est créée que par
-- 2026-11-03-equipes-vacations.sql, jouée après celle-ci, et c'est 2026-11-04-trois-rotations.sql qui pose
-- le CHECK final.
--   mariadb -u aura -p aura < database/migrations/2026-11-03-check-binaire.sql

ALTER TABLE CarePlan DROP CONSTRAINT IF EXISTS chk_careplan_mode;
ALTER TABLE CarePlan ADD CONSTRAINT chk_careplan_mode CHECK (BINARY mode IN ('MEDICAL', 'PARAMEDICAL', 'EMERGENCY'));

ALTER TABLE PlanDefinitionAction DROP CONSTRAINT IF EXISTS chk_plandefinitionaction_kind;
ALTER TABLE PlanDefinitionAction ADD CONSTRAINT chk_plandefinitionaction_kind CHECK (BINARY kind IN ('MEDICATION', 'CARE'));
