<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\ReferenceDataDao;
use PDO;
use PHPUnit\Framework\TestCase;

/** Liste des médecins (RG5) : c'est le droit de validation du rôle qui compte, pas son titre. */
final class ReferenceDataDaoTest extends TestCase
{
    private PDO $pdo;
    private ReferenceDataDao $dao;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->pdo->beginTransaction();
        $this->dao = new ReferenceDataDao($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testOnlyPractitionersWithTheValidationRightAreDoctors(): void
    {
        // Un rôle qui valide sans s'appeler « Médecin… » : c'est canValidate qui compte (RG5).
        $this->pdo->exec(
            "INSERT INTO PractitionerRole (idPractitionerRole, title, canValidate) VALUES ('ZZQUA', 'Zzchef', TRUE)"
        );
        $this->pdo->exec(
            "INSERT INTO Practitioner (idPractitioner, idPractitionerRole, firstName, lastName) VALUES
                ('ZZPRA4', 'ZZQUA', 'Zzprénom', 'Zzchef')"
        );

        $doctors = $this->dao->doctors();
        self::assertSame('Dr Zzprénom Zzchef', $doctors['ZZPRA4']);
        self::assertArrayHasKey('PRA01', $doctors, 'médecin réanimateur de la démo');
        self::assertArrayNotHasKey('PRA03', $doctors, 'une interne ne valide pas');
        self::assertArrayNotHasKey('PRA04', $doctors, 'un infirmier ne valide pas');
    }
}
