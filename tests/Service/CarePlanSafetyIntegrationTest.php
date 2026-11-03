<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\SubstanceDao;
use Aura\Dao\Transaction;
use Aura\Domain\AuditEvent;
use Aura\Service\CarePlanService;
use Aura\Service\PatientRecordService;
use Aura\Service\PrescriptionRejected;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Retours des médecins du 2026-11-03 sur base réelle (données de démo, transaction annulée en
 * tearDown) : le patient 1 (séjour 1) est allergique à la pénicilline, l'amoxicilline (MED04) en
 * contient. L'ordonnance est refusée sans confirmation (rien n'est écrit), acceptée avec, et la
 * confirmation est tracée ; la note est enregistrée ; la validation exige un mot de passe.
 */
final class CarePlanSafetyIntegrationTest extends TestCase
{
    private const EPISODE = 1;

    private PDO $pdo;
    private CarePlanService $service;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }
        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->service = new CarePlanService(
            new CarePlanDao($this->pdo),
            new ReferenceDataDao($this->pdo),
            new PatientRecordService(
                new EpisodeOfCareDao($this->pdo),
                new PatientDao($this->pdo),
                new AllergyIntoleranceDao($this->pdo),
                new SubstanceDao($this->pdo),
                new DateTimeImmutable('2026-10-14'),
                new ReferenceDataDao($this->pdo)
            ),
            new Transaction($this->pdo),
            new AuditEventDao($this->pdo)
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testAllergenRefusedWithoutConfirmationAndNothingWritten(): void
    {
        $before = $this->carePlanCount();

        try {
            $this->service->prescribe(self::EPISODE, self::amoxicillinOrder('PRA01', ''));
            self::fail('Une ordonnance avec un allergène du patient doit être refusée sans confirmation.');
        } catch (PrescriptionRejected $e) {
            self::assertSame([CarePlanService::ALLERGY_OVERRIDE], array_keys($e->errors));
            self::assertStringContainsString('Amoxicilline', $e->getMessage());
            self::assertStringContainsString('Pénicilline', $e->getMessage());
        }

        self::assertSame($before, $this->carePlanCount(), 'refus : aucune ordonnance écrite');
    }

    public function testAllergenAcceptedWithConfirmationWhichIsTraced(): void
    {
        $idCarePlan = $this->service->prescribe(self::EPISODE, self::amoxicillinOrder('PRA01', 'MED04'));

        $events = (new AuditEventDao($this->pdo))->findByEntity(AuditEvent::ENTITY_CARE_PLAN, (string) $idCarePlan);
        self::assertCount(1, $events);
        self::assertSame(CarePlanService::ALLERGY_OVERRIDE, $events[0]->getField());
        self::assertStringContainsString('Amoxicilline', (string) $events[0]->getNewValue());
        self::assertStringContainsString('Pénicilline', (string) $events[0]->getNewValue());
        self::assertStringContainsString('Morel', $events[0]->getAuthor(), 'prescripteur tracé');

        // Note enregistrée et visible ; la ligne est signalée « allergie » dans la liste du séjour.
        $listing = $this->service->listing(self::EPISODE);
        self::assertNotNull($listing);
        $lines = array_values(array_filter(
            $listing['lines'],
            static fn(array $line): bool => $line['idCarePlan'] === $idCarePlan
        ));
        self::assertCount(1, $lines);
        self::assertSame('Zz note : réévaluer à J3', $lines[0]['carePlanNote']);
        self::assertSame('Pénicilline', $lines[0]['allergen']);
    }

    public function testMedicationWithoutPatientAllergenNeedsNoConfirmationNorTrace(): void
    {
        $order = self::amoxicillinOrder('PRA01', '');
        $order['lines'][0]['idMedication'] = 'MED01';

        $idCarePlan = $this->service->prescribe(self::EPISODE, $order);

        self::assertSame(
            [],
            (new AuditEventDao($this->pdo))->findByEntity(AuditEvent::ENTITY_CARE_PLAN, (string) $idCarePlan)
        );
    }

    public function testPendingCarePlanValidationNeedsPassword(): void
    {
        // Infirmier, mode paramédical : ordonnance « non validée » (RG5), à valider par un médecin.
        $order = self::amoxicillinOrder('PRA04', '');
        $order['mode'] = 'PARAMEDICAL';
        $order['lines'][0]['idMedication'] = 'MED01';
        $idCarePlan = $this->service->prescribe(self::EPISODE, $order);

        foreach ([['PRA01', ''], ['PRA03', 'secret'], ['', 'secret']] as [$idValidator, $password]) {
            try {
                $this->service->validateCarePlan(self::EPISODE, $idCarePlan, $idValidator, $password);
                self::fail('Validation acceptée sans médecin authentifié.');
            } catch (PrescriptionRejected $e) {
                self::assertSame(['auth' => CarePlanService::AUTH_ERROR], $e->errors);
            }
        }
        self::assertNull($this->validator($idCarePlan), 'refus : rien n\'est écrit');

        $this->service->validateCarePlan(self::EPISODE, $idCarePlan, 'PRA01', 'secret');
        self::assertSame('PRA01', $this->validator($idCarePlan));
    }

    /** @return array<string, mixed> ordonnance d'une ligne d'amoxicilline (MED04), note comprise */
    public function testDoctorPrescriptionValidatedOnRecordNeedsThePassword(): void
    {
        $order = ['prescriberPassword' => ''] + self::amoxicillinOrder('PRA01', 'MED04');
        $before = $this->carePlanCount();
        try {
            $this->service->prescribe(self::EPISODE, $order);
            self::fail('une ordonnance validée d\'office sans mot de passe doit être refusée');
        } catch (PrescriptionRejected $e) {
            self::assertArrayHasKey('prescriberPassword', $e->errors);
        }
        self::assertSame($before, $this->carePlanCount(), 'rien n\'est écrit');
    }

    private static function amoxicillinOrder(string $idPrescriber, string $override): array
    {
        return [
            'mode' => 'MEDICAL',
            'idPrescriber' => $idPrescriber,
            'prescriberPassword' => 'x',
            'note' => 'Zz note : réévaluer à J3',
            CarePlanService::ALLERGY_OVERRIDE => $override,
            'lines' => [[
                'kind' => 'MEDICATION', 'idActivityDefinition' => '', 'idMedication' => 'MED04', 'dose' => '1 g',
                'route' => 'IV', 'frequencyType' => 'ONCE', 'frequencyHours' => '', 'frequencyDays' => '',
                'startDate' => '2026-10-14T10:00', 'endDate' => '',
            ]],
        ];
    }

    private function carePlanCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM CarePlan')->fetchColumn();
    }

    private function validator(int $idCarePlan): ?string
    {
        $stmt = $this->pdo->prepare('SELECT idValidator FROM CarePlan WHERE idCarePlan = :id');
        $stmt->execute(['id' => (string) $idCarePlan]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
