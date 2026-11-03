<?php

declare(strict_types=1);

namespace Aura\Tests\Http;

use DateTimeImmutable;

/**
 * Parcours de bout en bout qui écrivent en base, comme un soignant dans le navigateur : admission,
 * allergies, validation d'un soin, équipes, suppression d'un patient. Chaque test crée ses propres
 * données (ZZ…), vérifie les refus (CSRF, règles métier) et nettoie ce qu'il a écrit.
 */
final class WorkflowsHttpTest extends HttpTestCase
{
    public function testAdmissionRejectsMissingNamesThenAdmitsAndOpensTheRecord(): void
    {
        $refused = $this->postForm('/patients/create', ['lastname' => '', 'firstname' => ''], [
            'Accept: application/json',
        ]);
        self::assertSame(422, $refused->status);
        self::assertArrayHasKey('lastname', $refused->json()['errors'] ?? [], 'erreur sur le champ du formulaire');

        $admitted = $this->postForm('/patients/create', [
            'lastname' => 'Zzhttpadmission', 'firstname' => 'Test', 'gender' => 'F', 'idHealthcareService' => 'SRV03',
        ], ['Accept: application/json']);
        try {
            self::assertSame(200, $admitted->status, $admitted->body);
            $redirect = (string) ($admitted->json()['redirect'] ?? '');
            self::assertMatchesRegularExpression('#^/sejour\?id=\d+$#', $redirect);
            $record = $this->get($redirect);
            self::assertSame(200, $record->status);
            self::assertStringContainsString('ZZHTTPADMISSION', mb_strtoupper($record->body));
        } finally {
            $this->deletePatients('Zzhttpadmission');
        }
    }

    public function testAllergyIsAddedThenRemovedFromTheRecord(): void
    {
        // Ripley (séjour 8) n'a aucune allergie dans les données de démo.
        $added = $this->postForm('/sejour/allergies/ajouter', [
            'idEpisodeOfCare' => '8', 'idSubstance' => 'ALG01', 'criticality' => 'Élevée',
        ]);
        try {
            self::assertSame(303, $added->status);
            self::assertSame('/sejour?id=8', $added->header('Location'));
            self::assertStringContainsString('Pénicilline', $this->get('/sejour?id=8')->body);

            $id = (int) $this->pdo()->query(
                "SELECT idAllergy FROM AllergyIntolerance WHERE idPatient = '7' AND idSubstance = 'ALG01'"
            )->fetchColumn();
            $removed = $this->postForm(
                '/sejour/allergies/retirer',
                ['idEpisodeOfCare' => '8', 'idAllergy' => (string) $id]
            );
            self::assertSame(303, $removed->status);
            self::assertStringContainsString('Aucune allergie connue', $this->get('/sejour?id=8')->body);
        } finally {
            $this->pdo()->exec("DELETE FROM AllergyIntolerance WHERE idPatient = '7' AND idSubstance = 'ALG01'");
        }
    }

    public function testCareIsValidatedOnceWithCsrfAndAuthorShownInTheAgenda(): void
    {
        $planned = (new DateTimeImmutable('now'))->modify('-10 minutes');
        $this->pdo()->prepare(
            "INSERT INTO Appointment (idAppointment, idServiceRequest, idMedicationRequest, plannedTime, status)
             VALUES ('ZZ_HTTP', NULL, '1', ?, 'PLANIFIE')"
        )->execute([$planned->format('Y-m-d H:i:s')]);
        try {
            $page = $this->get('/agenda-patient?id=1');
            $csrf = $this->csrfFrom($page);
            $done = [
                'episodeOfCare' => '1', 'appointment' => 'ZZ_HTTP', 'action' => 'done', 'idPerformer' => 'PRA04',
                'password' => 'x', 'completedAt' => $planned->format('Y-m-d\TH:i'),
            ];

            self::assertSame(403, $this->postJson('/agenda-patient/soin', $done)->status, 'sans jeton CSRF');
            self::assertSame(
                422,
                $this->postJson('/agenda-patient/soin', ['password' => ''] + $done, $csrf)->status,
                'authentification factice : mot de passe vide'
            );
            self::assertSame(
                404,
                $this->postJson('/agenda-patient/soin', ['episodeOfCare' => '2'] + $done, $csrf)->status,
                'soin d\'un autre séjour'
            );

            $saved = $this->postJson('/agenda-patient/soin', $done, $csrf);
            self::assertSame(200, $saved->status, $saved->body);
            self::assertSame(409, $this->postJson('/agenda-patient/soin', $done, $csrf)->status, 'déjà validé');

            $agenda = $this->get('/agenda-patient?id=1&date=' . $planned->format('Y-m-d'))->body;
            self::assertStringContainsString('par Thomas Girard', $agenda, 'RG10 : qui a validé');
        } finally {
            $this->pdo()->exec("DELETE FROM AuditEvent WHERE entity = 'Appointment' AND entityId = 'ZZ_HTTP'");
            $this->pdo()->exec("DELETE FROM Appointment WHERE idAppointment = 'ZZ_HTTP'");
        }
    }

    public function testTeamIsComposedAndRemovedForAShift(): void
    {
        $context = ['idHealthcareService' => 'SRV01', 'date' => '2031-03-04', 'vacation' => 'nuit'];
        $page = $this->get('/equipes?idHealthcareService=SRV01&date=2031-03-04&vacation=nuit');
        $csrf = $this->csrfFrom($page);
        try {
            $team = $context + ['name' => 'Zz équipe', 'members' => ['PRA05'], 'patients' => ['1', '2']];
            self::assertSame(403, $this->postForm('/equipes/creer', $team)->status, 'sans jeton CSRF');

            $created = $this->postForm('/equipes/creer', ['csrf' => $csrf] + $team);
            self::assertSame(303, $created->status, $created->body);
            $list = $this->get((string) $created->header('Location'))->body;
            self::assertStringContainsString('Zz équipe', $list);

            $twice = $this->postForm('/equipes/creer', ['csrf' => $csrf, 'name' => 'Zz autre'] + $team);
            self::assertSame(422, $twice->status, 'un patient n\'a qu\'une équipe par vacation');

            $id = (int) $this->pdo()->query("SELECT idCareTeam FROM CareTeam WHERE name = 'Zz équipe'")->fetchColumn();
            $removed = $this->postForm('/equipes/retirer', ['csrf' => $csrf, 'idCareTeam' => (string) $id] + $context);
            self::assertSame(303, $removed->status);
            self::assertStringNotContainsString('Zz équipe', $this->get((string) $removed->header('Location'))->body);
        } finally {
            foreach (['CareTeamMember', 'CareTeamPatient'] as $table) {
                $this->pdo()->exec(
                    "DELETE l FROM $table l JOIN CareTeam t ON t.idCareTeam = l.idCareTeam
                     WHERE t.shiftDate = '2031-03-04'"
                );
            }
            $this->pdo()->exec("DELETE FROM CareTeam WHERE shiftDate = '2031-03-04'");
        }
    }

    public function testPatientDeletionNeedsCsrfIsRefusedDuringAStayAndHidesThePatient(): void
    {
        $this->pdo()->exec(
            "INSERT INTO Patient (idPatient, lastName, firstName) VALUES ('900001', 'Zzhttpsuppression', 'Test')"
        );
        try {
            $confirm = $this->get('/patients/supprimer?id=900001');
            self::assertSame(200, $confirm->status);
            $csrf = $this->csrfFrom($confirm);

            self::assertSame(403, $this->postForm('/patients/supprimer?id=900001', [])->status, 'sans jeton CSRF');
            self::assertSame(
                409,
                $this->postForm('/patients/supprimer?id=1', ['csrf' => $csrf])->status,
                'RG10 : patient avec un séjour en cours'
            );

            $deleted = $this->postForm('/patients/supprimer?id=900001', ['csrf' => $csrf]);
            self::assertSame(303, $deleted->status);
            self::assertStringNotContainsString('Zzhttpsuppression', $this->get('/patients')->body);
            self::assertSame(404, $this->get('/patients/supprimer?id=900001')->status, 'déjà supprimé');
        } finally {
            $this->pdo()->exec("DELETE FROM AuditEvent WHERE entity = 'Patient' AND entityId = '900001'");
            $this->pdo()->exec("DELETE FROM Patient WHERE idPatient = '900001'");
        }
    }

    public function testStoppingALineNeedsCsrfAndADoctorPasswordAndCancelsItsFutureCare(): void
    {
        $now = new DateTimeImmutable('now');
        $this->pdo()->prepare(
            "INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, frequencyHours)
             VALUES ('900001', '1', 'TYP04', ?, 24)"
        )->execute([$now->modify('-1 day')->format('Y-m-d H:i:s')]);
        $this->pdo()->prepare(
            "INSERT INTO Appointment (idAppointment, idServiceRequest, plannedTime, status)
             VALUES ('ZZ_STOP', '900001', ?, 'PLANIFIE')"
        )->execute([$now->modify('+2 hours')->format('Y-m-d H:i:s')]);
        try {
            $csrf = $this->csrfFrom($this->get('/sejour?id=1'));
            $stop = ['idLine' => '900001', 'isMedication' => '0', 'idStoppedBy' => 'PRA01', 'password' => 'x'];
            $path = '/care-plans/stop?idEpisodeOfCare=1';

            self::assertSame(403, $this->postForm($path, $stop)->status, 'sans jeton CSRF');
            self::assertSame(
                422,
                $this->postForm($path, ['csrf' => $csrf, 'password' => ''] + $stop)->status,
                'mot de passe vide'
            );
            self::assertSame(
                422,
                $this->postForm($path, ['csrf' => $csrf, 'idStoppedBy' => 'PRA03'] + $stop)->status,
                'une interne n\'arrête pas une prescription'
            );

            $stopped = $this->postForm($path, ['csrf' => $csrf] + $stop);
            self::assertSame(303, $stopped->status, $stopped->body);
            self::assertSame(
                'ANNULE',
                $this->pdo()->query("SELECT status FROM Appointment WHERE idAppointment = 'ZZ_STOP'")->fetchColumn(),
                'AURA-22 : le soin futur de la ligne arrêtée est annulé'
            );
        } finally {
            $this->pdo()->exec("DELETE FROM Appointment WHERE idServiceRequest = '900001'");
            $this->pdo()->exec("DELETE FROM ServiceRequest WHERE idServiceRequest = '900001'");
        }
    }

    /** Supprime physiquement les patients de test d'un nom donné et ce qui en dépend (nettoyage). */
    private function deletePatients(string $lastName): void
    {
        $pdo = $this->pdo();
        $ids = $pdo->prepare('SELECT idPatient FROM Patient WHERE lastName = ?');
        $ids->execute([$lastName]);
        foreach ($ids->fetchAll(\PDO::FETCH_COLUMN) as $idPatient) {
            $pdo->prepare(
                "DELETE FROM AuditEvent WHERE (entity = 'Patient' AND entityId = ?)
                 OR (entity = 'EpisodeOfCare'
                     AND entityId IN (SELECT idEpisodeOfCare FROM EpisodeOfCare WHERE idPatient = ?))"
            )->execute([$idPatient, $idPatient]);
            $pdo->prepare('DELETE FROM AllergyIntolerance WHERE idPatient = ?')->execute([$idPatient]);
            $pdo->prepare('DELETE FROM EpisodeOfCare WHERE idPatient = ?')->execute([$idPatient]);
            $pdo->prepare('DELETE FROM Patient WHERE idPatient = ?')->execute([$idPatient]);
        }
    }
}
