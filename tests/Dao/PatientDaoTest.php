<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\PatientDao;
use Aura\Domain\Patient;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Recherche de patients (liste et admission) et écriture conditionnelle, sur la base du CI.
 * Transaction annulée en tearDown ; ignoré sans DB_HOST/DB_NAME.
 */
final class PatientDaoTest extends TestCase
{
    private PDO $pdo;
    private PatientDao $dao;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->dao = new PatientDao($this->pdo);
        $this->dao->insert($this->patient('Zzlefèvre', 'Jean-Pierre', '987654401'));
        $this->dao->insert($this->patient('Zzlefèvre', 'Odette', '987654402'));
        $this->dao->insert($this->patient("Zzd'Arc", 'Jeanne', null, 'Zzromée'));
        $this->dao->insert($this->patient('Zzsous_ligne', 'Carla', null));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testSearchIgnoresCaseAccentsAndWordOrderAndFindsTheBirthName(): void
    {
        self::assertSame(
            ['Zzlefèvre Jean-Pierre', 'Zzlefèvre Odette'],
            $this->names($this->dao->searchByWords(['ZZLEFEVRE'], 21))
        );
        self::assertSame(['Zzlefèvre Odette'], $this->names($this->dao->searchByWords(['odette', 'zzlef'], 21)));
        $birthName = $this->dao->searchByWords(['zzrom'], 21);
        self::assertSame(["Zzd'Arc Jeanne"], $this->names($birthName), 'nom de naissance');
    }

    public function testWildcardsAndInjectionNeverWidenTheSearch(): void
    {
        self::assertCount(1, $this->dao->search('zzsous_ligne'));
        self::assertSame([], $this->dao->search('zzsous%'));
        self::assertSame([], $this->dao->search('%'));
        self::assertSame([], $this->dao->searchByWords(['%'], 21));
        self::assertSame([], $this->dao->search("' OR '1'='1"));
        self::assertNotEmpty($this->dao->search('zzlefèvre'), 'la table est intacte');
    }

    public function testConditionalWriteFailsWhenTheValueChangedMeanwhile(): void
    {
        $id = (int) $this->dao->findByIpp('987654401')?->getIdPatient();

        self::assertTrue($this->dao->updateDetailIfUnchanged($id, 'lifestyle', 'Non fumeur', null));
        self::assertFalse($this->dao->updateDetailIfUnchanged($id, 'lifestyle', 'Fumeur', null), 'valeur changée');
        self::assertSame('Non fumeur', $this->dao->findById($id)?->getDetail('lifestyle'));
    }

    /**
     * @param list<array{patient: Patient, lastAdmission: ?DateTimeImmutable}> $rows
     * @return list<string>
     */
    private function names(array $rows): array
    {
        return array_map(
            static fn (array $r): string => $r['patient']->getLastName() . ' ' . $r['patient']->getFirstName(),
            $rows
        );
    }

    private function patient(string $lastName, string $firstName, ?string $ipp, ?string $birthLastName = null): Patient
    {
        return new Patient(null, $ipp, $lastName, $firstName, new DateTimeImmutable('1970-01-01'), 'F', details: [
            'birthLastName' => $birthLastName,
        ]);
    }
}
