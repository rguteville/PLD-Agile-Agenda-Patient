-- Données fictives de test (AURA-05). Chargées par la CI après database/schema.sql.
-- Règles : aucune donnée réelle de patient ni de membre de l'équipe ; colonnes nommées
-- explicitement pour que l'ajout d'une colonne au schéma ne casse pas ce fichier.

INSERT INTO Service (idService, serviceName) VALUES
    ('REA', 'Réanimation');

INSERT INTO Patient (idPatient, ipp, lastName, firstName, birthDate, sex, phoneNumber, email) VALUES
    ('1', 100200300, 'Lefevre', 'Camille', '1958-03-21', 'F', '0600000001', 'c.lefevre@exemple.fr'),
    ('2', NULL,      'Moreau',  'Julien',  '1979-11-02', 'M', NULL,         NULL);

INSERT INTO Sejour (idSejour, idPatient, idService, sejourNumber, admissionDate, dischargeDate, bedNumber, boxNumber) VALUES
    ('1', '1', 'REA', '000000001', '2026-10-12 08:15:00', NULL, '3', 'B2'),
    ('2', '2', 'REA', '000000002', '2026-10-13 22:40:00', NULL, '7', 'B5');

INSERT INTO Allergene (idAllergene, substance) VALUES
    ('PENI', 'Pénicilline'),
    ('LATEX', 'Latex');

INSERT INTO Allergie (idAllergie, idPatient, idAllergene, criticality) VALUES
    ('1', '2', 'PENI', 'sévère'),
    ('2', '2', 'LATEX', 'modérée');
