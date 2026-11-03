<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\PatientDao;
use Aura\Domain\Patient;
use Aura\Service\IppSearchService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-15 : un IPP présent en base renvoie l'identité et les allergies du patient ; un IPP
 * absent ne renvoie rien. Test d'intégration sur la base MariaDB du CI (variables DB_*),
 * ignoré hors CI. Tout est exécuté dans une transaction annulée à la fin.
 */
final class IppSearchIntegrationTest extends TestCase
{
    private PDO $pdo;
    private IppSearchService $service;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->service = new IppSearchService(new PatientDao($this->pdo), new AllergyIntoleranceDao($this->pdo));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testIppInDatabaseReturnsIdentityAndAllergies(): void
    {
        $idPatient = (new PatientDao($this->pdo))->insert(
            new Patient(null, '987654320', 'Zzrechercheipp', 'Alice', new DateTimeImmutable('1990-06-15'), 'F')
        );
        $this->pdo->exec("INSERT INTO Substance (idSubstance, description) VALUES ('T_IP', 'Iode test')");
        $this->pdo->prepare(
            'INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance, criticality) VALUES (?, ?, ?, ?)'
        )->execute(['9015', (string) $idPatient, 'T_IP', 'Élevée']);

        $view = $this->service->search('987654320');

        self::assertNotNull($view);
        self::assertSame($idPatient, $view['idPatient']);
        self::assertSame('Zzrechercheipp', $view['lastName']);
        self::assertSame('Alice', $view['firstName']);
        self::assertSame('1990-06-15', $view['birthDate']);
        self::assertSame('F', $view['sex']);
        self::assertSame([['label' => 'Iode test', 'criticality' => 'Élevée']], $view['allergies']);
    }

    public function testMissingIppReturnsNothing(): void
    {
        self::assertNull($this->service->search('987654329'));
    }
}
