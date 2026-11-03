-- Recale les données de démo (database/test.sql) sur le jour courant, pour une démonstration :
-- le « jour de référence » du jeu de démo (mercredi 14 octobre 2026, jour du soin SOI01) devient
-- aujourd'hui, et toutes les dates de séjour, d'ordonnance, de ligne et de soin glissent d'autant.
-- Les dates de naissance ne bougent pas.
--
-- À lancer APRÈS test.sql, jamais dans le CI (les tests s'appuient sur les dates fixes) :
--   mariadb -u aura -p aura < database/demo-today.sql
-- Rejouable : le décalage se calcule à partir de SOI01, déjà recalé à la 2e exécution (décalage nul).

SET @shift = (SELECT DATEDIFF(CURRENT_DATE, DATE(plannedTime)) FROM Appointment WHERE idAppointment = 'SOI01');
SET @shift = COALESCE(@shift, 0);

UPDATE EpisodeOfCare
SET admissionDate = admissionDate + INTERVAL @shift DAY,
    dischargeDate = dischargeDate + INTERVAL @shift DAY,
    surgeryDate = surgeryDate + INTERVAL @shift DAY;

UPDATE AllergyIntolerance SET recordedAt = recordedAt + INTERVAL @shift DAY;

UPDATE CarePlan
SET recordedAt = recordedAt + INTERVAL @shift DAY,
    validatedAt = validatedAt + INTERVAL @shift DAY;

UPDATE ServiceRequest
SET startDate = startDate + INTERVAL @shift DAY,
    endDate = endDate + INTERVAL @shift DAY,
    stoppedAt = stoppedAt + INTERVAL @shift DAY;

UPDATE MedicationRequest
SET startDate = startDate + INTERVAL @shift DAY,
    endDate = endDate + INTERVAL @shift DAY,
    stoppedAt = stoppedAt + INTERVAL @shift DAY;

UPDATE Appointment
SET plannedTime = plannedTime + INTERVAL @shift DAY,
    timeOfCompletion = timeOfCompletion + INTERVAL @shift DAY,
    timeRecorded = timeRecorded + INTERVAL @shift DAY;

UPDATE AuditEvent SET recordedAt = recordedAt + INTERVAL @shift DAY;

SELECT @shift AS jours_decales;
