<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\PatientDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Le numéro de séjour est généré côté serveur au format AAAA-NNNNNN,
 * unique (contrainte UNIQUE en base), jamais saisi (RG1).
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
 */
final class EpisodeOfCareDaoTest extends TestCase
{
    private PDO $pdo;
    private EpisodeOfCareDao $dao;
    private int $idPatient;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();

        $this->dao = new EpisodeOfCareDao($this->pdo);
        $this->idPatient = (new PatientDao($this->pdo))->insert(
            new Patient(null, null, 'Testsejour', 'Alice', new DateTimeImmutable('1960-01-01'), 'F')
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testEpisodeNumberIsGeneratedUniqueInTheYearFormat(): void
    {
        $first = $this->dao->insert($this->newEpisodeOfCare('L1'));
        $second = $this->dao->insert($this->newEpisodeOfCare('L2'));

        $this->assertNotNull($first->getIdEpisodeOfCare());
        $year = $first->getAdmissionDate()->format('Y');
        $this->assertMatchesRegularExpression("/^{$year}-\\d{6}$/", $first->getEpisodeNumber(), 'format AAAA-NNNNNN');
        $this->assertNotSame($first->getEpisodeNumber(), $second->getEpisodeNumber(), 'deux séjours, deux numéros');
        $this->assertSame($this->counter($first) + 1, $this->counter($second), 'compteur incrémenté');
        $this->assertSame($first->getIdEpisodeOfCare() + 1, $second->getIdEpisodeOfCare());

        $reread = $this->dao->findById($first->getIdEpisodeOfCare());
        $this->assertNotNull($reread);
        $this->assertSame($first->getEpisodeNumber(), $reread->getEpisodeNumber());
        $this->assertSame($this->idPatient, $reread->getIdPatient());
        $this->assertSame('L1', $reread->getBedNumber());
        $this->assertNull($reread->getDischargeDate());
        $this->assertSame(
            $first->getAdmissionDate()->format('Y-m-d H:i:s'),
            $reread->getAdmissionDate()->format('Y-m-d H:i:s')
        );
    }

    public function testClosedEpisodeIsNoLongerOngoingButStaysInHistory(): void
    {
        $former = $this->dao->insert($this->newEpisodeOfCare(null, new DateTimeImmutable('-3 days')));
        $this->dao->updateDischarge($former->getIdEpisodeOfCare(), new DateTimeImmutable('-1 day'));
        $actuel = $this->dao->insert($this->newEpisodeOfCare('L3'));

        $ongoing = array_map(fn(EpisodeOfCare $s) => $s->getIdEpisodeOfCare(), $this->dao->findOngoing());
        $this->assertContains($actuel->getIdEpisodeOfCare(), $ongoing);
        $this->assertNotContains($former->getIdEpisodeOfCare(), $ongoing, 'un séjour clôturé n\'est plus en cours');

        $history = $this->dao->findByPatient($this->idPatient);
        $this->assertCount(2, $history);
        $this->assertSame(
            $actuel->getIdEpisodeOfCare(),
            $history[0]->getIdEpisodeOfCare(),
            'du plus récent au plus ancien'
        );
        $this->assertFalse($history[1]->isOngoing());
    }

    public function testCounterRestartsAtOneEachYear(): void
    {
        // test.sql ne contient aucun séjour en 1999.
        $episodeOfCare = $this->dao->insert(
            $this->newEpisodeOfCare(null, new DateTimeImmutable('1999-03-01 10:00:00'))
        );

        $this->assertSame('1999-000001', $episodeOfCare->getEpisodeNumber(), 'année d\'admission, pas année courante');
    }

    public function testNumberSentByTheCallerIsIgnored(): void
    {
        $episodeOfCare = $this->dao->insert($this->newEpisodeOfCare(null, null, 'SAISI-A-LA-MAIN'));

        $this->assertNotSame('SAISI-A-LA-MAIN', $episodeOfCare->getEpisodeNumber(), 'jamais saisi (RG1)');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{6}$/', $episodeOfCare->getEpisodeNumber());
    }

    public function testConditionalWriteFailsWhenTheValueChangedMeanwhile(): void
    {
        $this->insertPractitioner('ZZREF1', 'Zzclaire', 'Zzreferente');
        $id = (int) $this->dao->insert($this->newEpisodeOfCare('L6'))->getIdEpisodeOfCare();

        self::assertTrue($this->dao->updateDetailIfUnchanged($id, 'idReferringPractitioner', 'ZZREF1', null));
        self::assertFalse(
            $this->dao->updateDetailIfUnchanged($id, 'idReferringPractitioner', null, null),
            'la valeur attendue (à désigner) n\'est plus celle en base'
        );
        self::assertSame('ZZREF1', $this->dao->findById($id)?->getIdReferringPractitioner());
    }

    /** Praticien de test sans rôle, annulé avec la transaction du test. */
    private function insertPractitioner(string $id, string $firstName, string $lastName): void
    {
        $this->pdo->prepare(
            'INSERT INTO Practitioner (idPractitioner, firstName, lastName) VALUES (:id, :firstName, :lastName)'
        )->execute(['id' => $id, 'firstName' => $firstName, 'lastName' => $lastName]);
    }

    /** Partie NNNNNN du numéro AAAA-NNNNNN. */
    private function counter(EpisodeOfCare $s): int
    {
        return (int) substr($s->getEpisodeNumber(), 5);
    }

    private function newEpisodeOfCare(
        ?string $bedNumber,
        ?DateTimeImmutable $admissionDate = null,
        string $episodeNumber = ''
    ): EpisodeOfCare {
        return new EpisodeOfCare(
            idEpisodeOfCare: null,
            idPatient: $this->idPatient,
            episodeNumber: $episodeNumber,
            admissionDate: $admissionDate ?? new DateTimeImmutable(),
            bedNumber: $bedNumber
        );
    }
}
