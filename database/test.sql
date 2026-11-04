-- Données de démonstration AURA (schéma AURA-16, noms HL7), 100 % fictives (aucune donnée réelle de patient).
-- À charger APRÈS schema.sql :  mariadb -u aura -p aura < database/test.sql
-- Ordre des INSERT : d'abord les tables sans clé étrangère, puis celles qui les référencent.
--
-- Jour de référence de l'agenda : mercredi 14 octobre 2026, vers 14 h (soins passés « réalisés » ou en
-- retard, soins suivants « planifiés ») ; database/demo-today.sql recale tout sur le jour courant.
-- Noms : personnages de fiction (pop culture) ; téléphones 06000…, e-mails @example.com.
-- Contenu :
--   - 3 services (SRV01 réanimation médicale, SRV02 réanimation chirurgicale, SRV03 soins continus) ;
--   - 15 praticiens : 5 médecins réanimateurs (valident), 2 internes, 5 IDE, 3 aides-soignants ;
--   - 21 patients (1 à 21) : 3 sans IPP (7, 11, 18), 3 décédés (5, 16, 17), 14 avec au moins une allergie ;
--   - 24 séjours : 16 en cours (lits et box tous distincts), 8 clos ; historique de plusieurs séjours pour
--     Marge Simpson (1, 7), Bruce Wayne (9, 24) et Sherlock Holmes (10, 20) ; référent « à désigner »
--     (NULL) pour les admissions de garde (8, 11, 16) ;
--   - 21 ordonnances, dont 4 non validées (PARAMEDICAL ou EMERGENCY : 4, 8, 11, 20), 22 lignes de
--     médicament et 21 lignes de soin, et les soins planifiés du 13 au 15 octobre (réalisés, en retard,
--     planifiés, un non fait avec motif, des annulés après arrêt de ligne) ;
--   - 4 équipes de la vacation de jour du 14 octobre en réanimation médicale (A, B, C, D).
-- Les blocs « Données complémentaires » (fin de chaque section) ont été ajoutés sans modifier les lignes
-- d'origine, sur lesquelles s'appuient les tests (tests/Dao, tests/Http).

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
INSERT INTO PractitionerRole (idPractitionerRole, title, canValidate) VALUES
    ('QUA01', 'Médecin réanimateur', TRUE),
    ('QUA02', 'Interne', FALSE),
    ('QUA03', 'Infirmier(e) diplômé(e) d''État', FALSE),
    ('QUA04', 'Aide-soignant(e)', FALSE);

-- Référentiel d'allergènes génériques (AURA-13.1). name est UNIQUE.
INSERT INTO Substance (idSubstance, name) VALUES
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

-- idSubstance : allergène du principe actif (alerte allergie de l'écran B3, retours du 2026-11-03).
-- Amoxicilline → Pénicilline (classe des pénicillines ; le patient 1 y est allergique : démo de l'alerte),
-- Céfotaxime → Céphalosporines. Une seule substance par médicament : pas de réactivité croisée.
INSERT INTO Medication (idMedication, inn, form, idSubstance) VALUES
    ('MED01', 'Paracétamol', 'Solution injectable', NULL),
    ('MED02', 'Noradrénaline', 'Solution pour perfusion', NULL),
    ('MED03', 'Héparine sodique', 'Solution injectable', NULL),
    ('MED04', 'Amoxicilline', 'Poudre pour solution injectable', 'ALG01'),
    ('MED05', 'Midazolam', 'Solution injectable', NULL),
    ('MED06', 'Céfotaxime', 'Poudre pour solution injectable', 'ALG11'),
    ('MED07', 'Chlorure de sodium 0,9 %', 'Solution pour perfusion', NULL),
    ('MED08', 'Altéplase', 'Poudre et solvant pour solution injectable', NULL);

INSERT INTO ActivityDefinition (idActivityDefinition, name, description) VALUES
    ('TYP01', 'Administration médicamenteuse', 'Administration d''un traitement prescrit'),
    ('TYP02', 'Prise des constantes', 'Pression artérielle, fréquence cardiaque, SpO2, température'),
    ('TYP03', 'Prélèvement sanguin', 'Bilan biologique sur prescription'),
    ('TYP04', 'Réfection de pansement', 'Pansement de voie centrale ou de plaie'),
    ('TYP05', 'Aspiration trachéale', 'Pour patient intubé ou trachéotomisé'),
    ('TYP06', 'Surveillance neurologique', 'Score de Glasgow, NIHSS, pupilles');

-- ---------------------------------------------------------------------------
-- Protocoles = groupes de prescriptions (dépend de Pathology, ActivityDefinition, Medication)
-- Exemples de démonstration, à faire valider par le service avant tout usage réel.
-- startOffsetHours / durationHours : en heures à partir de l'application du protocole ;
-- durationHours NULL = sans date d'arrêt.
--   PLD01 : choc septique, bundle de la première heure puis antibiothérapie 7 jours
--   PLD02 : AVC ischémique thrombolysé, surveillance rapprochée 24 h
--   PLD03 : ancien protocole retiré (active = FALSE) : ne doit PAS être proposé
-- ---------------------------------------------------------------------------

INSERT INTO Pathology (idPathology, label) VALUES
    ('PTH01', 'Sepsis et choc septique'),
    ('PTH02', 'AVC ischémique'),
    ('PTH03', 'Détresse respiratoire aiguë');

INSERT INTO PlanDefinition (idPlanDefinition, title, idPathology, active) VALUES
    ('PLD01', 'Choc septique : prise en charge initiale', 'PTH01', TRUE),
    ('PLD02', 'AVC ischémique : thrombolyse et surveillance', 'PTH02', TRUE),
    ('PLD03', 'Sédation continue (ancienne version)', 'PTH03', FALSE);

INSERT INTO PlanDefinitionAction (idPlanDefinitionAction, idPlanDefinition, kind, idActivityDefinition, idMedication, dose, route, nurseInstructions, maxDose, administrationDuration, antibioticReason, germ, startOffsetHours, durationHours, frequencyHours, timeConstraint) VALUES
    ('PDA01', 'PLD01', 'CARE',       'TYP03', NULL,    NULL,            NULL,   NULL,                                         NULL,    NULL,     NULL,     NULL,             0, NULL, NULL, 'Hémocultures et lactates, avant l''antibiotique'),
    ('PDA02', 'PLD01', 'MEDICATION', 'TYP01', 'MED07', '30 mL/kg',      'IV',   'Remplissage, réévaluer après chaque 500 mL', NULL,    '3 h',    NULL,     NULL,             0, NULL, NULL, NULL),
    ('PDA03', 'PLD01', 'MEDICATION', 'TYP01', 'MED02', '0,1 µg/kg/min', 'IVSE', NULL,                                         NULL,    NULL,     NULL,     NULL,             0, NULL, 24,   'Débit selon PAM, objectif ≥ 65 mmHg'),
    ('PDA04', 'PLD01', 'MEDICATION', 'TYP01', 'MED06', '2 g',           'IV',   NULL,                                         NULL,    '30 min', 'Sepsis', 'Non documenté',  1,  168, 8,    NULL),
    ('PDA05', 'PLD01', 'CARE',       'TYP02', NULL,    NULL,            NULL,   NULL,                                         NULL,    NULL,     NULL,     NULL,             0,  24,  1,    NULL),
    ('PDA06', 'PLD01', 'CARE',       'TYP03', NULL,    NULL,            NULL,   NULL,                                         NULL,    NULL,     NULL,     NULL,             3, NULL, NULL, 'Lactates de contrôle'),
    ('PDA07', 'PLD02', 'MEDICATION', 'TYP01', 'MED08', '0,9 mg/kg',     'IV',   '10 % en bolus sur 1 min, le reste sur 60 min', '90 mg', '60 min', NULL,     NULL,             0, NULL, NULL, NULL),
    ('PDA08', 'PLD02', 'CARE',       'TYP06', NULL,    NULL,            NULL,   NULL,                                         NULL,    NULL,     NULL,     NULL,             0,  24,  1,    NULL),
    ('PDA09', 'PLD02', 'CARE',       'TYP02', NULL,    NULL,            NULL,   NULL,                                         NULL,    NULL,     NULL,     NULL,             0,  24,  1,    'PA < 180/105 mmHg'),
    ('PDA10', 'PLD02', 'CARE',       'TYP03', NULL,    NULL,            NULL,   NULL,                                         NULL,    NULL,     NULL,     NULL,            24, NULL, NULL, 'Bilan de contrôle à H24'),
    ('PDA11', 'PLD03', 'MEDICATION', 'TYP01', 'MED05', '2 mg/h',        'IVSE', NULL,                                         NULL,    NULL,     NULL,     NULL,             0, NULL, 24,   'Seringue électrique');

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

-- Données complémentaires (personnages de fiction) : 3 médecins réanimateurs, 1 interne, 3 IDE, 2 AS.
-- Christine Chapel (PRA15) travaille en soins continus (SRV03) : elle n'est dans aucune équipe de SRV01.
INSERT INTO Practitioner (idPractitioner, idPractitionerRole, firstName, lastName, phoneNumber, email) VALUES
    ('PRA07', 'QUA01', 'Gregory',   'House',     '0600000107', 'g.house@example.com'),
    ('PRA08', 'QUA01', 'Meredith',  'Grey',      '0600000108', 'm.grey@example.com'),
    ('PRA09', 'QUA01', 'John',      'Carter',    '0600000109', 'j.carter@example.com'),
    ('PRA10', 'QUA02', 'Cristina',  'Yang',      '0600000110', 'c.yang@example.com'),
    ('PRA11', 'QUA03', 'Carla',     'Espinosa',  '0600000111', 'c.espinosa@example.com'),
    ('PRA12', 'QUA03', 'Abby',      'Lockhart',  '0600000112', 'a.lockhart@example.com'),
    ('PRA13', 'QUA04', 'Samwise',   'Gamgee',    '0600000113', NULL),
    ('PRA14', 'QUA04', 'Mary',      'Poppins',   '0600000114', 'm.poppins@example.com'),
    ('PRA15', 'QUA03', 'Christine', 'Chapel',    '0600000115', 'c.chapel@example.com');

-- ---------------------------------------------------------------------------
-- Patients (aucune dépendance)
-- idPatient et idEpisodeOfCare sont des VARCHAR mais DOIVENT contenir des entiers : les DAO
-- les lisent en int et calculent le prochain id par MAX(CAST(... AS UNSIGNED)) + 1.
-- Le patient 7 n'a pas d'IPP (ipp NULL, AURA-05) : admis en urgence, son IPP n'est
-- pas encore attribué. Le bandeau et la liste doivent afficher « sans IPP ».
-- ---------------------------------------------------------------------------

INSERT INTO Patient (idPatient, ipp, firstName, lastName, phoneNumber, email, sex, birthDate, deceased, generalPractitioner, address, bloodType) VALUES
    ('1', 100000001, 'Marge',    'Simpson',   '0600000001', 'marge.simpson@example.com', 'F', '1958-03-14', FALSE, 'Dr Lambert', '12 rue des Lilas, 69003 Lyon',                    'A+'),
    ('2', 100000002, 'Luke',     'Skywalker', '0600000002', NULL,                        'M', '1972-11-02', FALSE, 'Dr Lambert', '4 avenue Berthelot, 69007 Lyon',                  'O+'),
    ('3', 100000003, 'Hermione', 'Granger',   '0600000003', 'h.granger@example.com',     'F', '1990-06-25', FALSE, 'Dr Nguyen',  '27 cours Gambetta, 69007 Lyon',                   'B-'),
    ('4', 100000004, 'Peter',    'Parker',    NULL,         NULL,                        'M', '2001-01-09', FALSE, NULL,         '3 place Bellecour, 69002 Lyon',                   'O-'),
    ('5', 100000005, 'Jessica',  'Fletcher',  '0600000005', NULL,                        'F', '1939-08-30', TRUE,  'Dr Faure',   '8 rue Mercière, 69002 Lyon',                      'A-'),
    ('6', 100000006, 'Arsène',   'Lupin',     '0600000006', 'a.lupin@example.com',       'M', '1985-12-17', FALSE, 'Dr Nguyen',  '15 boulevard du 11 Novembre, 69100 Villeurbanne', 'AB+'),
    ('7', NULL,      'Ellen',    'Ripley',    NULL,         NULL,                        'F', '1967-04-21', FALSE, NULL,         NULL,                                              NULL);

-- Données complémentaires (personnages de fiction, adresses inventées). Sans IPP : Jason Bourne (11, retrouvé
-- en mer, identité incertaine) et Amélie Poulain (18, admise de nuit). Décédés : Jane Marple (16) et
-- Obi-Wan Kenobi (17), dont le dernier séjour est clos.
INSERT INTO Patient (idPatient, ipp, firstName, lastName, phoneNumber, email, sex, birthDate, deceased, generalPractitioner, address, bloodType) VALUES
    ('8',  100000008, 'Bruce',     'Wayne',      '0600000008', 'b.wayne@example.com',     'M', '1972-02-19', FALSE, 'Dr Lambert', '1 montée du Manoir, 69005 Lyon',            'AB-'),
    ('9',  100000009, 'Lara',      'Croft',      '0600000009', 'l.croft@example.com',     'F', '1992-02-14', FALSE, 'Dr Nguyen',  '22 quai des Explorateurs, 69002 Lyon',      'O+'),
    ('10', 100000010, 'Sherlock',  'Holmes',     '0600000010', 's.holmes@example.com',    'M', '1954-01-06', FALSE, 'Dr Faure',   '221 rue du Boulanger, 69006 Lyon',          'A+'),
    ('11', NULL,      'Jason',     'Bourne',     NULL,         NULL,                      'M', '1970-09-13', FALSE, NULL,         NULL,                                        NULL),
    ('12', 100000012, 'Daenerys',  'Targaryen',  '0600000012', NULL,                      'F', '1996-05-23', FALSE, 'Dr Nguyen',  '3 impasse des Dragons, 69009 Lyon',         'B+'),
    ('13', 100000013, 'Albus',     'Dumbledore', '0600000013', 'a.dumbledore@example.com','M', '1938-07-01', FALSE, 'Dr Lambert', '9 chemin du Château, 69110 Sainte-Foy-lès-Lyon', 'A+'),
    ('14', 100000014, 'Leia',      'Organa',     '0600000014', 'l.organa@example.com',    'F', '1977-05-25', FALSE, 'Dr Faure',   '77 avenue de l''Alliance, 69008 Lyon',      'O-'),
    ('15', 100000015, 'Marty',     'McFly',      '0600000015', NULL,                      'M', '1968-06-12', FALSE, NULL,         '9303 allée des Pins, 69500 Bron',           'A-'),
    ('16', 100000016, 'Jane',      'Marple',     '0600000016', NULL,                      'F', '1932-09-15', TRUE,  'Dr Faure',   '2 place du Village, 69570 Dardilly',        'B+'),
    ('17', 100000017, 'Obi-Wan',   'Kenobi',     '0600000017', NULL,                      'M', '1955-03-26', TRUE,  'Dr Lambert', '1 route des Dunes, 69200 Vénissieux',       'O+'),
    ('18', NULL,      'Amélie',    'Poulain',    NULL,         NULL,                      'F', '1973-06-03', FALSE, NULL,         NULL,                                        NULL),
    ('19', 100000019, 'Tony',      'Stark',      '0600000019', 't.stark@example.com',     'M', '1970-05-29', FALSE, 'Dr Nguyen',  '10880 boulevard de la Côte, 69300 Caluire-et-Cuire', 'AB+'),
    ('20', 100000020, 'Katniss',   'Everdeen',   '0600000020', NULL,                      'F', '2008-05-08', FALSE, NULL,         '12 rue du District, 69100 Villeurbanne',    'O+'),
    ('21', 100000021, 'Hercule',   'Poirot',     '0600000021', 'h.poirot@example.com',    'M', '1940-01-20', FALSE, 'Dr Lambert', '56 rue des Petites Cellules Grises, 69003 Lyon', 'A-');

-- Quelques champs complémentaires des nouveaux patients (personne de confiance, antécédents, traitement).
UPDATE Patient SET trustedPersonName = 'Alfred Pennyworth (majordome)', trustedPersonPhone = '06 00 00 00 81',
    surgicalHistory = 'Ostéosynthèse de clavicule (2019) ; fractures costales (novembre 2025).'
    WHERE idPatient = '8';
UPDATE Patient SET trustedPersonName = 'Winston (majordome)', trustedPersonPhone = '06 00 00 00 91'
    WHERE idPatient = '9';
UPDATE Patient SET trustedPersonName = 'John Watson (ami, médecin)', trustedPersonPhone = '06 00 00 01 01',
    toxicHistory = 'Tabagisme actif (pipe), 40 paquets-années.'
    WHERE idPatient = '10';
UPDATE Patient SET allergyNote = 'Allergies déclarées par l''entourage à l''admission, à confirmer avec la patiente.'
    WHERE idPatient = '12';
UPDATE Patient SET trustedPersonName = 'Aberforth Dumbledore (frère)', trustedPersonPhone = '06 00 00 01 31',
    medicalHistory = 'Insuffisance rénale chronique stade 3b.\nHTA.',
    currentTreatment = 'Furosémide 40 mg/j ; ramipril 5 mg/j.'
    WHERE idPatient = '13';
UPDATE Patient SET trustedPersonName = 'Pepper Potts (épouse)', trustedPersonPhone = '06 00 00 01 91',
    medicalHistory = 'Cardiopathie ischémique.\nÉclats métalliques intrathoraciques (ancien traumatisme).'
    WHERE idPatient = '19';
UPDATE Patient SET trustedPersonName = 'Primrose Everdeen (sœur)', trustedPersonPhone = '06 00 00 02 01'
    WHERE idPatient = '20';

-- ---------------------------------------------------------------------------
-- Séjours (dépend de Patient, HealthcareService et Practitioner)
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

-- Médecin référent (responsable de soin, retours du 2026-11-03) : un médecin réanimateur (PRA01, PRA02) pour
-- la plupart des séjours. Le séjour 8 (Ellen Ripley, admise à 02 h 30 pendant une garde) reste « à désigner »
-- (NULL) : un médecin de jour le reprendra depuis le dossier.
UPDATE EpisodeOfCare SET idReferringPractitioner = 'PRA01' WHERE idEpisodeOfCare IN ('1', '3', '5', '7');
UPDATE EpisodeOfCare SET idReferringPractitioner = 'PRA02' WHERE idEpisodeOfCare IN ('2', '4', '6');

-- Données complémentaires : 11 séjours en cours (9 à 19, surtout en réanimation médicale) et 5 séjours clos
-- (20 à 24). Lits et box des séjours en cours tous distincts, entre eux et avec les séjours 1, 2, 3, 6, 8
-- (EpisodeOfCareService refuse un lit occupé). Référent NULL = « à désigner » pour les deux admissions de
-- garde (11 : Jason Bourne à 23 h 50 ; 16 : Amélie Poulain à 5 h 10).
-- Séjours clos : 20 = séjour antérieur de Sherlock Holmes, 24 = séjour 2025 de Bruce Wayne (historique),
-- 21 et 22 = derniers séjours de Jane Marple et d'Obi-Wan Kenobi (décédés : dischargeDate = date du décès).
INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, idHealthcareService, episodeNumber, admissionDate, dischargeDate, bedNumber, boxNumber, admissionReason, weight, height, precautions, idReferringPractitioner) VALUES
    ('9',  '8',  'SRV01', '2026-000109', '2026-10-12 19:20:00', NULL,                  'L03', 'B03', 'Traumatisme thoracique fermé (chute de grande hauteur), hémopneumothorax gauche drainé', 92.00, 188.00, 'Risque de chute.',                                                  'PRA07'),
    ('10', '10', 'SRV01', '2026-000110', '2026-10-13 07:45:00', NULL,                  'L10', 'B10', 'Pneumopathie d''inhalation, intubé-ventilé',                                            68.00, 183.00, 'Isolement air (suspicion de tuberculose, prélèvements en cours).', 'PRA08'),
    ('11', '11', 'SRV01', '2026-000111', '2026-10-13 23:50:00', NULL,                  'L11', 'B11', 'Hypothermie sévère après noyade (repêché en mer)',                                      NULL,  NULL,   'Identité incertaine : identitovigilance renforcée.',                 NULL),
    ('12', '12', 'SRV01', '2026-000112', '2026-10-11 16:30:00', NULL,                  'L12', 'B12', 'SDRA après inhalation de fumées d''incendie',                                           58.00, 165.00, 'Décubitus ventral selon prescription médicale.',                     'PRA01'),
    ('13', '13', 'SRV01', '2026-000113', '2026-10-09 11:00:00', NULL,                  'L13', 'B13', 'Insuffisance rénale aiguë avec hyperkaliémie menaçante',                                71.00, 190.00, 'Risque de chute. Escarre talonnière droite.',                         'PRA02'),
    ('14', '19', 'SRV01', '2026-000114', '2026-10-12 03:15:00', NULL,                  'L14', 'B14', 'Choc cardiogénique sur infarctus antérieur',                                           82.00, 185.00, 'Éclats métalliques intrathoraciques : IRM contre-indiquée.',          'PRA07'),
    ('15', '20', 'SRV01', '2026-000115', '2026-10-13 14:20:00', NULL,                  'L15', 'B15', 'Choc septique sur plaie infectée de la jambe gauche',                                  52.00, 170.00, 'Allergie à la chlorhexidine : antisepsie à la povidone iodée.',       'PRA08'),
    ('16', '18', 'SRV01', '2026-000116', '2026-10-14 05:10:00', NULL,                  'L16', 'B16', 'État de mal épileptique inaugural',                                                   NULL,  NULL,   'Risque de chute. Barrières de lit relevées.',                         NULL),
    ('17', '9',  'SRV02', '2026-000117', '2026-10-13 10:30:00', NULL,                  'L06', 'B06', 'Fracture ouverte de jambe droite (chute en escalade), post-opératoire',               60.00, 175.00, 'Pas d''appui sur la jambe droite.',                                   'PRA09'),
    ('18', '14', 'SRV03', '2026-000118', '2026-10-12 09:40:00', NULL,                  'L08', 'B08', 'Embolie pulmonaire bilatérale sans état de choc',                                     55.00, 150.00, NULL,                                                                  'PRA09'),
    ('19', '21', 'SRV02', '2026-000119', '2026-10-14 08:30:00', NULL,                  'L07', 'B07', 'Surveillance après colectomie pour occlusion',                                        74.00, 164.00, 'À jeun jusqu''à avis chirurgical.',                                   'PRA02'),
    ('20', '10', 'SRV03', '2026-000120', '2026-03-02 14:30:00', '2026-03-06 10:00:00', 'L09', 'B09', 'Séjour antérieur : traumatisme crânien léger (chute dans un escalier)',                NULL,  NULL,   NULL,                                                                  'PRA01'),
    ('21', '16', 'SRV01', '2026-000121', '2026-10-02 16:45:00', '2026-10-09 04:20:00', 'L03', 'B03', 'Choc cardiogénique',                                                                  48.00, 158.00, NULL,                                                                  'PRA01'),
    ('22', '17', 'SRV01', '2026-000122', '2026-09-12 21:10:00', '2026-09-18 15:30:00', 'L02', 'B02', 'Arrêt cardiaque extrahospitalier, pronostic neurologique défavorable',                NULL,  NULL,   NULL,                                                                  'PRA02'),
    ('23', '15', 'SRV02', '2026-000123', '2026-08-20 18:00:00', '2026-08-25 11:00:00', 'L05', 'B05', 'Traumatisme crânien (chute de skateboard)',                                           70.00, 173.00, NULL,                                                                  'PRA09'),
    ('24', '8',  'SRV02', '2025-000431', '2025-11-05 09:30:00', '2025-11-12 14:00:00', 'L06', 'B06', 'Séjour antérieur : fractures costales multiples',                                     90.00, 188.00, NULL,                                                                  'PRA02');

-- Champs complémentaires de deux séjours en cours (scores contrôlés par Aura\Domain\ClinicalScales).
UPDATE EpisodeOfCare SET
    diseaseHistory1 = 'Douleur thoracique à 2 h du matin, sus-décalage antérieur, choc à l''arrivée du SMUR.',
    asaScore = 'IV',
    nyhaClass = 'IV',
    intermacsProfile = '2',
    materialLeftInPlace = 'Éclats métalliques intrathoraciques (non extractibles).',
    completedBy = 'Dr House (admission)'
WHERE idEpisodeOfCare = '14';
UPDATE EpisodeOfCare SET
    specialStatus = 'Identité incertaine',
    diseaseHistory1 = 'Repêché inconscient par un chalutier, température centrale 28 °C à la prise en charge.',
    completedBy = 'Dr Benali (garde)'
WHERE idEpisodeOfCare = '11';

-- Champs complémentaires (PR « admission-champs-complets ») : quelques valeurs sur le patient 1 et son
-- séjour en cours pour que le dossier montre des sections renseignées ; les autres restent « Non renseigné ».
UPDATE Patient SET
    birthLastName = 'Durand',
    generalPractitioner = 'Dr Lambert',
    trustedPersonName = 'Homer Simpson (époux)',
    trustedPersonPhone = '06 00 00 00 11',
    medicalHistory = 'HTA traitée depuis 2015.\nBPCO stade II (tabagisme sevré en 2019).',
    surgicalHistory = 'Cholécystectomie (2008).',
    currentTreatment = 'Amlodipine 5 mg/j ; tiotropium 18 µg/j.'
WHERE idPatient = '1';

UPDATE EpisodeOfCare SET
    diseaseHistory1 = 'Dyspnée croissante depuis 4 jours, fièvre à 39 °C, SpO2 86 % en air ambiant aux urgences.',
    asaScore = 'III',
    nyhaClass = 'II',
    mcCabeScore = '1',
    knausScore = 'B',
    girScore = '5',
    removableProstheses = 'Lunettes ; prothèse dentaire haut',
    completedBy = 'Dr Morel (admission)'
WHERE idEpisodeOfCare = '1';

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

-- Données complémentaires. criticality NULL = non évaluée (allergie 14). L'allergie 6 de Bruce Wayne date
-- de son séjour 2025 ; la 8 de Sherlock Holmes de son séjour de mars. Aucun médicament prescrit aux
-- nouveaux patients ne contient un de leurs allergènes (Albus Dumbledore : pas de céfotaxime).
INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance, manifestation, criticality, recordedAt) VALUES
    ('6',  '8',  'ALG02', 'Urticaire de contact',                       'Faible', '2025-11-05 10:00:00'),
    ('7',  '9',  'ALG03', 'Choc anaphylactique (produit de contraste)', 'Élevée', '2026-10-13 10:45:00'),
    ('8',  '10', 'ALG09', 'Vomissements',                               'Faible', '2026-03-02 15:00:00'),
    ('9',  '12', 'ALG10', 'Syndrome de Lyell',                          'Élevée', '2026-10-11 16:45:00'),
    ('10', '12', 'ALG04', 'Œdème des lèvres',                           'Faible', '2026-10-11 16:45:00'),
    ('11', '13', 'ALG11', 'Éruption maculo-papuleuse',                  'Élevée', '2026-10-09 11:20:00'),
    ('12', '19', 'ALG07', 'Bronchospasme',                              'Élevée', '2026-10-12 03:40:00'),
    ('13', '19', 'ALG08', 'Bronchospasme',                              'Élevée', '2026-10-12 03:40:00'),
    ('14', '20', 'ALG15', 'Eczéma de contact',                          NULL,     '2026-10-13 14:40:00'),
    ('15', '18', 'ALG12', 'Urticaire',                                  'Faible', '2026-10-14 05:30:00'),
    ('16', '21', 'ALG14', 'Prurit buccal',                              'Faible', '2026-10-14 08:45:00'),
    ('17', '16', 'ALG06', 'Éruption cutanée',                           'Élevée', '2026-10-02 18:00:00');

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

-- Note libre de l'ordonnance (retours du 2026-11-03) : affichée dans la liste des prescriptions et dans
-- « Prescriptions en cours » du dossier. Les autres ordonnances n'en ont pas (NULL).
UPDATE CarePlan SET note = 'Réévaluer le bilan biologique à J3 ; prévenir le médecin si fièvre > 38,5 °C.'
    WHERE idCarePlan = '1';
UPDATE CarePlan SET note = 'Surveillance rapprochée demandée par le chirurgien jusqu''à J7.'
    WHERE idCarePlan = '4';

-- Données complémentaires : une ordonnance validée par séjour en cours (9 à 19), plus :
--   10 : saisie par l'interne Cristina Yang, validée ensuite par la Dr Grey (RG5)
--   11 : IDE de garde, mode EMERGENCY, « non validée » (soluté réchauffé et constantes de Jason Bourne)
--   12 : même séjour, ordonnance du médecin de garde
--   16 : issue du protocole PLD01 (choc septique), lignes adaptées (constantes toutes les 4 h)
--   20 : IDE de soins continus, mode PARAMEDICAL, « non validée »
INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, recordedAt, idValidator, validatedAt, idPlanDefinition, note) VALUES
    ('9',  '9',  'PRA07', 'MEDICAL',     '2026-10-12 20:00:00', 'PRA07', '2026-10-12 20:00:00', NULL,    'Radio de thorax de contrôle après ablation du drain.'),
    ('10', '10', 'PRA10', 'MEDICAL',     '2026-10-13 08:30:00', 'PRA08', '2026-10-13 09:15:00', NULL,    NULL),
    ('11', '11', 'PRA11', 'EMERGENCY',   '2026-10-14 00:10:00', NULL,    NULL,                  NULL,    'Prescription d''urgence en attente de validation médicale.'),
    ('12', '11', 'PRA02', 'MEDICAL',     '2026-10-14 01:00:00', 'PRA02', '2026-10-14 01:00:00', NULL,    NULL),
    ('13', '12', 'PRA01', 'MEDICAL',     '2026-10-11 17:00:00', 'PRA01', '2026-10-11 17:00:00', NULL,    NULL),
    ('14', '13', 'PRA02', 'MEDICAL',     '2026-10-09 12:00:00', 'PRA02', '2026-10-09 12:00:00', NULL,    'Allergie aux céphalosporines : pas de céfotaxime.'),
    ('15', '14', 'PRA07', 'MEDICAL',     '2026-10-12 04:00:00', 'PRA07', '2026-10-12 04:00:00', NULL,    NULL),
    ('16', '15', 'PRA08', 'MEDICAL',     '2026-10-13 14:30:00', 'PRA08', '2026-10-13 14:30:00', 'PLD01', 'Réévaluer l''antibiothérapie à 48 h selon les hémocultures.'),
    ('17', '16', 'PRA02', 'MEDICAL',     '2026-10-14 05:20:00', 'PRA02', '2026-10-14 05:20:00', NULL,    NULL),
    ('18', '17', 'PRA09', 'MEDICAL',     '2026-10-13 11:30:00', 'PRA09', '2026-10-13 11:30:00', NULL,    NULL),
    ('19', '18', 'PRA09', 'MEDICAL',     '2026-10-12 10:00:00', 'PRA09', '2026-10-12 10:00:00', NULL,    NULL),
    ('20', '18', 'PRA15', 'PARAMEDICAL', '2026-10-14 06:00:00', NULL,    NULL,                  NULL,    NULL),
    ('21', '19', 'PRA02', 'MEDICAL',     '2026-10-14 09:00:00', 'PRA02', '2026-10-14 09:00:00', NULL,    NULL);

-- ---------------------------------------------------------------------------
-- Lignes de soin (dépend de CarePlan, ActivityDefinition, Practitioner)
-- frequencyHours NULL = soin unique (RG3) ; 48 = tous les 2 jours.
-- ---------------------------------------------------------------------------

INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, endDate, frequencyHours, timeConstraint, idStoppedBy, stoppedAt) VALUES
    ('1', '1', 'TYP03', '2026-10-11 06:00:00', NULL,                  NULL, 'À jeun', NULL, NULL),
    ('2', '1', 'TYP04', '2026-10-11 10:00:00', NULL,                  48,   NULL,     NULL, NULL),
    ('3', '3', 'TYP03', '2026-10-12 15:00:00', NULL,                  NULL, NULL,     NULL, NULL),
    ('4', '4', 'TYP02', '2026-10-12 16:00:00', '2026-10-19 16:00:00', 4,    NULL,     NULL, NULL);

-- Données complémentaires (ordonnances 9 à 21).
INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, endDate, frequencyHours, timeConstraint, idStoppedBy, stoppedAt) VALUES
    ('5',  '9',  'TYP02', '2026-10-12 22:00:00', NULL,                  6,    NULL,                                              NULL, NULL),
    ('6',  '9',  'TYP04', '2026-10-13 10:00:00', NULL,                  24,   'Pansement du drain thoracique',                   NULL, NULL),
    ('7',  '10', 'TYP05', '2026-10-13 12:00:00', NULL,                  6,    NULL,                                              NULL, NULL),
    ('8',  '11', 'TYP02', '2026-10-14 00:15:00', '2026-10-15 00:15:00', 4,    'Température centrale : réchauffement ≤ 1 °C/h',   NULL, NULL),
    ('9',  '12', 'TYP06', '2026-10-14 01:00:00', '2026-10-16 01:00:00', 4,    NULL,                                              NULL, NULL),
    ('10', '13', 'TYP05', '2026-10-11 18:00:00', NULL,                  6,    NULL,                                              NULL, NULL),
    ('11', '13', 'TYP03', '2026-10-12 06:00:00', NULL,                  24,   'Gaz du sang artériel',                            NULL, NULL),
    ('12', '14', 'TYP03', '2026-10-09 18:00:00', NULL,                  12,   'Ionogramme, kaliémie de contrôle',                NULL, NULL),
    ('13', '15', 'TYP02', '2026-10-12 04:00:00', NULL,                  4,    NULL,                                              NULL, NULL),
    ('14', '16', 'TYP03', '2026-10-13 14:30:00', NULL,                  NULL, 'Hémocultures et lactates, avant l''antibiotique', NULL, NULL),
    ('15', '16', 'TYP02', '2026-10-13 14:30:00', '2026-10-16 14:30:00', 4,    NULL,                                              NULL, NULL),
    ('16', '16', 'TYP04', '2026-10-14 10:00:00', NULL,                  24,   'Antisepsie sans chlorhexidine (allergie)',        NULL, NULL),
    ('17', '17', 'TYP06', '2026-10-14 06:00:00', '2026-10-16 06:00:00', 4,    NULL,                                              NULL, NULL),
    ('18', '18', 'TYP04', '2026-10-14 10:00:00', NULL,                  24,   'Fixateur externe, jambe droite',                  NULL, NULL),
    ('19', '19', 'TYP03', '2026-10-12 16:00:00', NULL,                  12,   'Activité anti-Xa',                                NULL, NULL),
    ('20', '20', 'TYP02', '2026-10-14 06:00:00', NULL,                  6,    NULL,                                              NULL, NULL),
    ('21', '21', 'TYP02', '2026-10-14 10:00:00', '2026-10-17 10:00:00', 4,    NULL,                                              NULL, NULL);

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

-- Données complémentaires (ordonnances 9 à 21) :
--   12 : midazolam de Daenerys Targaryen arrêté le 14/10 à 9 h (fenêtre d'éveil) : ses soins suivants sont annulés
--   11, 15, 18 : administrations uniques (frequencyHours NULL) en urgence
INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition, idMedication, dose, route, important, nurseInstructions, maxDose, administrationDuration, antibioticReason, germ, renalAdjustment, adjustmentCriterion, startDate, endDate, frequencyHours, timeConstraint, idStoppedBy, stoppedAt) VALUES
    ('9',  '9',  'TYP01', 'MED01', '1 g',           'IV',   NULL,                           NULL,                                          NULL,           NULL,     NULL,                         NULL,            NULL,  NULL,                                       '2026-10-12 20:00:00', '2026-10-19 20:00:00', 6,    NULL,                                   NULL,    NULL),
    ('10', '10', 'TYP01', 'MED04', '1 g',           'IV',   NULL,                           NULL,                                          NULL,           '30 min', 'Pneumopathie d''inhalation', 'Non documenté', NULL,  NULL,                                       '2026-10-13 10:00:00', '2026-10-20 10:00:00', 8,    NULL,                                   NULL,    NULL),
    ('11', '11', 'TYP01', 'MED07', '500 mL',        'IV',   NULL,                           'Soluté réchauffé à 38 °C',                    NULL,           '30 min', NULL,                         NULL,            NULL,  NULL,                                       '2026-10-14 00:15:00', NULL,                  NULL, NULL,                                   NULL,    NULL),
    ('12', '13', 'TYP01', 'MED05', '3 mg/h',        'IVSE', NULL,                           NULL,                                          NULL,           NULL,     NULL,                         NULL,            NULL,  NULL,                                       '2026-10-11 17:00:00', NULL,                  24,   'Seringue électrique',                  'PRA01', '2026-10-14 09:00:00'),
    ('13', '14', 'TYP01', 'MED03', '5000 UI',       'SC',   NULL,                           NULL,                                          NULL,           NULL,     NULL,                         NULL,            'Oui', 'Clairance de la créatinine < 30 mL/min', '2026-10-09 20:00:00', NULL,                  12,   '8 h et 20 h',                          NULL,    NULL),
    ('14', '15', 'TYP01', 'MED02', '0,3 µg/kg/min', 'IVSE', 'Voie veineuse centrale dédiée', NULL,                                          '1 µg/kg/min',  NULL,     NULL,                         NULL,            NULL,  NULL,                                       '2026-10-12 04:00:00', NULL,                  24,   'Débit selon PAM, objectif ≥ 65 mmHg', NULL,    NULL),
    ('15', '16', 'TYP01', 'MED07', '30 mL/kg',      'IV',   NULL,                           'Remplissage, réévaluer après chaque 500 mL', NULL,           '3 h',    NULL,                         NULL,            NULL,  NULL,                                       '2026-10-13 14:30:00', NULL,                  NULL, NULL,                                   NULL,    NULL),
    ('16', '16', 'TYP01', 'MED02', '0,1 µg/kg/min', 'IVSE', NULL,                           NULL,                                          NULL,           NULL,     NULL,                         NULL,            NULL,  NULL,                                       '2026-10-13 14:30:00', NULL,                  24,   'Débit selon PAM, objectif ≥ 65 mmHg', NULL,    NULL),
    ('17', '16', 'TYP01', 'MED06', '2 g',           'IV',   NULL,                           NULL,                                          NULL,           '30 min', 'Sepsis',                     'Non documenté', NULL,  NULL,                                       '2026-10-13 15:30:00', '2026-10-20 15:30:00', 8,    NULL,                                   NULL,    NULL),
    ('18', '17', 'TYP01', 'MED05', '10 mg',         'IV',   'Si crise > 5 min',             'Injection lente, surveiller la ventilation', NULL,           NULL,     NULL,                         NULL,            NULL,  NULL,                                       '2026-10-14 05:25:00', NULL,                  NULL, NULL,                                   NULL,    NULL),
    ('19', '18', 'TYP01', 'MED01', '1 g',           'IV',   NULL,                           NULL,                                          '4 g/24 h',     NULL,     NULL,                         NULL,            NULL,  NULL,                                       '2026-10-13 12:00:00', '2026-10-18 12:00:00', 6,    NULL,                                   NULL,    NULL),
    ('20', '18', 'TYP01', 'MED03', '5000 UI',       'SC',   NULL,                           NULL,                                          NULL,           NULL,     NULL,                         NULL,            NULL,  NULL,                                       '2026-10-13 20:00:00', NULL,                  12,   '8 h et 20 h',                          NULL,    NULL),
    ('21', '19', 'TYP01', 'MED03', '18 UI/kg/h',    'IVSE', NULL,                           NULL,                                          NULL,           NULL,     NULL,                         NULL,            NULL,  'Adapter selon l''anti-Xa (cible 0,3 à 0,7)', '2026-10-12 10:00:00', NULL,              24,   'Seringue électrique',                  NULL,    NULL),
    ('22', '21', 'TYP01', 'MED01', '1 g',           'IV',   NULL,                           NULL,                                          '4 g/24 h',     NULL,     NULL,                         NULL,            NULL,  NULL,                                       '2026-10-14 12:00:00', '2026-10-19 12:00:00', 6,    NULL,                                   NULL,    NULL);

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

-- Données complémentaires : soins des lignes 5 à 21 (soin) et 9 à 22 (médicament) du 13 au 15 octobre,
-- un par heure prévue de chaque ligne active. Jour de référence le 14 vers 14 h : avant, « réalisé » (heure
-- de réalisation renseignée) sauf cinq retards laissés « planifiés » (SOI93, SOI100, SOI104, SOI105, SOI107)
-- et un « non fait » avec motif (SOI97) ; après, « planifié ». SOI118 et SOI200 : annulés par l'arrêt du
-- midazolam (ligne 12). Les soins des ordonnances non validées 11 (urgence) et 20 sont aussi présents.
INSERT INTO Appointment (idAppointment, idServiceRequest, idMedicationRequest, plannedTime, status, timeOfCompletion, timeRecorded, comment) VALUES
    ('SOI15',  '10', NULL, '2026-10-13 00:00:00', 'REALISE' , '2026-10-13 00:00:00', '2026-10-13 00:03:00', NULL),
    ('SOI16',  '13', NULL, '2026-10-13 00:00:00', 'REALISE' , '2026-10-13 00:05:00', '2026-10-13 00:08:00', NULL),
    ('SOI17',  NULL, '9',  '2026-10-13 02:00:00', 'REALISE' , '2026-10-13 02:10:00', '2026-10-13 02:13:00', NULL),
    ('SOI18',  NULL, '14', '2026-10-13 04:00:00', 'REALISE' , '2026-10-13 04:15:00', '2026-10-13 04:18:00', NULL),
    ('SOI19',  '5',  NULL, '2026-10-13 04:00:00', 'REALISE' , '2026-10-13 04:20:00', '2026-10-13 04:23:00', NULL),
    ('SOI20',  '13', NULL, '2026-10-13 04:00:00', 'REALISE' , '2026-10-13 04:05:00', '2026-10-13 04:08:00', NULL),
    ('SOI21',  '19', NULL, '2026-10-13 04:00:00', 'REALISE' , '2026-10-13 04:00:00', '2026-10-13 04:03:00', NULL),
    ('SOI22',  '10', NULL, '2026-10-13 06:00:00', 'REALISE' , '2026-10-13 06:10:00', '2026-10-13 06:13:00', NULL),
    ('SOI23',  '11', NULL, '2026-10-13 06:00:00', 'REALISE' , '2026-10-13 06:00:00', '2026-10-13 06:03:00', NULL),
    ('SOI24',  '12', NULL, '2026-10-13 06:00:00', 'REALISE' , '2026-10-13 06:05:00', '2026-10-13 06:08:00', NULL),
    ('SOI25',  NULL, '9',  '2026-10-13 08:00:00', 'REALISE' , '2026-10-13 08:10:00', '2026-10-13 08:13:00', NULL),
    ('SOI26',  NULL, '13', '2026-10-13 08:00:00', 'REALISE' , '2026-10-13 08:15:00', '2026-10-13 08:18:00', NULL),
    ('SOI27',  '13', NULL, '2026-10-13 08:00:00', 'REALISE' , '2026-10-13 08:20:00', '2026-10-13 08:23:00', NULL),
    ('SOI28',  NULL, '10', '2026-10-13 10:00:00', 'REALISE' , '2026-10-13 10:05:00', '2026-10-13 10:08:00', NULL),
    ('SOI29',  NULL, '21', '2026-10-13 10:00:00', 'REALISE' , '2026-10-13 10:00:00', '2026-10-13 10:03:00', NULL),
    ('SOI30',  '5',  NULL, '2026-10-13 10:00:00', 'REALISE' , '2026-10-13 10:10:00', '2026-10-13 10:13:00', NULL),
    ('SOI31',  '6',  NULL, '2026-10-13 10:00:00', 'REALISE' , '2026-10-13 10:00:00', '2026-10-13 10:03:00', NULL),
    ('SOI32',  NULL, '19', '2026-10-13 12:00:00', 'REALISE' , '2026-10-13 12:05:00', '2026-10-13 12:08:00', NULL),
    ('SOI33',  '7',  NULL, '2026-10-13 12:00:00', 'REALISE' , '2026-10-13 12:10:00', '2026-10-13 12:13:00', NULL),
    ('SOI34',  '10', NULL, '2026-10-13 12:00:00', 'REALISE' , '2026-10-13 12:15:00', '2026-10-13 12:18:00', NULL),
    ('SOI35',  '13', NULL, '2026-10-13 12:00:00', 'REALISE' , '2026-10-13 12:20:00', '2026-10-13 12:23:00', NULL),
    ('SOI36',  NULL, '9',  '2026-10-13 14:00:00', 'REALISE' , '2026-10-13 14:05:00', '2026-10-13 14:08:00', NULL),
    ('SOI37',  NULL, '15', '2026-10-13 14:30:00', 'REALISE' , '2026-10-13 14:30:00', '2026-10-13 14:33:00', NULL),
    ('SOI38',  NULL, '16', '2026-10-13 14:30:00', 'REALISE' , '2026-10-13 14:40:00', '2026-10-13 14:43:00', NULL),
    ('SOI39',  '14', NULL, '2026-10-13 14:30:00', 'REALISE' , '2026-10-13 14:30:00', '2026-10-13 14:33:00', 'Lactates 4,1 mmol/L'),
    ('SOI40',  '15', NULL, '2026-10-13 14:30:00', 'REALISE' , '2026-10-13 14:35:00', '2026-10-13 14:38:00', NULL),
    ('SOI41',  NULL, '17', '2026-10-13 15:30:00', 'REALISE' , '2026-10-13 15:40:00', '2026-10-13 15:43:00', NULL),
    ('SOI42',  '5',  NULL, '2026-10-13 16:00:00', 'REALISE' , '2026-10-13 16:15:00', '2026-10-13 16:18:00', NULL),
    ('SOI43',  '13', NULL, '2026-10-13 16:00:00', 'REALISE' , '2026-10-13 16:20:00', '2026-10-13 16:23:00', NULL),
    ('SOI44',  '19', NULL, '2026-10-13 16:00:00', 'REALISE' , '2026-10-13 16:05:00', '2026-10-13 16:08:00', NULL),
    ('SOI45',  NULL, '12', '2026-10-13 17:00:00', 'REALISE' , '2026-10-13 17:00:00', '2026-10-13 17:03:00', NULL),
    ('SOI46',  NULL, '10', '2026-10-13 18:00:00', 'REALISE' , '2026-10-13 18:10:00', '2026-10-13 18:13:00', NULL),
    ('SOI47',  NULL, '19', '2026-10-13 18:00:00', 'REALISE' , '2026-10-13 18:00:00', '2026-10-13 18:03:00', NULL),
    ('SOI48',  '7',  NULL, '2026-10-13 18:00:00', 'REALISE' , '2026-10-13 18:05:00', '2026-10-13 18:08:00', NULL),
    ('SOI49',  '10', NULL, '2026-10-13 18:00:00', 'REALISE' , '2026-10-13 18:10:00', '2026-10-13 18:13:00', NULL),
    ('SOI50',  '12', NULL, '2026-10-13 18:00:00', 'REALISE' , '2026-10-13 18:15:00', '2026-10-13 18:18:00', NULL),
    ('SOI51',  '15', NULL, '2026-10-13 18:30:00', 'REALISE' , '2026-10-13 18:50:00', '2026-10-13 18:53:00', NULL),
    ('SOI52',  NULL, '9',  '2026-10-13 20:00:00', 'REALISE' , '2026-10-13 20:05:00', '2026-10-13 20:08:00', NULL),
    ('SOI53',  NULL, '13', '2026-10-13 20:00:00', 'REALISE' , '2026-10-13 20:00:00', '2026-10-13 20:03:00', NULL),
    ('SOI54',  NULL, '20', '2026-10-13 20:00:00', 'REALISE' , '2026-10-13 20:10:00', '2026-10-13 20:13:00', NULL),
    ('SOI55',  '13', NULL, '2026-10-13 20:00:00', 'REALISE' , '2026-10-13 20:00:00', '2026-10-13 20:03:00', NULL),
    ('SOI56',  '5',  NULL, '2026-10-13 22:00:00', 'REALISE' , '2026-10-13 22:05:00', '2026-10-13 22:08:00', NULL),
    ('SOI57',  '15', NULL, '2026-10-13 22:30:00', 'REALISE' , '2026-10-13 22:40:00', '2026-10-13 22:43:00', NULL),
    ('SOI58',  NULL, '17', '2026-10-13 23:30:00', 'REALISE' , '2026-10-13 23:45:00', '2026-10-13 23:48:00', NULL),
    ('SOI59',  NULL, '19', '2026-10-14 00:00:00', 'REALISE' , '2026-10-14 00:20:00', '2026-10-14 00:23:00', NULL),
    ('SOI60',  '7',  NULL, '2026-10-14 00:00:00', 'REALISE' , '2026-10-14 00:05:00', '2026-10-14 00:08:00', NULL),
    ('SOI61',  '10', NULL, '2026-10-14 00:00:00', 'REALISE' , '2026-10-14 00:00:00', '2026-10-14 00:03:00', NULL),
    ('SOI62',  '13', NULL, '2026-10-14 00:00:00', 'REALISE' , '2026-10-14 00:10:00', '2026-10-14 00:13:00', NULL),
    ('SOI63',  NULL, '11', '2026-10-14 00:15:00', 'REALISE' , '2026-10-14 00:15:00', '2026-10-14 00:18:00', 'Soluté réchauffé à 38 °C'),
    ('SOI64',  '8',  NULL, '2026-10-14 00:15:00', 'REALISE' , '2026-10-14 00:20:00', '2026-10-14 00:23:00', NULL),
    ('SOI65',  '9',  NULL, '2026-10-14 01:00:00', 'REALISE' , '2026-10-14 01:10:00', '2026-10-14 01:13:00', NULL),
    ('SOI66',  NULL, '9',  '2026-10-14 02:00:00', 'REALISE' , '2026-10-14 02:15:00', '2026-10-14 02:18:00', NULL),
    ('SOI67',  NULL, '10', '2026-10-14 02:00:00', 'REALISE' , '2026-10-14 02:20:00', '2026-10-14 02:23:00', NULL),
    ('SOI68',  '15', NULL, '2026-10-14 02:30:00', 'REALISE' , '2026-10-14 02:35:00', '2026-10-14 02:38:00', NULL),
    ('SOI69',  NULL, '14', '2026-10-14 04:00:00', 'REALISE' , '2026-10-14 04:00:00', '2026-10-14 04:03:00', NULL),
    ('SOI70',  '5',  NULL, '2026-10-14 04:00:00', 'REALISE' , '2026-10-14 04:10:00', '2026-10-14 04:13:00', NULL),
    ('SOI71',  '13', NULL, '2026-10-14 04:00:00', 'REALISE' , '2026-10-14 04:00:00', '2026-10-14 04:03:00', NULL),
    ('SOI72',  '19', NULL, '2026-10-14 04:00:00', 'REALISE' , '2026-10-14 04:05:00', '2026-10-14 04:08:00', NULL),
    ('SOI73',  '8',  NULL, '2026-10-14 04:15:00', 'REALISE' , '2026-10-14 04:25:00', '2026-10-14 04:28:00', NULL),
    ('SOI74',  '9',  NULL, '2026-10-14 05:00:00', 'REALISE' , '2026-10-14 05:15:00', '2026-10-14 05:18:00', NULL),
    ('SOI75',  NULL, '18', '2026-10-14 05:25:00', 'REALISE' , '2026-10-14 05:25:00', '2026-10-14 05:28:00', 'Crise arrêtée en 2 min'),
    ('SOI76',  NULL, '19', '2026-10-14 06:00:00', 'REALISE' , '2026-10-14 06:05:00', '2026-10-14 06:08:00', NULL),
    ('SOI77',  '7',  NULL, '2026-10-14 06:00:00', 'REALISE' , '2026-10-14 06:00:00', '2026-10-14 06:03:00', NULL),
    ('SOI78',  '10', NULL, '2026-10-14 06:00:00', 'REALISE' , '2026-10-14 06:10:00', '2026-10-14 06:13:00', NULL),
    ('SOI79',  '11', NULL, '2026-10-14 06:00:00', 'REALISE' , '2026-10-14 06:00:00', '2026-10-14 06:03:00', NULL),
    ('SOI80',  '12', NULL, '2026-10-14 06:00:00', 'REALISE' , '2026-10-14 06:05:00', '2026-10-14 06:08:00', NULL),
    ('SOI81',  '17', NULL, '2026-10-14 06:00:00', 'REALISE' , '2026-10-14 06:10:00', '2026-10-14 06:13:00', NULL),
    ('SOI82',  '20', NULL, '2026-10-14 06:00:00', 'REALISE' , '2026-10-14 06:15:00', '2026-10-14 06:18:00', NULL),
    ('SOI83',  '15', NULL, '2026-10-14 06:30:00', 'REALISE' , '2026-10-14 06:50:00', '2026-10-14 06:53:00', NULL),
    ('SOI84',  NULL, '17', '2026-10-14 07:30:00', 'REALISE' , '2026-10-14 08:15:00', '2026-10-14 08:18:00', 'Retard : pharmacie'),
    ('SOI85',  NULL, '9',  '2026-10-14 08:00:00', 'REALISE' , '2026-10-14 08:00:00', '2026-10-14 08:03:00', NULL),
    ('SOI86',  NULL, '13', '2026-10-14 08:00:00', 'REALISE' , '2026-10-14 08:10:00', '2026-10-14 08:13:00', NULL),
    ('SOI87',  NULL, '20', '2026-10-14 08:00:00', 'REALISE' , '2026-10-14 08:00:00', '2026-10-14 08:03:00', NULL),
    ('SOI88',  '13', NULL, '2026-10-14 08:00:00', 'REALISE' , '2026-10-14 08:05:00', '2026-10-14 08:08:00', NULL),
    ('SOI89',  '8',  NULL, '2026-10-14 08:15:00', 'REALISE' , '2026-10-14 08:25:00', '2026-10-14 08:28:00', 'Température centrale 35,2 °C'),
    ('SOI90',  '9',  NULL, '2026-10-14 09:00:00', 'REALISE' , '2026-10-14 09:15:00', '2026-10-14 09:18:00', NULL),
    ('SOI91',  NULL, '10', '2026-10-14 10:00:00', 'REALISE' , '2026-10-14 10:20:00', '2026-10-14 10:23:00', NULL),
    ('SOI92',  NULL, '21', '2026-10-14 10:00:00', 'REALISE' , '2026-10-14 10:05:00', '2026-10-14 10:08:00', NULL),
    ('SOI93',  '5',  NULL, '2026-10-14 10:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI94',  '6',  NULL, '2026-10-14 10:00:00', 'REALISE' , '2026-10-14 10:10:00', '2026-10-14 10:13:00', NULL),
    ('SOI95',  '16', NULL, '2026-10-14 10:00:00', 'REALISE' , '2026-10-14 10:00:00', '2026-10-14 10:03:00', NULL),
    ('SOI96',  '17', NULL, '2026-10-14 10:00:00', 'REALISE' , '2026-10-14 10:05:00', '2026-10-14 10:08:00', NULL),
    ('SOI97',  '18', NULL, '2026-10-14 10:00:00', 'NON_FAIT', NULL                 , '2026-10-14 10:20:00', 'Patiente au bloc opératoire'),
    ('SOI98',  '21', NULL, '2026-10-14 10:00:00', 'REALISE' , '2026-10-14 10:15:00', '2026-10-14 10:18:00', NULL),
    ('SOI99',  '15', NULL, '2026-10-14 10:30:00', 'REALISE' , '2026-10-14 10:50:00', '2026-10-14 10:53:00', NULL),
    ('SOI100', NULL, '19', '2026-10-14 12:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI101', NULL, '22', '2026-10-14 12:00:00', 'REALISE' , '2026-10-14 12:00:00', '2026-10-14 12:03:00', NULL),
    ('SOI102', '7',  NULL, '2026-10-14 12:00:00', 'REALISE' , '2026-10-14 12:10:00', '2026-10-14 12:13:00', NULL),
    ('SOI103', '10', NULL, '2026-10-14 12:00:00', 'REALISE' , '2026-10-14 12:00:00', '2026-10-14 12:03:00', NULL),
    ('SOI104', '13', NULL, '2026-10-14 12:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI105', '20', NULL, '2026-10-14 12:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI106', '8',  NULL, '2026-10-14 12:15:00', 'REALISE' , '2026-10-14 12:30:00', '2026-10-14 12:33:00', NULL),
    ('SOI107', '9',  NULL, '2026-10-14 13:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI108', NULL, '9',  '2026-10-14 14:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI109', '17', NULL, '2026-10-14 14:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI110', '21', NULL, '2026-10-14 14:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI111', NULL, '16', '2026-10-14 14:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI112', '15', NULL, '2026-10-14 14:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI113', NULL, '17', '2026-10-14 15:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI114', '5',  NULL, '2026-10-14 16:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI115', '13', NULL, '2026-10-14 16:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI116', '19', NULL, '2026-10-14 16:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI117', '8',  NULL, '2026-10-14 16:15:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI118', NULL, '12', '2026-10-14 17:00:00', 'ANNULE'  , NULL                 , '2026-10-14 09:00:00', 'Arrêt du traitement'),
    ('SOI119', '9',  NULL, '2026-10-14 17:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI120', NULL, '10', '2026-10-14 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI121', NULL, '19', '2026-10-14 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI122', NULL, '22', '2026-10-14 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI123', '7',  NULL, '2026-10-14 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI124', '10', NULL, '2026-10-14 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI125', '12', NULL, '2026-10-14 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI126', '17', NULL, '2026-10-14 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI127', '20', NULL, '2026-10-14 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI128', '21', NULL, '2026-10-14 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI129', '15', NULL, '2026-10-14 18:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI130', NULL, '9',  '2026-10-14 20:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI131', NULL, '13', '2026-10-14 20:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI132', NULL, '20', '2026-10-14 20:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI133', '13', NULL, '2026-10-14 20:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI134', '8',  NULL, '2026-10-14 20:15:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI135', '9',  NULL, '2026-10-14 21:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI136', '5',  NULL, '2026-10-14 22:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI137', '17', NULL, '2026-10-14 22:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI138', '21', NULL, '2026-10-14 22:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI139', '15', NULL, '2026-10-14 22:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI140', NULL, '17', '2026-10-14 23:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI141', NULL, '19', '2026-10-15 00:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI142', NULL, '22', '2026-10-15 00:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI143', '7',  NULL, '2026-10-15 00:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI144', '10', NULL, '2026-10-15 00:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI145', '13', NULL, '2026-10-15 00:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI146', '20', NULL, '2026-10-15 00:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI147', '8',  NULL, '2026-10-15 00:15:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI148', '9',  NULL, '2026-10-15 01:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI149', NULL, '9',  '2026-10-15 02:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI150', NULL, '10', '2026-10-15 02:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI151', '17', NULL, '2026-10-15 02:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI152', '21', NULL, '2026-10-15 02:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI153', '15', NULL, '2026-10-15 02:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI154', NULL, '14', '2026-10-15 04:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI155', '5',  NULL, '2026-10-15 04:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI156', '13', NULL, '2026-10-15 04:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI157', '19', NULL, '2026-10-15 04:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI158', '9',  NULL, '2026-10-15 05:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI159', NULL, '19', '2026-10-15 06:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI160', NULL, '22', '2026-10-15 06:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI161', '7',  NULL, '2026-10-15 06:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI162', '10', NULL, '2026-10-15 06:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI163', '11', NULL, '2026-10-15 06:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI164', '12', NULL, '2026-10-15 06:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI165', '17', NULL, '2026-10-15 06:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI166', '20', NULL, '2026-10-15 06:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI167', '21', NULL, '2026-10-15 06:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI168', '15', NULL, '2026-10-15 06:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI169', NULL, '17', '2026-10-15 07:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI170', NULL, '9',  '2026-10-15 08:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI171', NULL, '13', '2026-10-15 08:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI172', NULL, '20', '2026-10-15 08:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI173', '13', NULL, '2026-10-15 08:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI174', '9',  NULL, '2026-10-15 09:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI175', NULL, '10', '2026-10-15 10:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI176', NULL, '21', '2026-10-15 10:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI177', '5',  NULL, '2026-10-15 10:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI178', '6',  NULL, '2026-10-15 10:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI179', '16', NULL, '2026-10-15 10:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI180', '17', NULL, '2026-10-15 10:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI181', '18', NULL, '2026-10-15 10:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI182', '21', NULL, '2026-10-15 10:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI183', '15', NULL, '2026-10-15 10:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI184', NULL, '19', '2026-10-15 12:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI185', NULL, '22', '2026-10-15 12:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI186', '7',  NULL, '2026-10-15 12:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI187', '10', NULL, '2026-10-15 12:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI188', '13', NULL, '2026-10-15 12:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI189', '20', NULL, '2026-10-15 12:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI190', '9',  NULL, '2026-10-15 13:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI191', NULL, '9',  '2026-10-15 14:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI192', '17', NULL, '2026-10-15 14:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI193', '21', NULL, '2026-10-15 14:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI194', NULL, '16', '2026-10-15 14:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI195', '15', NULL, '2026-10-15 14:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI196', NULL, '17', '2026-10-15 15:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI197', '5',  NULL, '2026-10-15 16:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI198', '13', NULL, '2026-10-15 16:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI199', '19', NULL, '2026-10-15 16:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI200', NULL, '12', '2026-10-15 17:00:00', 'ANNULE'  , NULL                 , '2026-10-14 09:00:00', 'Arrêt du traitement'),
    ('SOI201', '9',  NULL, '2026-10-15 17:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI202', NULL, '10', '2026-10-15 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI203', NULL, '19', '2026-10-15 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI204', NULL, '22', '2026-10-15 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI205', '7',  NULL, '2026-10-15 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI206', '10', NULL, '2026-10-15 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI207', '12', NULL, '2026-10-15 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI208', '17', NULL, '2026-10-15 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI209', '20', NULL, '2026-10-15 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI210', '21', NULL, '2026-10-15 18:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI211', '15', NULL, '2026-10-15 18:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI212', NULL, '9',  '2026-10-15 20:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI213', NULL, '13', '2026-10-15 20:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI214', NULL, '20', '2026-10-15 20:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI215', '13', NULL, '2026-10-15 20:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI216', '9',  NULL, '2026-10-15 21:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI217', '5',  NULL, '2026-10-15 22:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI218', '17', NULL, '2026-10-15 22:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI219', '21', NULL, '2026-10-15 22:00:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI220', '15', NULL, '2026-10-15 22:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL),
    ('SOI221', NULL, '17', '2026-10-15 23:30:00', 'PLANIFIE', NULL                 , NULL                 , NULL);

-- ---------------------------------------------------------------------------
-- Équipes de la rotation du matin du 14 octobre 2026 en réanimation médicale (AURA-30) :
--   Équipe A : Thomas Girard (IDE) + Julien Perrin (AS) → Simpson (1) et Skywalker (2)
--   Équipe B : Inès Rousseau (IDE) → Ripley (8)
INSERT INTO CareTeam (idCareTeam, idHealthcareService, shiftDate, shift, name) VALUES
    (1, 'SRV01', '2026-10-14', 'MATIN', 'Équipe A'),
    (2, 'SRV01', '2026-10-14', 'MATIN', 'Équipe B');

INSERT INTO CareTeamMember (idCareTeam, idPractitioner) VALUES
    (1, 'PRA04'), (1, 'PRA06'), (2, 'PRA05');

INSERT INTO CareTeamPatient (idCareTeam, idEpisodeOfCare) VALUES
    (1, '1'), (1, '2'), (2, '8');

-- Données complémentaires, même rotation : les nouveaux patients de réanimation médicale (séjours 9 à 16).
-- Un patient n'est que dans une équipe par rotation (règle de CareTeamService), un soignant aussi.
--   Équipe B : + Everdeen (15) et Poulain (16)
--   Équipe C : Carla Espinosa (IDE) + Samwise Gamgee (AS) → Wayne (9), Holmes (10), Bourne (11)
--   Équipe D : Abby Lockhart (IDE) + Mary Poppins (AS) → Targaryen (12), Dumbledore (13), Stark (14)
INSERT INTO CareTeam (idCareTeam, idHealthcareService, shiftDate, shift, name) VALUES
    (3, 'SRV01', '2026-10-14', 'MATIN', 'Équipe C'),
    (4, 'SRV01', '2026-10-14', 'MATIN', 'Équipe D');

INSERT INTO CareTeamMember (idCareTeam, idPractitioner) VALUES
    (3, 'PRA11'), (3, 'PRA13'), (4, 'PRA12'), (4, 'PRA14');

INSERT INTO CareTeamPatient (idCareTeam, idEpisodeOfCare) VALUES
    (2, '15'), (2, '16'),
    (3, '9'), (3, '10'), (3, '11'),
    (4, '12'), (4, '13'), (4, '14');

-- Mêmes équipes pour l'après-midi et la nuit du 14 (2026-11-04 : trois rotations) : la démonstration trouve des
-- équipes et « Mon planning » quelle que soit l'heure. Équipes 5 à 8 (après-midi) et 9 à 12 (nuit).
INSERT INTO CareTeam (idCareTeam, idHealthcareService, shiftDate, shift, name)
    SELECT idCareTeam + 4, idHealthcareService, shiftDate, 'APRES_MIDI', name FROM CareTeam WHERE idCareTeam <= 4;
INSERT INTO CareTeam (idCareTeam, idHealthcareService, shiftDate, shift, name)
    SELECT idCareTeam + 8, idHealthcareService, shiftDate, 'NUIT', name FROM CareTeam WHERE idCareTeam <= 4;
INSERT INTO CareTeamMember (idCareTeam, idPractitioner)
    SELECT idCareTeam + 4, idPractitioner FROM CareTeamMember WHERE idCareTeam <= 4;
INSERT INTO CareTeamMember (idCareTeam, idPractitioner)
    SELECT idCareTeam + 8, idPractitioner FROM CareTeamMember WHERE idCareTeam <= 4;
INSERT INTO CareTeamPatient (idCareTeam, idEpisodeOfCare)
    SELECT idCareTeam + 4, idEpisodeOfCare FROM CareTeamPatient WHERE idCareTeam <= 4;
INSERT INTO CareTeamPatient (idCareTeam, idEpisodeOfCare)
    SELECT idCareTeam + 8, idEpisodeOfCare FROM CareTeamPatient WHERE idCareTeam <= 4;
