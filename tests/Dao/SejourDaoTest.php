<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\PatientDao;
use Aura\Dao\SejourDao;
use Aura\Domain\Patient;
use Aura\Domain\Sejour;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-10.3 : le numéro de séjour est généré côté serveur, unique, jamais saisi (RG1).
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
 */
final class SejourDaoTest extends TestCase
{
    private PDO $pdo;
    private SejourDao $dao;
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

        $this->dao = new SejourDao($this->pdo);
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

    public function testLeNumeroDeSejourEstGenereUniqueEtRelu(): void
    {
        $premier = $this->dao->insert($this->nouveauSejour('L1'));
        $second = $this->dao->insert($this->nouveauSejour('L2'));

        $this->assertNotNull($premier->getIdSejour());
        $this->assertMatchesRegularExpression('/^\d{9}$/', $premier->getNumSejour(), 'numéro sur 9 chiffres');
        $this->assertSame(str_pad((string) $premier->getIdSejour(), 9, '0', STR_PAD_LEFT), $premier->getNumSejour());
        $this->assertNotSame($premier->getNumSejour(), $second->getNumSejour(), 'deux séjours, deux numéros');
        $this->assertSame($premier->getIdSejour() + 1, $second->getIdSejour());

        $relu = $this->dao->findById($premier->getIdSejour());
        $this->assertNotNull($relu);
        $this->assertSame($premier->getNumSejour(), $relu->getNumSejour());
        $this->assertSame($this->idPatient, $relu->getIdPatient());
        $this->assertSame('L1', $relu->getNumLit());
        $this->assertNull($relu->getDateSortie());
        $this->assertSame(
            $premier->getDateAdmission()->format('Y-m-d H:i:s'),
            $relu->getDateAdmission()->format('Y-m-d H:i:s')
        );
    }

    public function testSejoursEnCoursEtHistoriqueDuPatient(): void
    {
        $ancien = $this->dao->insert($this->nouveauSejour(null, new DateTimeImmutable('-3 days')));
        $this->dao->updateSortie($ancien->getIdSejour(), new DateTimeImmutable('-1 day'));
        $actuel = $this->dao->insert($this->nouveauSejour('L3'));

        $enCours = array_map(fn(Sejour $s) => $s->getIdSejour(), $this->dao->findEnCours());
        $this->assertContains($actuel->getIdSejour(), $enCours);
        $this->assertNotContains($ancien->getIdSejour(), $enCours, 'un séjour clôturé n\'est plus en cours');

        $historique = $this->dao->findByPatient($this->idPatient);
        $this->assertCount(2, $historique);
        $this->assertSame($actuel->getIdSejour(), $historique[0]->getIdSejour(), 'du plus récent au plus ancien');
        $this->assertFalse($historique[1]->estEnCours());
    }

    private function nouveauSejour(?string $numLit, ?DateTimeImmutable $dateAdmission = null): Sejour
    {
        return new Sejour(
            idSejour: null,
            idPatient: $this->idPatient,
            numSejour: '',
            dateAdmission: $dateAdmission ?? new DateTimeImmutable(),
            numLit: $numLit
        );
    }
}
