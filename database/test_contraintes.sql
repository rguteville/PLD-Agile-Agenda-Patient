-- Tests d'insertions invalides pour vérifier les contraintes sur la base

-- Indique à MariaDB que ce fichier est en UTF-8. Sans ça, si le client est en latin1
-- (cas fréquent), les accents sont stockés en double encodage et PHP affiche "LefÃ¨vre".
SET NAMES utf8mb4;

-- Patient
--- Unicité ipp
INSERT INTO Patient (idPatient,ipp,lastName) VALUES
    ('t1',555555,'Martin'),
    ('t2',555555,'Dupont');
--- 100000 <= ipp < 999999999
INSERT INTO Patient (idPatient,ipp,lastName) VALUES
    ('t3',10000,'Jullien');
INSERT INTO Patient (idPatient,ipp,lastName) VALUES
    ('t4',1000000000,'Toubon');

-- EpisodeOfCare
--- Unicité episodeNumber
INSERT INTO episodeofcare (`idEpisodeOfCare`,`idPatient`,`idHealthcareService`,`episodeNumber`,`admissionDate`) VALUES
    ('t1','1','SRV01','2026-100000','2026-10-10 08:15:00'),
    ('t2','1','SRV01','2026-100000','2026-10-11 08:15:00');
--- Clés étrangères :
---- idPatient
INSERT INTO episodeofcare (`idEpisodeOfCare`,`idPatient`,`idHealthcareService`,`episodeNumber`,`admissionDate`) VALUES
    ('t3','t','SRV01','2026-100000','2026-10-10 08:15:00');
---- idHealthcareService
INSERT INTO episodeofcare (`idEpisodeOfCare`,`idPatient`,`idHealthcareService`,`episodeNumber`,`admissionDate`) VALUES
    ('t4','1','t','2026-100000','2026-10-10 08:15:00');

-- AllergyIntolerance
--- Unicité (idPatient, idSubstance)
INSERT INTO allergyintolerance (`idAllergy`,`idPatient`,`idSubstance`) VALUES
    ('t1','1','ALG03'),
    ('t2','1','ALG03');
--- Clés étrangères :
---- idPatient
INSERT INTO allergyintolerance (`idAllergy`,`idPatient`,`idSubstance`) VALUES
    ('t3','t','ALG01');
---- idSubstance
INSERT INTO allergyintolerance (`idAllergy`,`idPatient`,`idSubstance`) VALUES
    ('t4','1','t');

-- Practitioner
--- Clés étrangères :
---- idPractitionerRole
INSERT INTO practitioner (`idPractitioner`,`idPractitionerRole`) VALUES
    ('t1','t');

-- CarePlan
--- mode IN ('MEDICALE', 'PARAMEDICALE', 'PROTOCOLE', 'URGENCE')
