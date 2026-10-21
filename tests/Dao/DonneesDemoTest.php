<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Controller\PatientController;
use Aura\Controller\SejourController;
use Aura\Core\Database;
use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Dao\SejourDao;
use Aura\Dao\SubstanceDao;
use Aura\Domain\Patient;
use Aura\Service\DossierPatientService;
use Aura\Service\ListePatientsService;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Le jeu de données de démonstration (database/test.sql) doit rester lisible par les DAO
 * et couvrir les critères d'AURA-05 : au moins 3 patients, dont un avec des allergies
 * et un sans IPP.
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

    protected function tearDown(): void
    {
        $_GET = [];
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

    public function testAuMoinsUnPatientDeDemoADesAllergies(): void
    {
        $allergieDao = new AllergieDao($this->pdo);

        $avecAllergies = array_filter(
            (new PatientDao($this->pdo))->findAll(),
            fn (Patient $p) => $allergieDao->findByPatient((int) $p->getIdPatient()) !== []
        );

        self::assertNotEmpty($avecAllergies, 'AURA-05 : au moins un patient de démo avec des allergies');
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

    /**
     * AURA-05 : un patient sans IPP (ipp NULL) apparaît dans la liste et son dossier
     * s'ouvre, avec « sans IPP » à la place de l'IPP (et non une cellule vide ou « 0 »).
     */
    public function testUnPatientDeDemoSansIppSAfficheDansLaListeEtLeDossier(): void
    {
        $patientDao = new PatientDao($this->pdo);
        $sejourDao = new SejourDao($this->pdo);

        $sansIpp = [];
        foreach ($patientDao->findAll() as $patient) {
            if ($patient->getIpp() === null) {
                $sansIpp[$patient->getIdPatient()] = $patient;
            }
        }
        self::assertNotEmpty($sansIpp, 'AURA-05 : au moins un patient de démo sans IPP');

        $sejour = null;
        foreach ($sejourDao->findEnCours() as $enCours) {
            if (isset($sansIpp[$enCours->getIdPatient()])) {
                $sejour = $enCours;
                break;
            }
        }
        self::assertNotNull($sejour, 'AURA-05 : le patient sans IPP de démo doit avoir un séjour en cours');
        $patient = $sansIpp[$sejour->getIdPatient()];

        // GET /patients : même câblage que public/index.php.
        $liste = self::xpath(self::capturer([], static function () use ($patientDao, $sejourDao): void {
            (new PatientController(
                listePatientsService: new ListePatientsService($patientDao, $sejourDao)
            ))->list();
        }));
        $ligne = null;
        foreach ($liste->query('//table[contains(@class, "liste-patients")]/tbody/tr') as $tr) {
            $cellules = $liste->query('td', $tr);
            if (
                trim($cellules->item(0)->textContent) === $patient->getNom()
                && trim($cellules->item(1)->textContent) === $patient->getPrenom()
            ) {
                $ligne = $cellules;
                break;
            }
        }
        self::assertNotNull($ligne, 'le patient sans IPP doit figurer dans la liste /patients');
        self::assertSame('sans IPP', trim($ligne->item(2)->textContent), 'colonne IPP de la liste');
        self::assertSame(
            '/sejour?id=' . $sejour->getIdSejour(),
            $liste->evaluate('string(a/@href)', $ligne->item(5)),
            'lien « Voir » vers le dossier du séjour en cours'
        );

        // GET /sejour?id=… : même câblage que public/index.php.
        $pdo = $this->pdo;
        $dossier = self::xpath(self::capturer(
            ['id' => (string) $sejour->getIdSejour()],
            static function () use ($pdo): void {
                (new SejourController(new DossierPatientService(
                    new SejourDao($pdo),
                    new PatientDao($pdo),
                    new AllergieDao($pdo),
                    new SubstanceDao($pdo),
                    new DateTimeImmutable('today')
                )))->show();
            }
        ));
        $bandeau = '//header[contains(@class, "bandeau-patient")]';
        self::assertSame(
            $patient->getNom(),
            trim($dossier->evaluate("string($bandeau//span[@class='nom'])")),
            'le dossier du patient sans IPP doit s\'afficher avec son bandeau'
        );
        self::assertSame(
            'sans IPP',
            trim($dossier->evaluate("string($bandeau//dt[normalize-space()='IPP']/following-sibling::dd[1])")),
            'IPP du bandeau'
        );
    }

    /** Exécute une action de contrôleur avec ce $_GET et renvoie le HTML produit. */
    private static function capturer(array $get, callable $action): string
    {
        $_GET = $get;
        ob_start();
        try {
            $action();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private static function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        // libxml ne connaît pas les balises HTML5 (header, main…) : avertissements ignorés.
        $precedent = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($precedent);

        return new DOMXPath($dom);
    }
}
