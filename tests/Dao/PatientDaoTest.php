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
 * AURA-12.1 et AURA-12.2 : recherche de patients par nom,
 * prénom ou IPP en requête préparée et modification d'un patient.
 *
 * Test d'intégration sur la base MariaDB du CI (variables DB_*), ignoré hors CI.
 * Tout est exécuté dans une transaction annulée à la fin : la base n'est pas modifiée.
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

        // Noms volontairement improbables pour ne pas dépendre du contenu de la base.
        $this->dao->insert($this->patient('Zzduprecherche', 'Alice', '987654301'));
        $this->dao->insert($this->patient('Zzmartinrecherche', 'Bob', '987654302'));
        $this->dao->insert($this->patient('Zzsous_ligne', 'Carla', null));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testRechercheParFragmentDeNomSansTenirCompteDeLaCasse(): void
    {
        $noms = array_map(fn(Patient $p) => $p->getNom(), $this->dao->search('DUPRECHERCHE'));

        self::assertSame(['Zzduprecherche'], $noms);
    }

    public function testRechercheParPrenomEtParIpp(): void
    {
        self::assertSame('Bob', $this->dao->search('bob')[0]->getPrenom());
        self::assertSame('987654302', $this->dao->search('987654302')[0]->getIpp());
    }

    public function testLesJokersSqlSontEchappes(): void
    {
        // « _ » est un joker LIKE : sans échappement, « zzsous_ligne » et « zzsousXligne » matcheraient tous deux.
        self::assertCount(1, $this->dao->search('zzsous_ligne'));
        self::assertSame([], $this->dao->search('zzsous%'), 'le joker % saisi ne doit pas élargir la recherche');
        self::assertSame([], $this->dao->search('%'), 'un simple % ne doit pas renvoyer toute la table');
    }

    public function testUneTentativeDInjectionNeRenvoieRien(): void
    {
        self::assertSame([], $this->dao->search("' OR '1'='1"));
        self::assertSame([], $this->dao->search("zz'; DROP TABLE Patient; --"));
        // La table est toujours là : la requête précédente n'a rien exécuté d'autre qu'un SELECT.
        self::assertNotEmpty($this->dao->search('zzmartinrecherche'));
    }

    public function testRechercheSansResultat(): void
    {
        self::assertSame([], $this->dao->search('zzaucunpatientnesappelleainsi'));
    }

    public function testUpdateChampsPatient(): void
    {
        $id = $this->dao->insert($this->patient('Zzdupdate', 'Alice', '987654303'));

        $this->dao->update(new Patient(
            idPatient: $id,
            ipp: '987654304',
            nom: 'Zzdupdate2',
            prenom: 'Alice2',
            dateNaissance: new DateTimeImmutable('1985-06-15'),
            sexe: 'M'
        ));

        $relu = $this->dao->findById($id);
        self::assertNotNull($relu);
        self::assertSame('Zzdupdate2', $relu->getNom());
        self::assertSame('Alice2', $relu->getPrenom());
        self::assertSame('987654304', $relu->getIpp());
        self::assertSame('1985-06-15', $relu->getDateNaissance()?->format('Y-m-d'));
        self::assertSame('M', $relu->getSexe());
    }

    public function testUpdateAccepteUnIppVide(): void
    {
        $id = $this->dao->insert($this->patient('Zzdupdate5', 'Chloé', '987654307'));

        $this->dao->update(new Patient(
            $id,
            null,
            'Zzdupdate5',
            'Chloé',
            new DateTimeImmutable('1970-01-01'),
            'F'
        ));

        self::assertNull($this->dao->findById($id)?->getIpp());
    }

    public function testUpdateNeToucheQueLePatientVise(): void
    {
        $id1 = $this->dao->insert($this->patient('Zzdupdate3', 'Alice', '987654305'));
        $id2 = $this->dao->insert($this->patient('Zzdupdate4', 'Bob', '987654306'));

        $this->dao->update(new Patient(
            $id1,
            '987654305',
            'Zzdupdate3modifie',
            'Alice',
            new DateTimeImmutable('1970-01-01'),
            'F'
        ));

        self::assertSame('Zzdupdate3modifie', $this->dao->findById($id1)?->getNom());
        self::assertSame('Zzdupdate4', $this->dao->findById($id2)?->getNom());
    }

    private function patient(string $nom, string $prenom, ?string $ipp): Patient
    {
        return new Patient(null, $ipp, $nom, $prenom, new DateTimeImmutable('1970-01-01'), 'F');
    }
}
