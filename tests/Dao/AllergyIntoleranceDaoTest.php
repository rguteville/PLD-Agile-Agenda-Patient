<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\PatientDao;
use Aura\Domain\AllergyIntolerance;
use Aura\Domain\Patient;
use DateTimeImmutable;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * AURA-14 : les allergies du bandeau viennent de la base (tables Allergie / Allergene).
 * AURA-13.1 : contraintes du schéma (couple patient / allergène unique, substance unique,
 * ON DELETE RESTRICT) et date de saisie remplie par la base.
 * AURA-13.2 : insertion (id calculé) et retrait.
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
 */
final class AllergyIntoleranceDaoTest extends TestCase
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

    public function testPatientAllergiesWithLabelInAlphabeticalOrder(): void
    {
        $idPatient = (new PatientDao($this->pdo))->insert(
            new Patient(null, null, 'Testallergie', 'Bob', new DateTimeImmutable('1970-05-05'), 'M')
        );
        $this->pdo->exec(
            "INSERT INTO Substance (idSubstance, name) VALUES ('T_ZZ', 'Zinc test'), ('T_AA', 'Arachide test')"
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance, criticality)
             VALUES (:id, :idPatient, :idSubstance, :crit)'
        );
        $textId = (string) $idPatient;
        $stmt->execute(['id' => '9001', 'idPatient' => $textId, 'idSubstance' => 'T_ZZ', 'crit' => 'sévère']);
        $stmt->execute(['id' => '9002', 'idPatient' => $textId, 'idSubstance' => 'T_AA', 'crit' => null]);

        $allergies = (new AllergyIntoleranceDao($this->pdo))->findByPatient($idPatient);

        $this->assertCount(2, $allergies);
        $this->assertContainsOnlyInstancesOf(AllergyIntolerance::class, $allergies);
        $this->assertSame('Arachide test', $allergies[0]->getSubstance(), 'tri alphabétique sur le libellé');
        $this->assertNull($allergies[0]->getCriticality());
        $this->assertSame('Zinc test', $allergies[1]->getSubstance());
        $this->assertSame('sévère', $allergies[1]->getCriticality());
        $this->assertSame($idPatient, $allergies[1]->getIdPatient());
        $this->assertSame(9001, $allergies[1]->getIdAllergyIntolerance());
    }

    public function testRecordedAtFilledByDatabaseAndRead(): void
    {
        $idPatient = $this->insertPatient('Ana');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, name) VALUES ('T_DA', 'Date test')");
        $before = $this->nowInDatabase();
        $this->insertAllergy('9001', $idPatient, 'T_DA');

        $allergies = (new AllergyIntoleranceDao($this->pdo))->findByPatient($idPatient);

        $this->assertCount(1, $allergies);
        $recordedAt = $allergies[0]->getRecordedAt();
        $this->assertInstanceOf(DateTimeImmutable::class, $recordedAt, 'DEFAULT CURRENT_TIMESTAMP');
        $this->assertGreaterThanOrEqual($before, $recordedAt);
    }

    public function testSamePairTwiceRejectedByDatabase(): void
    {
        $idPatient = $this->insertPatient('Doublon');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, name) VALUES ('T_DB', 'Doublon test')");
        $this->insertAllergy('9001', $idPatient, 'T_DB');

        $this->assertMariaDbError(1062, fn() => $this->insertAllergy('9002', $idPatient, 'T_DB'));
        $this->assertCount(1, (new AllergyIntoleranceDao($this->pdo))->findByPatient($idPatient));
    }

    public function testSameAllergenForTwoPatientsAccepted(): void
    {
        $idPatient1 = $this->insertPatient('Un');
        $idPatient2 = $this->insertPatient('Deux');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, name) VALUES ('T_PA', 'Partage test')");
        $this->insertAllergy('9001', $idPatient1, 'T_PA');
        $this->insertAllergy('9002', $idPatient2, 'T_PA');

        $this->assertCount(1, (new AllergyIntoleranceDao($this->pdo))->findByPatient($idPatient2));
    }

    public function testDuplicateSubstanceRejectedByDatabase(): void
    {
        $this->pdo->exec("INSERT INTO Substance (idSubstance, name) VALUES ('T_S1', 'Substance test')");

        $this->assertMariaDbError(
            1062,
            fn() => $this->pdo->exec(
                "INSERT INTO Substance (idSubstance, name) VALUES ('T_S2', 'Substance test')"
            )
        );
    }

    public function testPatientWithAllergyCannotBeDeleted(): void
    {
        $idPatient = $this->insertPatient('Restrict');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, name) VALUES ('T_RE', 'Restrict test')");
        $this->insertAllergy('9001', $idPatient, 'T_RE');

        $this->assertMariaDbError(
            1451,
            fn() => $this->pdo->exec("DELETE FROM Patient WHERE idPatient = '{$idPatient}'")
        );
        $this->assertMariaDbError(
            1451,
            fn() => $this->pdo->exec("DELETE FROM Substance WHERE idSubstance = 'T_RE'")
        );
    }

    public function testPatientWithoutAllergyReturnsEmptyList(): void
    {
        $idPatient = (new PatientDao($this->pdo))->insert(
            new Patient(null, null, 'Testallergie', 'Zoe', new DateTimeImmutable('1980-01-01'), 'F')
        );

        $this->assertSame([], (new AllergyIntoleranceDao($this->pdo))->findByPatient($idPatient));
    }

    /** AURA-13.2 : id calculé comme PatientDao::insert() (MAX + 1), date de saisie laissée à la base. */
    public function testInsertComputesIdAndLeavesDateToDatabase(): void
    {
        $idPatient = $this->insertPatient('Insert');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, name) VALUES ('T_IN', 'Insert test')");
        $dao = new AllergyIntoleranceDao($this->pdo);
        $expectedId = (int) $this->pdo
            ->query('SELECT COALESCE(MAX(CAST(idAllergy AS UNSIGNED)), 0) + 1 FROM AllergyIntolerance')
            ->fetchColumn();
        $before = $this->nowInDatabase();

        $id = $dao->insert(new AllergyIntolerance(null, $idPatient, 'T_IN', 'Insert test', 'Élevée'));

        $this->assertSame($expectedId, $id);
        $allergies = $dao->findByPatient($idPatient);
        $this->assertCount(1, $allergies);
        $this->assertSame($id, $allergies[0]->getIdAllergyIntolerance());
        $this->assertSame('T_IN', $allergies[0]->getIdSubstance());
        $this->assertSame('Insert test', $allergies[0]->getSubstance(), 'libellé lu depuis le référentiel');
        $this->assertSame('Élevée', $allergies[0]->getCriticality());
        $this->assertGreaterThanOrEqual($before, $allergies[0]->getRecordedAt(), 'recordedAt rempli par la base');
    }

    public function testInsertWithoutCriticality(): void
    {
        $idPatient = $this->insertPatient('SansCrit');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, name) VALUES ('T_NC', 'Sans criticité test')");
        $dao = new AllergyIntoleranceDao($this->pdo);

        $dao->insert(new AllergyIntolerance(null, $idPatient, 'T_NC', 'Sans criticité test'));

        $this->assertNull($dao->findByPatient($idPatient)[0]->getCriticality());
    }

    public function testInsertSameAllergenTwiceRejectedByDatabase(): void
    {
        $idPatient = $this->insertPatient('DuplicateDao');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, name) VALUES ('T_DD', 'Doublon dao test')");
        $dao = new AllergyIntoleranceDao($this->pdo);
        $dao->insert(new AllergyIntolerance(null, $idPatient, 'T_DD', 'Doublon dao test'));

        $this->assertMariaDbError(
            1062,
            fn() => $dao->insert(new AllergyIntolerance(null, $idPatient, 'T_DD', 'Doublon dao test', 'Faible'))
        );
    }

    public function testInsertAllergenOutsideReferenceListRejectedByDatabase(): void
    {
        $idPatient = $this->insertPatient('Inconnu');

        $this->assertMariaDbError(
            1452,
            fn() => (new AllergyIntoleranceDao($this->pdo))->insert(
                new AllergyIntolerance(null, $idPatient, 'T_ABSENT', 'Absent')
            )
        );
    }

    /** AURA-13.2 : retrait (suppression physique, décision d'équipe), limité au patient. */
    public function testDeleteRemovesOnlyRequestedAllergy(): void
    {
        $idPatient = $this->insertPatient('Delete');
        $this->pdo->exec(
            "INSERT INTO Substance (idSubstance, name)
             VALUES ('T_D1', 'Delete un test'), ('T_D2', 'Delete deux test')"
        );
        $dao = new AllergyIntoleranceDao($this->pdo);
        $id1 = $dao->insert(new AllergyIntolerance(null, $idPatient, 'T_D1', 'Delete un test'));
        $dao->insert(new AllergyIntolerance(null, $idPatient, 'T_D2', 'Delete deux test'));

        $this->assertTrue($dao->delete($id1, $idPatient));

        $remaining = $dao->findByPatient($idPatient);
        $this->assertCount(1, $remaining);
        $this->assertSame('T_D2', $remaining[0]->getIdSubstance());
        $this->assertFalse($dao->delete($id1, $idPatient), 'déjà retirée : rien à supprimer');
    }

    public function testDeleteIgnoresAnotherPatientsAllergy(): void
    {
        $idPatient = $this->insertPatient('Proprietaire');
        $otherId = $this->insertPatient('Autre');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, name) VALUES ('T_AP', 'Autre patient test')");
        $dao = new AllergyIntoleranceDao($this->pdo);
        $id = $dao->insert(new AllergyIntolerance(null, $idPatient, 'T_AP', 'Autre patient test'));

        $this->assertFalse($dao->delete($id, $otherId), 'un id forgé ne touche pas le dossier d\'un autre patient');
        $this->assertCount(1, $dao->findByPatient($idPatient));
    }

    private function insertPatient(string $firstName): int
    {
        return (new PatientDao($this->pdo))->insert(
            new Patient(null, null, 'Testallergie', $firstName, new DateTimeImmutable('1975-03-03'), 'F')
        );
    }

    private function insertAllergy(string $id, int $idPatient, string $idSubstance): void
    {
        $this->pdo->prepare('INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance) VALUES (?, ?, ?)')
            ->execute([$id, (string) $idPatient, $idSubstance]);
    }

    /** Heure de la base (et non de PHP) arrondie à la seconde, comme une colonne DATETIME. */
    private function nowInDatabase(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->pdo->query('SELECT NOW()')->fetchColumn());
    }

    /** Vérifie que l'instruction échoue avec ce code d'erreur MariaDB (1062 doublon, 1451 clé étrangère). */
    private function assertMariaDbError(int $code, callable $instruction): void
    {
        try {
            $instruction();
        } catch (PDOException $e) {
            $this->assertSame($code, $e->errorInfo[1] ?? null, $e->getMessage());
            return;
        }
        $this->fail("L'instruction aurait dû échouer avec l'erreur MariaDB {$code}.");
    }
}
