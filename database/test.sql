-- Données de démonstration AURA (schéma AURA-16, noms HL7), 100 % fictives (aucune donnée réelle de patient).
-- À charger APRÈS schema.sql :  mariadb -u aura -p aura < database/test.sql
-- Ordre des INSERT : d'abord les tables sans clé étrangère, puis celles qui les référencent.

-- Indique à MariaDB que ce fichier est en UTF-8. Sans ça, si le client est en latin1
-- (cas fréquent), les accents sont stockés en double encodage et PHP affiche "LefÃ¨vre".
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Référentiels (aucune dépendance)
-- ---------------------------------------------------------------------------

INSERT INTO HealthcareService (idHealthcareService, serviceName) VALUES
    ('SRV01', 'Réanimation médicale'),
    ('SRV02', 'Réanimation chirurgicale'),
    ('SRV03', 'Soins continus');

-- canValidate (RG5) : seul le médecin réanimateur valide ; les prescriptions d'un interne
-- ou d'un infirmier restent « non validées » jusqu'à validation par un médecin.
INSERT INTO PractitionerRole (idPractitionerRole, description, canValidate) VALUES
    ('QUA01', 'Médecin réanimateur', TRUE),
    ('QUA02', 'Interne', FALSE),
    ('QUA03', 'Infirmier(e) diplômé(e) d''État', FALSE),
    ('QUA04', 'Aide-soignant(e)', FALSE);

-- Référentiel d'allergènes génériques (AURA-13.1). description est UNIQUE.
INSERT INTO Substance (idSubstance, description) VALUES
    ('ALG01', 'Pénicilline'),
    ('ALG02', 'Latex'),
    ('ALG03', 'Iode'),
    ('ALG04', 'Arachide'),
    ('ALG05', 'Morphine'),
    ('ALG06', 'Amoxicilline'),
    ('ALG07', 'Aspirine'),
    ('ALG08', 'AINS'),
    ('ALG09', 'Codéine'),
    ('ALG10', 'Sulfamides'),
    ('ALG11', 'Céphalosporines'),
    ('ALG12', 'Œuf'),
    ('ALG13', 'Lait'),
    ('ALG14', 'Fruits à coque'),
    ('ALG15', 'Chlorhexidine');

INSERT INTO Medication (idMedication, inn, form) VALUES
    ('MED01', 'Paracétamol', 'Solution injectable'),
    ('MED02', 'Noradrénaline', 'Solution pour perfusion'),
    ('MED03', 'Héparine sodique', 'Solution injectable'),
    ('MED04', 'Amoxicilline', 'Poudre pour solution injectable'),
    ('MED05', 'Midazolam', 'Solution injectable');

INSERT INTO ActivityDefinition (idActivityDefinition, name, description) VALUES
    ('TYP01', 'Administration médicamenteuse', 'Administration d''un traitement prescrit'),
    ('TYP02', 'Prise des constantes', 'Pression artérielle, fréquence cardiaque, SpO2, température'),
    ('TYP03', 'Prélèvement sanguin', 'Bilan biologique sur prescription'),
    ('TYP04', 'Réfection de pansement', 'Pansement de voie centrale ou de plaie'),
    ('TYP05', 'Aspiration trachéale', 'Pour patient intubé ou trachéotomisé');

-- ---------------------------------------------------------------------------
-- Praticiens (dépend de PractitionerRole)
-- ---------------------------------------------------------------------------

INSERT INTO Practitioner (idPractitioner, idPractitionerRole, firstName, lastName, phoneNumber, email) VALUES
    ('PRA01', 'QUA01', 'Claire',  'Morel',    '0600000101', 'c.morel@example.com'),
    ('PRA02', 'QUA01', 'Karim',   'Benali',   '0600000102', 'k.benali@example.com'),
    ('PRA03', 'QUA02', 'Léa',     'Fontaine', '0600000103', 'l.fontaine@example.com'),
    ('PRA04', 'QUA03', 'Thomas',  'Girard',   '0600000104', 't.girard@example.com'),
    ('PRA05', 'QUA03', 'Inès',    'Rousseau', '0600000105', 'i.rousseau@example.com'),
    ('PRA06', 'QUA04', 'Julien',  'Perrin',   '0600000106', NULL);

-- ---------------------------------------------------------------------------
-- Patients (aucune dépendance)
-- idPatient et idEpisodeOfCare sont des VARCHAR mais DOIVENT contenir des entiers : les DAO
-- les lisent en int et calculent le prochain id par MAX(CAST(... AS UNSIGNED)) + 1.
-- Le patient 7 n'a pas d'IPP (ipp NULL, AURA-05) : admis en urgence, son IPP n'est
-- pas encore attribué. Le bandeau et la liste doivent afficher « sans IPP ».
-- ---------------------------------------------------------------------------

INSERT INTO Patient (idPatient, ipp, firstName, lastName, phoneNumber, email, sex, birthDate, deceased, generalPractitioner, address, bloodType) VALUES
    ('1', 100000001, 'Marie',   'Dupont',  '0600000001', 'marie.dupont@example.com', 'F', '1958-03-14', FALSE, 'Dr Lambert', '12 rue des Lilas, 69003 Lyon',                    'A+'),
    ('2', 100000002, 'Jean',    'Martin',  '0600000002', NULL,                       'M', '1972-11-02', FALSE, 'Dr Lambert', '4 avenue Berthelot, 69007 Lyon',                  'O+'),
    ('3', 100000003, 'Sofia',   'Haddad',  '0600000003', 'sofia.h@example.com',      'F', '1990-06-25', FALSE, 'Dr Nguyen',  '27 cours Gambetta, 69007 Lyon',                   'B-'),
    ('4', 100000004, 'Lucas',   'Bernard', NULL,         NULL,                       'M', '2001-01-09', FALSE, NULL,         '3 place Bellecour, 69002 Lyon',                   'O-'),
    ('5', 100000005, 'Odette',  'Lefèvre', '0600000005', NULL,                       'F', '1939-08-30', TRUE,  'Dr Faure',   '8 rue Mercière, 69002 Lyon',                      'A-'),
    ('6', 100000006, 'Yanis',   'Mercier', '0600000006', 'yanis.m@example.com',      'M', '1985-12-17', FALSE, 'Dr Nguyen',  '15 boulevard du 11 Novembre, 69100 Villeurbanne', 'AB+'),
    ('7', NULL,      'Camille', 'Roux',    NULL,         NULL,                       'F', '1967-04-21', FALSE, NULL,         NULL,                                              NULL);

-- ---------------------------------------------------------------------------
-- Séjours (dépend de Patient et HealthcareService)
-- dischargeDate NULL = patient encore hospitalisé.
-- Mesures à l'admission (AURA-10.1) : seuls certains séjours en ont (« non renseigné » sinon).
-- ---------------------------------------------------------------------------

INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService, episodeNumber, admissionDate, dischargeDate, bedNumber, boxNumber, admissionReason, weight, height, precautions) VALUES
    ('1', '1', 'SRV01', '2026-000101', '2026-10-10 08:15:00', NULL,                  'L01', 'B01', 'Pneumopathie hypoxémiante',                      62.50, 164.00, 'Isolement contact (BMR). Risque de chute.'),
    ('2', '2', 'SRV01', '2026-000102', '2026-10-11 22:40:00', NULL,                  'L02', 'B02', 'Choc septique d''origine urinaire',             88.00, 178.50, NULL),
    ('3', '3', 'SRV02', '2026-000103', '2026-10-12 14:05:00', NULL,                  'L05', 'B05', 'Surveillance post-opératoire',                   57.20, 169.00, 'Pas de lever avant avis du chirurgien.'),
    ('4', '4', 'SRV02', '2026-000104', '2026-10-01 03:20:00', '2026-10-08 11:00:00', 'L06', 'B06', 'Polytraumatisme (accident de la route)',         NULL,  NULL,   NULL),
    ('5', '5', 'SRV01', '2026-000105', '2026-09-20 17:30:00', '2026-09-27 06:45:00', 'L03', 'B03', 'Insuffisance cardiaque décompensée',             NULL,  NULL,   NULL),
    ('6', '6', 'SRV03', '2026-000106', '2026-10-13 09:00:00', NULL,                  'L09', 'B09', 'Surveillance après intoxication',                NULL,  NULL,   NULL),
    ('7', '1', 'SRV03', '2026-000107', '2026-06-02 10:00:00', '2026-06-05 16:00:00', 'L08', 'B08', 'Séjour antérieur : décompensation respiratoire', NULL,  NULL,   NULL),
    ('8', '7', 'SRV01', '2026-000108', '2026-10-14 02:30:00', NULL,                  'L04', 'B04', 'Arrêt cardio-respiratoire récupéré',             NULL,  NULL,   NULL);

-- ---------------------------------------------------------------------------
-- Allergies (dépend de Patient et Substance)
-- idAllergy est un VARCHAR mais DOIT contenir un entier : AllergieDao le lit en int.
-- recordedAt = date de saisie, pendant un des séjours du patient (l'allergie 1 au séjour 7).
-- ---------------------------------------------------------------------------

INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance, manifestation, criticality, recordedAt) VALUES
    ('1', '1', 'ALG01', 'Choc anaphylactique',   'Élevée', '2026-06-02 10:20:00'),
    ('2', '1', 'ALG02', 'Eczéma de contact',     'Faible', '2026-10-10 08:30:00'),
    ('3', '3', 'ALG03', 'Urticaire généralisée', 'Élevée', '2026-10-12 14:15:00'),
    ('4', '6', 'ALG04', 'Œdème de Quincke',      'Élevée', '2026-10-13 09:10:00'),
    ('5', '2', 'ALG05', 'Nausées, prurit',       'Faible', '2026-10-11 22:55:00');

-- ---------------------------------------------------------------------------
-- Prescriptions = ordonnances (dépend de EpisodeOfCare et Practitioner)
-- idCarePlan est un VARCHAR mais DOIT contenir un entier (comme Patient et EpisodeOfCare).
-- Un médecin (PRA01, PRA02) est son propre valideur, à l'heure de la saisie (RG5).
--   1 : interne, validée ensuite par un médecin, 3 lignes (1 médicament + 2 soins)
--   3 : 3 lignes, dont une arrêtée (midazolam) et les autres actives
--   4 : infirmier, mode PARAMEDICAL, « non validée » (RG5)
--   6 : séjour clos, sa ligne a été arrêtée
--   7 : séjour antérieur du patient 1 (ne doit PAS apparaître dans le séjour 1)
--   8 : infirmière, mode EMERGENCY, « non validée » (RG5), mais son soin a été réalisé
-- Le séjour 8 n'a aucune prescription (affichage « aucune prescription »).
-- ---------------------------------------------------------------------------

INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, recordedAt, idValidator, validatedAt) VALUES
    ('1', '1', 'PRA03', 'MEDICAL',     '2026-10-10 09:00:00', 'PRA01', '2026-10-10 09:30:00'),
    ('2', '2', 'PRA01', 'MEDICAL',     '2026-10-11 23:00:00', 'PRA01', '2026-10-11 23:00:00'),
    ('3', '3', 'PRA02', 'MEDICAL',     '2026-10-12 14:30:00', 'PRA02', '2026-10-12 14:30:00'),
    ('4', '3', 'PRA04', 'PARAMEDICAL', '2026-10-12 15:30:00', NULL,    NULL),
    ('5', '6', 'PRA02', 'MEDICAL',     '2026-10-13 09:30:00', 'PRA02', '2026-10-13 09:30:00'),
    ('6', '5', 'PRA01', 'MEDICAL',     '2026-09-20 18:00:00', 'PRA01', '2026-09-20 18:00:00'),
    ('7', '7', 'PRA02', 'MEDICAL',     '2026-06-02 10:30:00', 'PRA02', '2026-06-02 10:30:00'),
    ('8', '2', 'PRA05', 'EMERGENCY',   '2026-10-16 03:10:00', NULL,    NULL);

-- ---------------------------------------------------------------------------
-- Lignes de soin (dépend de CarePlan, ActivityDefinition, Practitioner)
-- frequencyHours NULL = soin unique (RG3) ; 48 = tous les 2 jours.
-- ---------------------------------------------------------------------------

INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, endDate, frequencyHours, timeConstraint, idStoppedBy, stoppedAt) VALUES
    ('1', '1', 'TYP03', '2026-10-11 06:00:00', NULL,                  NULL, 'À jeun', NULL, NULL),
    ('2', '1', 'TYP04', '2026-10-11 10:00:00', NULL,                  48,   NULL,     NULL, NULL),
    ('3', '3', 'TYP03', '2026-10-12 15:00:00', NULL,                  NULL, NULL,     NULL, NULL),
    ('4', '4', 'TYP02', '2026-10-12 16:00:00', '2026-10-19 16:00:00', 4,    NULL,     NULL, NULL);

-- ---------------------------------------------------------------------------
-- Lignes de médicament (dépend de CarePlan, ActivityDefinition, Medication, Practitioner)
--   4 : midazolam arrêté par un médecin (fin de sédation), les autres lignes de l'ordonnance 3 continuent
--   6 : amoxicilline arrêtée (séjour clos)
--   8 : paracétamol unique, prescrit en urgence par une infirmière
-- ---------------------------------------------------------------------------

INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition, idMedication, dose, route, startDate, endDate, frequencyHours, timeConstraint, idStoppedBy, stoppedAt) VALUES
    ('1', '1', 'TYP01', 'MED01', '1 g',           'IV',   '2026-10-10 12:00:00', '2026-10-17 12:00:00', 6,    NULL,                                   NULL,    NULL),
    ('2', '2', 'TYP01', 'MED02', '0,5 µg/kg/min', 'IVSE', '2026-10-12 08:00:00', NULL,                  24,   'Seringue électrique, débit selon PAM', NULL,    NULL),
    ('3', '3', 'TYP01', 'MED01', '1 g',           'IV',   '2026-10-12 18:00:00', '2026-10-19 18:00:00', 6,    NULL,                                   NULL,    NULL),
    ('4', '3', 'TYP01', 'MED05', '2 mg/h',        'IVSE', '2026-10-12 15:00:00', NULL,                  24,   'Seringue électrique',                  'PRA02', '2026-10-13 08:00:00'),
    ('5', '5', 'TYP01', 'MED03', '5000 UI',       'SC',   '2026-10-13 20:00:00', '2026-10-20 20:00:00', 12,   '8 h et 20 h',                          NULL,    NULL),
    ('6', '6', 'TYP01', 'MED04', '1 g',           'IV',   '2026-09-21 00:00:00', '2026-09-27 00:00:00', 8,    NULL,                                   'PRA01', '2026-09-26 15:00:00'),
    ('7', '7', 'TYP01', 'MED01', '1 g',           'PO',   '2026-06-02 12:00:00', '2026-06-05 12:00:00', 8,    'Pendant le repas',                     NULL,    NULL),
    ('8', '8', 'TYP01', 'MED01', '1 g',           'IV',   '2026-10-16 03:15:00', NULL,                  NULL, NULL,                                   NULL,    NULL);

-- ---------------------------------------------------------------------------
-- Soins planifiés (dépend de ServiceRequest / MedicationRequest) : chaque soin découle
-- d'UNE ligne, donc une seule des deux colonnes est remplie.
-- timeOfCompletion / timeRecorded remplis seulement quand le soin a été fait.
-- ---------------------------------------------------------------------------

INSERT INTO Appointment (idAppointment, idServiceRequest, idMedicationRequest, plannedTime, status, timeOfCompletion, timeRecorded, comment) VALUES
    ('SOI01', NULL, '1',  '2026-10-14 06:00:00', 'REALISE',  '2026-10-14 06:05:00', '2026-10-14 06:10:00', NULL),
    ('SOI02', NULL, '1',  '2026-10-14 12:00:00', 'REALISE',  '2026-10-14 12:20:00', '2026-10-14 12:25:00', 'Retard : patient au scanner'),
    ('SOI03', NULL, '1',  '2026-10-14 18:00:00', 'PLANIFIE', NULL,                  NULL,                  NULL),
    ('SOI04', NULL, '1',  '2026-10-15 00:00:00', 'PLANIFIE', NULL,                  NULL,                  NULL),
    ('SOI05', NULL, '2',  '2026-10-14 08:00:00', 'REALISE',  '2026-10-14 08:00:00', '2026-10-14 08:02:00', 'Débit ajusté selon PAM'),
    ('SOI06', '3',  NULL, '2026-10-12 15:00:00', 'REALISE',  '2026-10-12 15:10:00', '2026-10-12 15:15:00', NULL),
    ('SOI07', '4',  NULL, '2026-10-14 08:00:00', 'NON_FAIT', NULL,                  '2026-10-14 08:30:00', 'Prescription non vérifiée'),
    ('SOI08', '4',  NULL, '2026-10-14 12:00:00', 'PLANIFIE', NULL,                  NULL,                  NULL),
    ('SOI09', NULL, '5',  '2026-10-14 08:00:00', 'REALISE',  '2026-10-14 08:15:00', '2026-10-14 08:16:00', NULL),
    ('SOI10', NULL, '5',  '2026-10-14 20:00:00', 'PLANIFIE', NULL,                  NULL,                  NULL),
    ('SOI11', NULL, '6',  '2026-09-26 16:00:00', 'ANNULE',   NULL,                  '2026-09-26 15:00:00', 'Arrêt du traitement'),
    ('SOI12', NULL, '6',  '2026-09-27 00:00:00', 'ANNULE',   NULL,                  '2026-09-26 15:00:00', 'Arrêt du traitement'),
    ('SOI13', '2',  NULL, '2026-10-13 10:00:00', 'REALISE',  '2026-10-13 10:30:00', '2026-10-13 10:35:00', NULL),
    ('SOI14', NULL, '8',  '2026-10-16 03:15:00', 'REALISE',  '2026-10-16 03:20:00', '2026-10-16 03:25:00', 'Fièvre à 39,5 °C');