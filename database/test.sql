-- Données de démonstration AURA, 100 % fictives (aucune donnée réelle de patient).
-- À charger APRÈS schema.sql :  mariadb -u aura -p aura < database/test.sql
-- Ordre des INSERT : d'abord les tables sans clé étrangère, puis celles qui les référencent.

-- Indique à MariaDB que ce fichier est en UTF-8. Sans ça, si le client est en latin1
-- (cas fréquent), les accents sont stockés en double encodage et PHP affiche "LefÃ¨vre".
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Référentiels (aucune dépendance)
-- ---------------------------------------------------------------------------

INSERT INTO Service (idService, serviceName) VALUES
    ('SRV01', 'Réanimation médicale'),
    ('SRV02', 'Réanimation chirurgicale'),
    ('SRV03', 'Soins continus');

INSERT INTO Qualification (idQualification, label) VALUES
    ('QUA01', 'Médecin réanimateur'),
    ('QUA02', 'Interne'),
    ('QUA03', 'Infirmier(e) diplômé(e) d''État'),
    ('QUA04', 'Aide-soignant(e)');

INSERT INTO Allergene (idAllergene, substance) VALUES
    ('ALG01', 'Pénicilline'),
    ('ALG02', 'Latex'),
    ('ALG03', 'Iode'),
    ('ALG04', 'Arachide'),
    ('ALG05', 'Morphine');

INSERT INTO Medicament (idMedicament, inn, form) VALUES
    ('MED01', 'Paracétamol', 'Solution injectable'),
    ('MED02', 'Noradrénaline', 'Solution pour perfusion'),
    ('MED03', 'Héparine sodique', 'Solution injectable'),
    ('MED04', 'Amoxicilline', 'Poudre pour solution injectable'),
    ('MED05', 'Midazolam', 'Solution injectable');

INSERT INTO Type_Soin (idTypeSoin, name, description) VALUES
    ('TYP01', 'Administration médicamenteuse', 'Administration d''un traitement prescrit'),
    ('TYP02', 'Prise des constantes', 'Pression artérielle, fréquence cardiaque, SpO2, température'),
    ('TYP03', 'Prélèvement sanguin', 'Bilan biologique sur prescription'),
    ('TYP04', 'Réfection de pansement', 'Pansement de voie centrale ou de plaie'),
    ('TYP05', 'Aspiration trachéale', 'Pour patient intubé ou trachéotomisé');

-- ---------------------------------------------------------------------------
-- Praticiens (dépend de Qualification)
-- ---------------------------------------------------------------------------

INSERT INTO Praticien (idPraticien, firstName, lastName, phoneNumber, email, idQualification) VALUES
    ('PRA01', 'Claire',  'Morel',    '0600000101', 'c.morel@example.com',    'QUA01'),
    ('PRA02', 'Karim',   'Benali',   '0600000102', 'k.benali@example.com',   'QUA01'),
    ('PRA03', 'Léa',     'Fontaine', '0600000103', 'l.fontaine@example.com', 'QUA02'),
    ('PRA04', 'Thomas',  'Girard',   '0600000104', 't.girard@example.com',   'QUA03'),
    ('PRA05', 'Inès',    'Rousseau', '0600000105', 'i.rousseau@example.com', 'QUA03'),
    ('PRA06', 'Julien',  'Perrin',   '0600000106', NULL,                     'QUA04');

-- ---------------------------------------------------------------------------
-- Patients (aucune dépendance)
-- idPatient et idSejour sont des VARCHAR dans le schéma mais DOIVENT contenir des
-- entiers : PatientDao et SejourDao les lisent en int et PatientDao::insert()
-- calcule le prochain id par MAX(CAST(idPatient AS UNSIGNED)) + 1.
-- Le patient 7 n'a pas d'IPP (ipp NULL, AURA-05) : admis en urgence, son IPP n'est
-- pas encore attribué. Le bandeau et la liste doivent afficher « sans IPP ».
-- ---------------------------------------------------------------------------

INSERT INTO Patient (idPatient, ipp, firstName, lastName, phoneNumber, email, sex, birthDate, deceased, generalPractitioner, address) VALUES
    ('1', 100000001, 'Marie',   'Dupont',  '0600000001', 'marie.dupont@example.com', 'F', '1958-03-14', FALSE, 'Dr Lambert',  '12 rue des Lilas, 69003 Lyon'),
    ('2', 100000002, 'Jean',    'Martin',  '0600000002', NULL,                       'M', '1972-11-02', FALSE, 'Dr Lambert',  '4 avenue Berthelot, 69007 Lyon'),
    ('3', 100000003, 'Sofia',   'Haddad',  '0600000003', 'sofia.h@example.com',      'F', '1990-06-25', FALSE, 'Dr Nguyen',   '27 cours Gambetta, 69007 Lyon'),
    ('4', 100000004, 'Lucas',   'Bernard', NULL,         NULL,                       'M', '2001-01-09', FALSE, NULL,          '3 place Bellecour, 69002 Lyon'),
    ('5', 100000005, 'Odette',  'Lefèvre', '0600000005', NULL,                       'F', '1939-08-30', TRUE,  'Dr Faure',    '8 rue Mercière, 69002 Lyon'),
    ('6', 100000006, 'Yanis',   'Mercier', '0600000006', 'yanis.m@example.com',      'M', '1985-12-17', FALSE, 'Dr Nguyen',   '15 boulevard du 11 Novembre, 69100 Villeurbanne'),
    ('7', NULL,      'Camille', 'Roux',    NULL,         NULL,                       'F', '1967-04-21', FALSE, NULL,          NULL);

-- ---------------------------------------------------------------------------
-- Séjours (dépend de Patient et Service)
-- dischargeDate NULL = patient encore hospitalisé
-- ---------------------------------------------------------------------------

INSERT INTO Sejour (idSejour, idPatient, idService, sejourNumber, admissionDate, dischargeDate, bedNumber, boxNumber, admissionReason) VALUES
    ('1', '1', 'SRV01', '2026-000101', '2026-10-10 08:15:00', NULL,                  'L01', 'B01', 'Pneumopathie hypoxémiante'),
    ('2', '2', 'SRV01', '2026-000102', '2026-10-11 22:40:00', NULL,                  'L02', 'B02', 'Choc septique d''origine urinaire'),
    ('3', '3', 'SRV02', '2026-000103', '2026-10-12 14:05:00', NULL,                  'L05', 'B05', 'Surveillance post-opératoire'),
    ('4', '4', 'SRV02', '2026-000104', '2026-10-01 03:20:00', '2026-10-08 11:00:00', 'L06', 'B06', 'Polytraumatisme (accident de la route)'),
    ('5', '5', 'SRV01', '2026-000105', '2026-09-20 17:30:00', '2026-09-27 06:45:00', 'L03', 'B03', 'Insuffisance cardiaque décompensée'),
    ('6', '6', 'SRV03', '2026-000106', '2026-10-13 09:00:00', NULL,                  'L09', 'B09', 'Surveillance après intoxication'),
    ('7', '1', 'SRV03', '2026-000107', '2026-06-02 10:00:00', '2026-06-05 16:00:00', 'L08', 'B08', 'Séjour antérieur : décompensation respiratoire'),
    ('8', '7', 'SRV01', '2026-000108', '2026-10-14 02:30:00', NULL,                  'L04', 'B04', 'Arrêt cardio-respiratoire récupéré');

-- ---------------------------------------------------------------------------
-- Allergies (dépend de Patient et Allergene)
-- ---------------------------------------------------------------------------

INSERT INTO Allergie (idAllergie, idPatient, idAllergene, criticality) VALUES
    ('ALL01', '1', 'ALG01', 'Élevée'),
    ('ALL02', '1', 'ALG02', 'Faible'),
    ('ALL03', '3', 'ALG03', 'Élevée'),
    ('ALL04', '6', 'ALG04', 'Élevée'),
    ('ALL05', '2', 'ALG05', 'Faible');

-- ---------------------------------------------------------------------------
-- Prescriptions (dépend de Praticien)
-- ---------------------------------------------------------------------------

INSERT INTO Prescription (idPrescription, idPrescriber, idDoctor, recurrent, verified, startDate, endDate, frequency) VALUES
    ('PRE01', 'PRA03', 'PRA01', TRUE,  TRUE,  '2026-10-10', '2026-10-17', 'Toutes les 6 h'),
    ('PRE02', 'PRA01', 'PRA01', TRUE,  TRUE,  '2026-10-11', NULL,         'En continu'),
    ('PRE03', 'PRA02', 'PRA02', FALSE, TRUE,  '2026-10-12', '2026-10-12', 'Une fois'),
    ('PRE04', 'PRA03', 'PRA02', TRUE,  FALSE, '2026-10-12', '2026-10-19', 'Toutes les 4 h'),
    ('PRE05', 'PRA02', 'PRA02', TRUE,  TRUE,  '2026-10-13', '2026-10-20', 'Matin et soir'),
    ('PRE06', 'PRA01', 'PRA01', TRUE,  TRUE,  '2026-09-20', '2026-09-27', 'Toutes les 8 h');

-- ---------------------------------------------------------------------------
-- Soins planifiés (dépend de Prescription)
-- timeOfCompletion / timeRecorded remplis seulement quand le soin a été fait
-- ---------------------------------------------------------------------------

INSERT INTO Soin_planifie (idSoinPlanifie, idPrescription, plannedTime, status, timeOfCompletion, timeRecorded, comment) VALUES
    ('SOI01', 'PRE01', '2026-10-14 06:00:00', 'REALISE',   '2026-10-14 06:05:00', '2026-10-14 06:10:00', NULL),
    ('SOI02', 'PRE01', '2026-10-14 12:00:00', 'REALISE',   '2026-10-14 12:20:00', '2026-10-14 12:25:00', 'Retard : patient au scanner'),
    ('SOI03', 'PRE01', '2026-10-14 18:00:00', 'PLANIFIE',  NULL,                  NULL,                  NULL),
    ('SOI04', 'PRE01', '2026-10-15 00:00:00', 'PLANIFIE',  NULL,                  NULL,                  NULL),
    ('SOI05', 'PRE02', '2026-10-14 08:00:00', 'REALISE',   '2026-10-14 08:00:00', '2026-10-14 08:02:00', 'Débit ajusté selon PAM'),
    ('SOI06', 'PRE03', '2026-10-12 15:00:00', 'REALISE',   '2026-10-12 15:10:00', '2026-10-12 15:15:00', NULL),
    ('SOI07', 'PRE04', '2026-10-14 08:00:00', 'NON_FAIT',  NULL,                  '2026-10-14 08:30:00', 'Prescription non vérifiée'),
    ('SOI08', 'PRE04', '2026-10-14 12:00:00', 'PLANIFIE',  NULL,                  NULL,                  NULL),
    ('SOI09', 'PRE05', '2026-10-14 08:00:00', 'REALISE',   '2026-10-14 08:15:00', '2026-10-14 08:16:00', NULL),
    ('SOI10', 'PRE05', '2026-10-14 20:00:00', 'PLANIFIE',  NULL,                  NULL,                  NULL),
    ('SOI11', 'PRE06', '2026-09-26 16:00:00', 'ANNULE',    NULL,                  '2026-09-26 15:00:00', 'Arrêt du traitement'),
    ('SOI12', 'PRE06', '2026-09-27 00:00:00', 'ANNULE',    NULL,                  '2026-09-26 15:00:00', 'Arrêt du traitement');
