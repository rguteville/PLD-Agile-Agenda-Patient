<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Domain\Allergie;
use Aura\Domain\Patient;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-14 : les allergies du bandeau viennent de la base (tables Allergie / Allergene).
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
 */
final class AllergieDaoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testAllergiesDuPatientAvecLibelleParOrdreAlphabetique(): void
    {
        $idPatient = (new PatientDao($this->pdo))->insert(
            new Patient(null, null, 'Testallergie', 'Bob', new DateTimeImmutable('1970-05-05'), 'M')
        );
        $this->pdo->exec(
            "INSERT INTO Allergene (idAllergene, substance) VALUES ('T_ZZ', 'Zinc test'), ('T_AA', 'Arachide test')"
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO Allergie (idAllergie, idPatient, idAllergene, criticality)
             VALUES (:id, :idPatient, :idAllergene, :crit)'
        );
        $stmt->execute(['id' => 'T1', 'idPatient' => (string) $idPatient, 'idAllergene' => 'T_ZZ', 'crit' => 'sévère']);
        $stmt->execute(['id' => 'T2', 'idPatient' => (string) $idPatient, 'idAllergene' => 'T_AA', 'crit' => null]);

        $allergies = (new AllergieDao($this->pdo))->findByPatient($idPatient);

        $this->assertCount(2, $allergies);
        $this->assertContainsOnlyInstancesOf(Allergie::class, $allergies);
        $this->assertSame('Arachide test', $allergies[0]->getSubstance(), 'tri alphabétique sur le libellé');
        $this->assertNull($allergies[0]->getCriticite());
        $this->assertSame('Zinc test', $allergies[1]->getSubstance());
        $this->assertSame('sévère', $allergies[1]->getCriticite());
        $this->assertSame($idPatient, $allergies[1]->getIdPatient());
    }

    public function testPatientSansAllergieRenvoieUneListeVide(): void
    {
        $idPatient = (new PatientDao($this->pdo))->insert(
            new Patient(null, null, 'Testallergie', 'Zoe', new DateTimeImmutable('1980-01-01'), 'F')
        );

        $this->assertSame([], (new AllergieDao($this->pdo))->findByPatient($idPatient));
    }
}
