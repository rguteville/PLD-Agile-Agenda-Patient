<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AuditEventDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\SejourDao;
use Aura\Dao\Transaction;
use Aura\Domain\AuditEvent;
use Aura\Domain\Patient;
use Aura\Domain\Sejour;
use Aura\Service\RecordEditService;
use Aura\Service\RecordSectionService;
use Aura\Service\SejourService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Retours du 2026-11-02 : modification d'une partie entière du dossier et fin de séjour, validées par un
 * médecin (authentification factice), sur la base du CI. Transaction annulée en fin de test.
 */
final class RecordSectionServiceTest extends TestCase
{
    private PDO $pdo;
    private RecordSectionService $service;
    private int $idPatient;
    private int $idSejour;
    private string $idDoctor;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }
        $this->pdo = Database::get();
        $this->pdo->beginTransaction();

        $patients = new PatientDao($this->pdo);
        $sejours = new SejourDao($this->pdo);
        $this->idPatient = $patients->insert(new Patient(
            null,
            '987654320',
            'Zzpartie',
            'Alice',
            new DateTimeImmutable('1970-01-01'),
            'F',
            details: ['medicalHistory' => 'HTA']
        ));
        $this->idSejour = (int) $sejours->insert(new Sejour(
            null,
            $this->idPatient,
            '',
            new DateTimeImmutable('2026-10-01 08:00'),
            numLit: 'ZZL1',
            numBox: 'ZZB1'
        ))->getIdSejour();

        $references = new ReferenceDataDao($this->pdo);
        $this->service = new RecordSectionService(
            $patients,
            $sejours,
            new SejourService($sejours),
            new RecordEditService(
                $patients,
                $sejours,
                new AuditEventDao($this->pdo),
                new Transaction($this->pdo),
                $references
            ),
            new AuditEventDao($this->pdo),
            new Transaction($this->pdo),
            $references
        );
        $validators = $this->service->validators();
        self::assertNotEmpty($validators, 'test.sql fournit au moins un médecin habilité');
        $this->idDoctor = (string) array_key_first($validators);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testUnePartieEstEnregistreeEtChaqueChampTraceAuNomDuMedecin(): void
    {
        $result = $this->service->updatePart(
            $this->idSejour,
            'history',
            ['medicalHistory' => 'HTA, diabète', 'lifestyle' => 'Autonome', 'comorbidities' => ''],
            ['medicalHistory' => 'HTA', 'lifestyle' => '', 'comorbidities' => ''],
            $this->idDoctor,
            'factice'
        );

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status']);
        $patient = (new PatientDao($this->pdo))->findById($this->idPatient);
        self::assertSame('HTA, diabète', $patient?->getDetail('medicalHistory'));
        self::assertSame('Autonome', $patient?->getDetail('lifestyle'));
        $traces = $this->traces();
        self::assertSame(['lifestyle', 'medicalHistory'], $this->fields($traces), 'un champ inchangé n\'est pas tracé');
        self::assertStringContainsString('authentification factice', $traces[0]->getAuthor());
    }

    public function testUnChampRefuseAnnuleToutLaPartie(): void
    {
        $result = $this->service->updatePart(
            $this->idSejour,
            'summary',
            ['prenom' => 'Alicia', 'email' => 'pas-un-mail'],
            ['prenom' => 'Alice', 'email' => ''],
            $this->idDoctor,
            'factice'
        );

        self::assertSame(RecordSectionService::STATUS_INVALID, $result['status']);
        self::assertArrayHasKey('email', $result['errors']);
        $prenom = (new PatientDao($this->pdo))->findById($this->idPatient)?->getPrenom();
        self::assertSame('Alice', $prenom, 'rien d\'écrit');
        self::assertSame([], $this->traces());
    }

    public function testIdentiteEtEmplacementModifiesEnUneFois(): void
    {
        $result = $this->service->updatePart(
            $this->idSejour,
            'summary',
            ['nom' => 'Zzpartie', 'prenom' => 'Alicia', 'telephone' => '06 11 22 33 44', 'bedNumber' => 'ZZL2'],
            ['nom' => 'Zzpartie', 'prenom' => 'Alice', 'telephone' => '', 'bedNumber' => 'ZZL1'],
            $this->idDoctor,
            'factice'
        );

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status'], json_encode($result['errors']));
        $patient = (new PatientDao($this->pdo))->findById($this->idPatient);
        self::assertSame('Alicia', $patient?->getPrenom());
        self::assertSame('0611223344', $patient?->getTelephone(), 'téléphone enregistré sans espaces');
        self::assertSame('ZZL2', (new SejourDao($this->pdo))->findById($this->idSejour)?->getNumLit());
        self::assertSame(['bedNumber', 'firstName', 'phoneNumber'], $this->fields($this->traces()));
    }

    public function testConflitSiLaValeurDOrigineNEstPlusCelleEnBase(): void
    {
        $result = $this->service->updatePart(
            $this->idSejour,
            'history',
            ['medicalHistory' => 'HTA, asthme'],
            ['medicalHistory' => 'Autre valeur affichée'],
            $this->idDoctor,
            'factice'
        );

        self::assertSame(RecordSectionService::STATUS_CONFLICT, $result['status']);
        self::assertSame('HTA', (new PatientDao($this->pdo))->findById($this->idPatient)?->getDetail('medicalHistory'));
    }

    public function testAuthentificationFacticeRefuseeSansMedecinOuSansMotDePasse(): void
    {
        foreach ([['', 'x'], [$this->idDoctor, '  '], ['PRA-INCONNU', 'x']] as [$doctor, $password]) {
            $result = $this->service->updatePart(
                $this->idSejour,
                'history',
                ['lifestyle' => 'x'],
                ['lifestyle' => ''],
                $doctor,
                $password
            );
            self::assertSame(RecordSectionService::STATUS_AUTH, $result['status']);
        }
        self::assertSame([], $this->traces());
    }

    public function testUnChampHorsDeLaPartieEstIgnore(): void
    {
        $result = $this->service->updatePart(
            $this->idSejour,
            'history',
            ['lastName' => 'Pirate', 'idPatient' => '1'],
            ['lastName' => 'Zzpartie', 'idPatient' => (string) $this->idPatient],
            $this->idDoctor,
            'factice'
        );

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status'], 'rien à faire');
        self::assertSame('Zzpartie', (new PatientDao($this->pdo))->findById($this->idPatient)?->getNom());
    }

    public function testFinDeSejourDateeEtTracee(): void
    {
        $result = $this->service->endStay($this->idSejour, '02/10/2026', '09:30', $this->idDoctor, 'factice');

        self::assertSame(RecordSectionService::STATUS_SAVED, $result['status'], json_encode($result['errors']));
        $sejour = (new SejourDao($this->pdo))->findById($this->idSejour);
        self::assertSame('2026-10-02 09:30', $sejour?->getDateSortie()?->format('Y-m-d H:i'));
        self::assertSame(['dischargeDate'], $this->fields($this->traces()));

        $again = $this->service->endStay($this->idSejour, '', '', $this->idDoctor, 'factice');
        self::assertSame(RecordSectionService::STATUS_INVALID, $again['status'], 'séjour déjà terminé');
    }

    public function testFinDeSejourAvantLAdmissionRefusee(): void
    {
        $result = $this->service->endStay($this->idSejour, '30/09/2026', '08:00', $this->idDoctor, 'factice');

        self::assertSame(RecordSectionService::STATUS_INVALID, $result['status']);
        self::assertArrayHasKey('dischargeDate', $result['errors']);
    }

    public function testValeursActuellesDUnePartieAuFormatDuFormulaire(): void
    {
        $patient = (new PatientDao($this->pdo))->findById($this->idPatient);
        $sejour = (new SejourDao($this->pdo))->findById($this->idSejour);
        self::assertNotNull($patient);
        self::assertNotNull($sejour);

        $values = RecordSectionService::currentValues('summary', $patient, $sejour);

        self::assertSame('01/01/1970', $values['dateNaissance'], 'date en JJ/MM/AAAA');
        self::assertSame('ZZL1', $values['bedNumber']);
        self::assertSame('', $values['telephone']);
    }

    /** @return list<AuditEvent> */
    private function traces(): array
    {
        return array_values((new AuditEventDao($this->pdo))->findForRecord($this->idPatient, $this->idSejour, 50));
    }

    /**
     * @param list<AuditEvent> $traces
     * @return list<string>
     */
    private function fields(array $traces): array
    {
        $fields = array_map(static fn (AuditEvent $e): string => $e->getField(), $traces);
        sort($fields);

        return $fields;
    }
}
