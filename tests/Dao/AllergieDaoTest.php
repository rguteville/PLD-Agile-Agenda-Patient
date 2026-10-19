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
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * AURA-14 : les allergies du bandeau viennent de la base (tables Allergie / Allergene).
 * AURA-13.1 : contraintes du schéma (couple patient / allergène actif unique, substance
 * unique, ON DELETE RESTRICT), suppression logique et date de saisie remplie par la base.
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
            "INSERT INTO Substance (idSubstance, description) VALUES ('T_ZZ', 'Zinc test'), ('T_AA', 'Arachide test')"
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance, criticality)
             VALUES (:id, :idPatient, :idAllergene, :crit)'
        );
        $idTexte = (string) $idPatient;
        $stmt->execute(['id' => '9001', 'idPatient' => $idTexte, 'idAllergene' => 'T_ZZ', 'crit' => 'sévère']);
        $stmt->execute(['id' => '9002', 'idPatient' => $idTexte, 'idAllergene' => 'T_AA', 'crit' => null]);

        $allergies = (new AllergieDao($this->pdo))->findByPatient($idPatient);

        $this->assertCount(2, $allergies);
        $this->assertContainsOnlyInstancesOf(Allergie::class, $allergies);
        $this->assertSame('Arachide test', $allergies[0]->getSubstance(), 'tri alphabétique sur le libellé');
        $this->assertNull($allergies[0]->getCriticite());
        $this->assertSame('Zinc test', $allergies[1]->getSubstance());
        $this->assertSame('sévère', $allergies[1]->getCriticite());
        $this->assertSame($idPatient, $allergies[1]->getIdPatient());
        $this->assertSame(9001, $allergies[1]->getIdAllergie());
    }

    public function testDateDeSaisieRemplieParLaBaseEtLue(): void
    {
        $idPatient = $this->insererPatient('Ana');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, description) VALUES ('T_DA', 'Date test')");
        $avant = $this->maintenantEnBase();
        $this->insererAllergie('9001', $idPatient, 'T_DA');

        $allergies = (new AllergieDao($this->pdo))->findByPatient($idPatient);

        $this->assertCount(1, $allergies);
        $dateSaisie = $allergies[0]->getDateSaisie();
        $this->assertInstanceOf(DateTimeImmutable::class, $dateSaisie, 'DEFAULT CURRENT_TIMESTAMP');
        $this->assertGreaterThanOrEqual($avant, $dateSaisie);
    }

    public function testMemeCoupleDeuxFoisRefuseParLaBase(): void
    {
        $idPatient = $this->insererPatient('Doublon');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, description) VALUES ('T_DB', 'Doublon test')");
        $this->insererAllergie('9001', $idPatient, 'T_DB');

        $this->assertErreurMariaDb(1062, fn() => $this->insererAllergie('9002', $idPatient, 'T_DB'));
        $this->assertCount(1, (new AllergieDao($this->pdo))->findByPatient($idPatient));
    }

    public function testAllergieRetireeMasqueeEtSaisissableANouveau(): void
    {
        $idPatient = $this->insererPatient('Retrait');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, description) VALUES ('T_RT', 'Retrait test')");
        $this->insererAllergie('9001', $idPatient, 'T_RT');
        $this->pdo->exec("UPDATE AllergyIntolerance SET removedAt = NOW() WHERE idAllergy = '9001'");
        $dao = new AllergieDao($this->pdo);

        $this->assertSame([], $dao->findByPatient($idPatient), 'une allergie retirée n\'est plus affichée');

        $this->insererAllergie('9002', $idPatient, 'T_RT');
        $this->assertSame(9002, $dao->findByPatient($idPatient)[0]->getIdAllergie());
        $this->assertSame(
            2,
            (int) $this->pdo
                ->query("SELECT COUNT(*) FROM AllergyIntolerance WHERE idSubstance = 'T_RT'")
                ->fetchColumn(),
            'la ligne retirée reste en base'
        );
    }

    public function testMemeAllergeneChezDeuxPatientsAccepte(): void
    {
        $idPatient1 = $this->insererPatient('Un');
        $idPatient2 = $this->insererPatient('Deux');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, description) VALUES ('T_PA', 'Partage test')");
        $this->insererAllergie('9001', $idPatient1, 'T_PA');
        $this->insererAllergie('9002', $idPatient2, 'T_PA');

        $this->assertCount(1, (new AllergieDao($this->pdo))->findByPatient($idPatient2));
    }

    public function testSubstanceEnDoubleRefuseeParLaBase(): void
    {
        $this->pdo->exec("INSERT INTO Substance (idSubstance, description) VALUES ('T_S1', 'Substance test')");

        $this->assertErreurMariaDb(
            1062,
            fn() => $this->pdo->exec(
                "INSERT INTO Substance (idSubstance, description) VALUES ('T_S2', 'Substance test')"
            )
        );
    }

    public function testPatientAvecAllergieNonSupprimable(): void
    {
        $idPatient = $this->insererPatient('Restrict');
        $this->pdo->exec("INSERT INTO Substance (idSubstance, description) VALUES ('T_RE', 'Restrict test')");
        $this->insererAllergie('9001', $idPatient, 'T_RE');

        $this->assertErreurMariaDb(
            1451,
            fn() => $this->pdo->exec("DELETE FROM Patient WHERE idPatient = '{$idPatient}'")
        );
        $this->assertErreurMariaDb(
            1451,
            fn() => $this->pdo->exec("DELETE FROM Substance WHERE idSubstance = 'T_RE'")
        );
    }

    public function testPatientSansAllergieRenvoieUneListeVide(): void
    {
        $idPatient = (new PatientDao($this->pdo))->insert(
            new Patient(null, null, 'Testallergie', 'Zoe', new DateTimeImmutable('1980-01-01'), 'F')
        );

        $this->assertSame([], (new AllergieDao($this->pdo))->findByPatient($idPatient));
    }

    private function insererPatient(string $prenom): int
    {
        return (new PatientDao($this->pdo))->insert(
            new Patient(null, null, 'Testallergie', $prenom, new DateTimeImmutable('1975-03-03'), 'F')
        );
    }

    private function insererAllergie(string $id, int $idPatient, string $idAllergene): void
    {
        $this->pdo->prepare('INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance) VALUES (?, ?, ?)')
            ->execute([$id, (string) $idPatient, $idAllergene]);
    }

    /** Heure de la base (et non de PHP) arrondie à la seconde, comme une colonne DATETIME. */
    private function maintenantEnBase(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->pdo->query('SELECT NOW()')->fetchColumn());
    }

    /** Vérifie que l'instruction échoue avec ce code d'erreur MariaDB (1062 doublon, 1451 clé étrangère). */
    private function assertErreurMariaDb(int $code, callable $instruction): void
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
