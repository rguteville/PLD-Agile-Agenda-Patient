<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\SubstanceDao;
use Aura\Domain\Substance;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-13.2 : lecture du référentiel des allergènes (table Substance) pour la liste
 * déroulante d'ajout et le contrôle serveur « l'allergène existe » (critère 1).
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
 */
final class SubstanceDaoTest extends TestCase
{
    private PDO $pdo;
    private SubstanceDao $dao;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->dao = new SubstanceDao($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testFindAllSortedByDescription(): void
    {
        $this->pdo->exec(
            "INSERT INTO Substance (idSubstance, description)
             VALUES ('T_ZZ', 'Zz allergene test'), ('T_AA', 'Aa allergene test')"
        );

        $substances = $this->dao->findAll();

        $this->assertContainsOnlyInstancesOf(Substance::class, $substances);
        $descriptions = array_map(fn(Substance $s) => $s->getDescription(), $substances);
        $this->assertSame('Aa allergene test', $descriptions[0], 'le libellé le plus petit vient en premier');
        $this->assertSame('Zz allergene test', end($descriptions), 'le plus grand en dernier');
        $this->assertContains('T_AA', array_map(fn(Substance $s) => $s->getIdSubstance(), $substances));
    }

    public function testFindById(): void
    {
        $this->pdo->exec("INSERT INTO Substance (idSubstance, description) VALUES ('T_ID', 'Identifiant test')");

        $substance = $this->dao->findById('T_ID');

        $this->assertNotNull($substance);
        $this->assertSame('T_ID', $substance->getIdSubstance());
        $this->assertSame('Identifiant test', $substance->getDescription());
    }

    public function testFindByIdUnknownReturnsNull(): void
    {
        $this->assertNull($this->dao->findById('T_INEXISTANT'));
        $this->assertNull($this->dao->findById('Pénicilline'), 'un libellé en texte libre n\'est pas un identifiant');
    }
}
