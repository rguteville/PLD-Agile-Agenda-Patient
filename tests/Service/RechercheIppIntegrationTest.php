<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Domain\Patient;
use Aura\Service\RechercheIppService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-15 : un IPP présent en base renvoie l'identité et les allergies du patient ; un IPP
 * absent ne renvoie rien. Test d'intégration sur la base MariaDB du CI (variables DB_*),
 * ignoré hors CI. Tout est exécuté dans une transaction annulée à la fin.
 */
final class RechercheIppIntegrationTest extends TestCase
{
    private PDO $pdo;
    private RechercheIppService $service;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->service = new RechercheIppService(new PatientDao($this->pdo), new AllergieDao($this->pdo));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testIppPresentEnBaseRenvoieIdentiteEtAllergies(): void
    {
        $idPatient = (new PatientDao($this->pdo))->insert(
            new Patient(null, '987654320', 'Zzrechercheipp', 'Alice', new DateTimeImmutable('1990-06-15'), 'F')
        );
        $this->pdo->exec("INSERT INTO Substance (idSubstance, description) VALUES ('T_IP', 'Iode test')");
        $this->pdo->prepare(
            'INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance, criticality) VALUES (?, ?, ?, ?)'
        )->execute(['9015', (string) $idPatient, 'T_IP', 'Élevée']);

        $vue = $this->service->rechercher('987654320');

        self::assertNotNull($vue);
        self::assertSame($idPatient, $vue['idPatient']);
        self::assertSame('Zzrechercheipp', $vue['nom']);
        self::assertSame('Alice', $vue['prenom']);
        self::assertSame('1990-06-15', $vue['dateNaissance']);
        self::assertSame('F', $vue['sexe']);
        self::assertSame([['libelle' => 'Iode test', 'criticite' => 'Élevée']], $vue['allergies']);
    }

    public function testIppAbsentNeRenvoieRien(): void
    {
        self::assertNull($this->service->rechercher('987654329'));
    }
}
