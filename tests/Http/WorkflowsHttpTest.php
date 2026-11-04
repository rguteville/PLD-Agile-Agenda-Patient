<?php

declare(strict_types=1);

namespace Aura\Tests\Http;

use DateTimeImmutable;

/**
 * Parcours de bout en bout qui écrivent en base, comme un soignant dans le navigateur : admission,
 * validation d'un soin, équipe, arrêt d'une ligne, décès, édition en ligne. Chaque test crée ses propres
 * données (ZZ…), vérifie les refus (CSRF, règles métier) et nettoie ce qu'il a écrit.
 */
final class WorkflowsHttpTest extends HttpTestCase
{
    public function testAdmissionRejectsMissingNamesThenAdmitsAndOpensTheRecord(): void
    {
        // Relecture adversariale du 2026-11-04 : l'admission exige le jeton CSRF de la page.
        self::assertSame(403, $this->postForm('/patients/create', ['lastname' => 'Zzhttpadmission'], [
            'Accept: application/json',
        ])->status, 'sans jeton CSRF');
        $csrf = $this->csrfFrom($this->get('/'));
        $refused = $this->postForm('/patients/create', ['csrf' => $csrf, 'lastname' => '', 'firstname' => ''], [
            'Accept: application/json',
        ]);
        self::assertSame(422, $refused->status);
        self::assertArrayHasKey('lastname', $refused->json()['errors'] ?? [], 'erreur sur le champ du formulaire');

        $admitted = $this->postForm('/patients/create', [
            'csrf' => $csrf,
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
        $context = ['idHealthcareService' => 'SRV01', 'date' => '2031-03-04', 'rotation' => 'nuit'];
        $page = $this->get('/equipes?idHealthcareService=SRV01&date=2031-03-04&rotation=nuit');
        $csrf = $this->csrfFrom($page);
        try {
            $team = $context + ['name' => 'Zz équipe', 'members' => ['PRA05'], 'patients' => ['1', '2']];
            self::assertSame(403, $this->postForm('/equipes/creer', $team)->status, 'sans jeton CSRF');

            $created = $this->postForm('/equipes/creer', ['csrf' => $csrf] + $team);
            self::assertSame(303, $created->status, $created->body);
            $list = $this->get((string) $created->header('Location'))->body;
            self::assertStringContainsString('Zz équipe', $list);

            $twice = $this->postForm('/equipes/creer', ['csrf' => $csrf, 'name' => 'Zz autre'] + $team);
            self::assertSame(422, $twice->status, 'un patient n\'a qu\'une équipe par rotation');

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
                'le soin futur de la ligne arrêtée est annulé'
            );
        } finally {
            $this->pdo()->exec("DELETE FROM Appointment WHERE idServiceRequest = '900001'");
            $this->pdo()->exec("DELETE FROM ServiceRequest WHERE idServiceRequest = '900001'");
        }
    }

    /** Retours du 2026-11-04 : déclarer le décès (CSRF, médecin) met fin au séjour ; le dossier l'affiche. */
    public function testDeclaringDeathNeedsCsrfAndEndsTheStay(): void
    {
        $admitted = $this->postForm('/patients/create', [
            'csrf' => $this->csrfFrom($this->get('/')),
            'lastname' => 'Zzhttpdeces', 'firstname' => 'Test', 'gender' => 'M', 'idHealthcareService' => 'SRV03',
        ], ['Accept: application/json']);
        try {
            $record = (string) ($admitted->json()['redirect'] ?? '');
            self::assertMatchesRegularExpression('#^/sejour\?id=(\d+)$#', $record, $admitted->body);
            $id = (string) substr($record, strlen('/sejour?id='));
            $page = $this->get($record);
            self::assertStringContainsString('data-declare-death', $page->body);
            $body = ['episodeOfCare' => $id, 'oldDeceased' => '', 'validator' => 'PRA01', 'password' => 'x'];

            self::assertSame(403, $this->postJson('/sejour/deces', $body)->status, 'sans jeton CSRF');
            $declared = $this->postJson('/sejour/deces', $body, $this->csrfFrom($page));
            self::assertSame(200, $declared->status, $declared->body);

            $after = $this->get($record)->body;
            self::assertStringContainsString('Clos', $after, 'séjour terminé');
            self::assertStringNotContainsString('data-declare-death', $after);
            self::assertSame(
                '1',
                (string) $this->pdo()->query("SELECT deceased + 0 FROM Patient WHERE lastName = 'Zzhttpdeces'")
                    ->fetchColumn()
            );
        } finally {
            $this->deletePatients('Zzhttpdeces');
        }
    }

    /**
     * Retours du 2026-11-04 : une modification en ligne du dossier exige l'authentification factice (médecin et
     * mot de passe) ; l'historique porte le nom du médecin. Par la vraie route, câblage compris.
     */
    public function testInlineEditNeedsFactitiousDoctorAuthenticationAndTracesTheDoctor(): void
    {
        $csrf = $this->csrfFrom($this->get('/sejour?id=8'));
        $before = (string) $this->pdo()->query("SELECT precautions FROM EpisodeOfCare WHERE idEpisodeOfCare = '8'")
            ->fetchColumn();
        $edit = [
            'entity' => 'episode', 'id' => '8', 'episodeOfCare' => '8', 'field' => 'precautions',
            'value' => 'Zz précaution HTTP', 'oldValue' => $before,
        ];
        try {
            $refused = $this->postJson('/sejour/champ/modifier', $edit, $csrf);
            self::assertSame(422, $refused->status, $refused->body);
            self::assertTrue($refused->json()['auth'] ?? false, 'sans médecin ni mot de passe');

            $auth = ['validator' => 'PRA01', 'password' => 'x'];
            $saved = $this->postJson('/sejour/champ/modifier', $edit + $auth, $csrf);
            self::assertSame(200, $saved->status, $saved->body);
            self::assertStringContainsString('authentification factice', (string) ($saved->json()['author'] ?? ''));
            self::assertSame(
                'Zz précaution HTTP',
                $this->pdo()->query("SELECT precautions FROM EpisodeOfCare WHERE idEpisodeOfCare = '8'")->fetchColumn()
            );
        } finally {
            $this->pdo()->prepare("UPDATE EpisodeOfCare SET precautions = ? WHERE idEpisodeOfCare = '8'")
                ->execute([$before === '' ? null : $before]);
            $this->pdo()->exec(
                "DELETE FROM AuditEvent WHERE entity = 'EpisodeOfCare' AND entityId = '8' AND field = 'precautions'"
            );
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
