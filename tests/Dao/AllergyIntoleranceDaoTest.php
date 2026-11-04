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
use PHPUnit\Framework\TestCase;

/**
 * Allergies du bandeau (RG2) : ajout relu avec son libellé, retrait limité au patient du dossier.
 * Le doublon patient / allergène est refusé par la base (SchemaConstraintsTest).
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

    public function testInsertedAllergyIsReadBackWithItsLabelAndCriticality(): void
    {
        $idPatient = $this->insertPatient('Insert');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, name) VALUES ('T_IN', 'Zz allergène')");
        $dao = new AllergyIntoleranceDao($this->pdo);

        $id = $dao->insert(new AllergyIntolerance(null, $idPatient, 'T_IN', 'Zz allergène', 'Élevée'));

        $allergies = $dao->findByPatient($idPatient);
        self::assertCount(1, $allergies);
        self::assertSame($id, $allergies[0]->getIdAllergyIntolerance());
        self::assertSame('Zz allergène', $allergies[0]->getSubstance(), 'libellé lu depuis le référentiel');
        self::assertSame('Élevée', $allergies[0]->getCriticality());
        self::assertNotNull($allergies[0]->getRecordedAt(), 'date de saisie remplie par la base');
    }

    public function testAllergyIsRemovedOnlyFromItsOwnPatient(): void
    {
        $idPatient = $this->insertPatient('Proprietaire');
        $otherId = $this->insertPatient('Autre');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, name) VALUES ('T_AP', 'Zz allergène')");
        $dao = new AllergyIntoleranceDao($this->pdo);
        $id = $dao->insert(new AllergyIntolerance(null, $idPatient, 'T_AP', 'Zz allergène'));

        self::assertFalse($dao->delete($id, $otherId), 'un id forgé ne touche pas le dossier d\'un autre patient');
        self::assertCount(1, $dao->findByPatient($idPatient));
        self::assertTrue($dao->delete($id, $idPatient));
        self::assertSame([], $dao->findByPatient($idPatient));
    }

    private function insertPatient(string $firstName): int
    {
        return (new PatientDao($this->pdo))->insert(
            new Patient(null, null, 'Testallergie', $firstName, new DateTimeImmutable('1975-03-03'), 'F')
        );
    }
}
