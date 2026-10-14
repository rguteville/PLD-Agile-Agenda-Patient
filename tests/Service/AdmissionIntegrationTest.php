<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Core\Database;
use Aura\Dao\PatientDao;
use Aura\Dao\SejourDao;
use Aura\Dao\Transaction;
use Aura\Service\AdmissionService;
use Aura\Service\SejourService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AURA-10.4, définition de fini, sur la base MariaDB du CI (ignoré hors CI) :
 *  - une admission crée exactement une ligne Sejour et, pour un nouveau patient, une ligne Patient ;
 *  - une erreur à l'insertion du séjour ne laisse pas de Patient orphelin.
 *
 * La transaction réelle est testée ici : pas de transaction englobante, les lignes
 * créées sont supprimées à la fin.
 */
final class AdmissionIntegrationTest extends TestCase
{
    private const NOM = 'Zzadmissionintegration';

    private PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            $this->markTestSkipped('DB_HOST et DB_NAME non définis : test d\'intégration ignoré (hors CI).');
        }

        $this->pdo = Database::get();
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->nettoyer();
        }
    }

    public function testUneAdmissionCreeUnPatientEtUnSejour(): void
    {
        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            new SejourService(new SejourDao($this->pdo)),
            new Transaction($this->pdo)
        );
        [$patientsAvant, $sejoursAvant] = $this->compter();

        $sejour = $service->admettre(['nom' => self::NOM, 'prenom' => 'Alice', 'sexe' => 'F']);

        [$patientsApres, $sejoursApres] = $this->compter();
        self::assertSame($patientsAvant + 1, $patientsApres, 'exactement un patient créé');
        self::assertSame($sejoursAvant + 1, $sejoursApres, 'exactement un séjour créé');
        self::assertGreaterThan(0, $sejour->getIdSejour());
        self::assertTrue($sejour->estEnCours());

        // Le dossier est lisible comme le fera SejourController après la redirection.
        $relu = (new SejourDao($this->pdo))->findById((int) $sejour->getIdSejour());
        self::assertNotNull($relu);
        self::assertSame($sejour->getNumSejour(), $relu->getNumSejour());

        // Rejouer la même admission : le patient (sans IPP) est recréé mais c'est bien
        // un second POST, pas un rafraîchissement ; la redirection POST → GET couvre ce cas.
    }

    public function testUnSejourRefuseNeLaissePasDePatientOrphelin(): void
    {
        $sejours = $this->createMock(SejourService::class);
        $sejours->method('ouvrirSejour')->willThrowException(new InvalidArgumentException('Le lit L1 est déjà occupé'));

        $service = new AdmissionService(
            null,
            new PatientDao($this->pdo),
            $sejours,
            new Transaction($this->pdo)
        );
        [$patientsAvant, $sejoursAvant] = $this->compter();

        try {
            $service->admettre(['nom' => self::NOM, 'prenom' => 'Bob']);
            self::fail('L\'admission aurait dû être refusée.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('déjà occupé', $e->getMessage());
        }

        self::assertSame([$patientsAvant, $sejoursAvant], $this->compter(), 'rien n\'a été écrit');
        self::assertFalse($this->pdo->inTransaction(), 'la transaction est bien terminée');
    }

    /** @return array{int, int} */
    private function compter(): array
    {
        return [
            (int) $this->pdo->query('SELECT COUNT(*) FROM Patient')->fetchColumn(),
            (int) $this->pdo->query('SELECT COUNT(*) FROM Sejour')->fetchColumn(),
        ];
    }

    private function nettoyer(): void
    {
        $this->pdo->prepare(
            'DELETE FROM Sejour WHERE idPatient IN (SELECT idPatient FROM Patient WHERE lastName = :nom)'
        )->execute(['nom' => self::NOM]);
        $this->pdo->prepare('DELETE FROM Patient WHERE lastName = :nom')->execute(['nom' => self::NOM]);
    }
}
