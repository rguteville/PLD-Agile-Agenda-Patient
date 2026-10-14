<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Dao\SejourDao;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Le jeu de données de démonstration (database/test.sql) doit rester lisible par les DAO.
 *
 * PatientDao et SejourDao lisent idPatient et idSejour en entiers : un identifiant
 * non numérique dans test.sql (ex. « PAT01 ») donne 0 côté PHP et casse le dossier
 * patient (/sejour?id=…) et la liste des patients. Ce test tourne sur la base du CI,
 * où test.sql est chargé après schema.sql ; il est ignoré hors CI ou si la base est vide.
 */
final class DonneesDemoTest extends TestCase
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

        if ((int) $this->pdo->query('SELECT COUNT(*) FROM Patient')->fetchColumn() === 0) {
            $this->markTestSkipped('Aucun patient en base : database/test.sql n\'est pas chargé.');
        }
    }

    public function testLesPatientsDeDemoOntUnIdentifiantNumerique(): void
    {
        $patients = (new PatientDao($this->pdo))->findAll();

        self::assertGreaterThanOrEqual(3, count($patients), 'AURA-05 : au moins 3 patients de démo');
        foreach ($patients as $patient) {
            self::assertGreaterThan(
                0,
                $patient->getIdPatient(),
                sprintf(
                    'idPatient de %s %s doit être numérique dans test.sql',
                    $patient->getPrenom(),
                    $patient->getNom()
                )
            );
        }
    }

    public function testChaqueSejourEnCoursDeDemoMeneAUnDossierLisible(): void
    {
        $sejourDao = new SejourDao($this->pdo);
        $patientDao = new PatientDao($this->pdo);
        $allergieDao = new AllergieDao($this->pdo);

        $enCours = $sejourDao->findEnCours();
        self::assertNotEmpty($enCours, 'AURA-05 : au moins un patient encore hospitalisé');

        foreach ($enCours as $sejour) {
            $id = $sejour->getIdSejour();
            self::assertGreaterThan(0, $id, 'idSejour doit être numérique dans test.sql');

            // Même parcours que SejourController::show() : séjour, puis patient, puis allergies.
            $relu = $sejourDao->findById((int) $id);
            self::assertNotNull($relu, "le séjour $id doit être retrouvé par son identifiant");
            self::assertSame($sejour->getNumSejour(), $relu->getNumSejour());

            $patient = $patientDao->findById($relu->getIdPatient());
            self::assertNotNull($patient, "le patient du séjour $id doit exister");
            $allergieDao->findByPatient((int) $patient->getIdPatient());
        }
    }
}
