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
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * AURA-10.3 : le numéro de séjour est généré côté serveur au format AAAA-NNNNNN,
 * unique (contrainte UNIQUE en base), jamais saisi (RG1).
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
        $annee = $premier->getDateAdmission()->format('Y');
        $this->assertMatchesRegularExpression("/^{$annee}-\\d{6}$/", $premier->getNumSejour(), 'format AAAA-NNNNNN');
        $this->assertNotSame($premier->getNumSejour(), $second->getNumSejour(), 'deux séjours, deux numéros');
        $this->assertSame($this->compteur($premier) + 1, $this->compteur($second), 'compteur incrémenté');
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

    public function testUnSejourEstReluParSonNumero(): void
    {
        $sejour = $this->dao->insert($this->nouveauSejour('L1'));

        $relu = $this->dao->findByNumber($sejour->getNumSejour());
        $this->assertNotNull($relu, 'findByNumber (agenda, AURA-24.2) lit la table EpisodeOfCare');
        $this->assertSame($sejour->getIdSejour(), $relu->getIdSejour());
        $this->assertNull($this->dao->findByNumber('1900-000000'), 'numéro inconnu : null');
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

    public function testLeCompteurRepartAUnPourUneAnneeSansSejour(): void
    {
        // test.sql ne contient aucun séjour en 1999.
        $sejour = $this->dao->insert($this->nouveauSejour(null, new DateTimeImmutable('1999-03-01 10:00:00')));

        $this->assertSame('1999-000001', $sejour->getNumSejour(), 'année d\'admission, pas année courante');
    }

    public function testUnNumeroFourniParLAppelantEstIgnore(): void
    {
        $sejour = $this->dao->insert($this->nouveauSejour(null, null, 'SAISI-A-LA-MAIN'));

        $this->assertNotSame('SAISI-A-LA-MAIN', $sejour->getNumSejour(), 'jamais saisi (RG1)');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{6}$/', $sejour->getNumSejour());
    }

    public function testUnDoublonForceEnSqlEstRefuseParLaBase(): void
    {
        $existant = $this->dao->insert($this->nouveauSejour('L4'));

        try {
            $this->pdo->prepare(
                'INSERT INTO EpisodeOfCare (idEpisodeOfCare, idPatient, episodeNumber, admissionDate)
                 VALUES (:idSejour, :idPatient, :sejourNumber, NOW())'
            )->execute([
                'idSejour' => (string) ($existant->getIdSejour() + 1000),
                'idPatient' => (string) $this->idPatient,
                'sejourNumber' => $existant->getNumSejour(),
            ]);
            $this->fail('La contrainte UNIQUE aurait dû refuser le doublon.');
        } catch (PDOException $e) {
            $this->assertSame(1062, $e->errorInfo[1], 'Duplicate entry');
        }
    }

    /** Partie NNNNNN du numéro AAAA-NNNNNN. */
    private function compteur(Sejour $s): int
    {
        return (int) substr($s->getNumSejour(), 5);
    }

    private function nouveauSejour(
        ?string $numLit,
        ?DateTimeImmutable $dateAdmission = null,
        string $numSejour = ''
    ): Sejour {
        return new Sejour(
            idSejour: null,
            idPatient: $this->idPatient,
            numSejour: $numSejour,
            dateAdmission: $dateAdmission ?? new DateTimeImmutable(),
            numLit: $numLit
        );
    }
}
