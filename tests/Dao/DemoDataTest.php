<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Controller\PatientController;
use Aura\Controller\EpisodeOfCareController;
use Aura\Core\Database;
use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\PatientDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\SubstanceDao;
use Aura\Domain\Patient;
use Aura\Service\PatientRecordService;
use Aura\Service\PatientListService;
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
 * PatientDao et EpisodeOfCareDao lisent idPatient et idSejour en entiers : un identifiant
 * non numérique dans test.sql (ex. « PAT01 ») donne 0 côté PHP et casse le dossier
 * patient (/sejour?id=…) et la liste des patients. Ce test tourne sur la base du CI,
 * où test.sql est chargé après schema.sql ; il est ignoré hors CI ou si la base est vide.
 */
final class DemoDataTest extends TestCase
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

    public function testDemoPatientsHaveNumericId(): void
    {
        $patients = (new PatientDao($this->pdo))->findAll();

        self::assertGreaterThanOrEqual(3, count($patients), 'AURA-05 : au moins 3 patients de démo');
        foreach ($patients as $patient) {
            self::assertGreaterThan(
                0,
                $patient->getIdPatient(),
                sprintf(
                    'idPatient de %s %s doit être numérique dans test.sql',
                    $patient->getFirstName(),
                    $patient->getLastName()
                )
            );
        }
    }

    /**
     * Retours du 2026-11-03 : la démo montre les deux cas du médecin référent parmi les séjours en
     * cours, un référent nommé et un séjour « à désigner » (admission de garde).
     */
    public function testOngoingDemoEpisodesShowNamedAndMissingReferent(): void
    {
        $ongoing = (new EpisodeOfCareDao($this->pdo))->findOngoing();
        $named = array_filter($ongoing, static fn ($e): bool => $e->getReferringPractitionerName() !== null);

        self::assertNotEmpty($named, 'au moins un séjour en cours avec un référent');
        self::assertLessThan(count($ongoing), count($named), 'au moins un séjour en cours « à désigner »');
        self::assertGreaterThan(count($ongoing) / 2, count($named), 'référent renseigné pour la plupart');
    }

    public function testAtLeastOneDemoPatientHasAllergies(): void
    {
        $allergyIntoleranceDao = new AllergyIntoleranceDao($this->pdo);

        $withAllergies = array_filter(
            (new PatientDao($this->pdo))->findAll(),
            fn (Patient $p) => $allergyIntoleranceDao->findByPatient((int) $p->getIdPatient()) !== []
        );

        self::assertNotEmpty($withAllergies, 'AURA-05 : au moins un patient de démo avec des allergies');
    }

    public function testEachOngoingDemoEpisodeLeadsToReadableRecord(): void
    {
        $episodeOfCareDao = new EpisodeOfCareDao($this->pdo);
        $patientDao = new PatientDao($this->pdo);
        $allergyIntoleranceDao = new AllergyIntoleranceDao($this->pdo);

        $ongoing = $episodeOfCareDao->findOngoing();
        self::assertNotEmpty($ongoing, 'AURA-05 : au moins un patient encore hospitalisé');

        foreach ($ongoing as $episodeOfCare) {
            $id = $episodeOfCare->getIdEpisodeOfCare();
            self::assertGreaterThan(0, $id, 'idSejour doit être numérique dans test.sql');

            // Même parcours que EpisodeOfCareController::show() : séjour, puis patient, puis allergies.
            $reread = $episodeOfCareDao->findById((int) $id);
            self::assertNotNull($reread, "le séjour $id doit être retrouvé par son identifiant");
            self::assertSame($episodeOfCare->getEpisodeNumber(), $reread->getEpisodeNumber());

            $patient = $patientDao->findById($reread->getIdPatient());
            self::assertNotNull($patient, "le patient du séjour $id doit exister");
            $allergyIntoleranceDao->findByPatient((int) $patient->getIdPatient());
        }
    }

    /**
     * AURA-05 : un patient sans IPP (ipp NULL) apparaît dans la liste et son dossier
     * s'ouvre, avec « sans IPP » à la place de l'IPP (et non une cellule vide ou « 0 »).
     */
    public function testDemoPatientWithoutIppShowsInListAndRecord(): void
    {
        $patientDao = new PatientDao($this->pdo);
        $episodeOfCareDao = new EpisodeOfCareDao($this->pdo);

        $withoutIpp = [];
        foreach ($patientDao->findAll() as $patient) {
            if ($patient->getIpp() === null) {
                $withoutIpp[$patient->getIdPatient()] = $patient;
            }
        }
        self::assertNotEmpty($withoutIpp, 'AURA-05 : au moins un patient de démo sans IPP');

        $episodeOfCare = null;
        foreach ($episodeOfCareDao->findOngoing() as $ongoing) {
            if (isset($withoutIpp[$ongoing->getIdPatient()])) {
                $episodeOfCare = $ongoing;
                break;
            }
        }
        self::assertNotNull($episodeOfCare, 'AURA-05 : le patient sans IPP de démo doit avoir un séjour en cours');
        $patient = $withoutIpp[$episodeOfCare->getIdPatient()];

        // GET /patients : même câblage que public/index.php.
        $items = self::xpath(self::capture([], static function () use ($patientDao, $episodeOfCareDao): void {
            (new PatientController(
                patientListService: new PatientListService($patientDao, $episodeOfCareDao)
            ))->list();
        }));
        $line = null;
        foreach ($items->query('//table[contains(@class, "patient-list")]/tbody/tr') as $tr) {
            $cells = $items->query('td', $tr);
            if (
                trim($cells->item(0)->textContent) === $patient->getLastName()
                && trim($cells->item(1)->textContent) === $patient->getFirstName()
            ) {
                $line = $cells;
                break;
            }
        }
        self::assertNotNull($line, 'le patient sans IPP doit figurer dans la liste /patients');
        self::assertSame('sans IPP', trim($line->item(2)->textContent), 'colonne IPP de la liste');
        self::assertSame(
            '/sejour?id=' . $episodeOfCare->getIdEpisodeOfCare(),
            $items->evaluate('string(a/@href)', $line->item(5)),
            'lien « Voir » vers le dossier du séjour en cours'
        );

        // GET /sejour?id=… : même câblage que public/index.php.
        $pdo = $this->pdo;
        $record = self::xpath(self::capture(
            ['id' => (string) $episodeOfCare->getIdEpisodeOfCare()],
            static function () use ($pdo): void {
                (new EpisodeOfCareController(new PatientRecordService(
                    new EpisodeOfCareDao($pdo),
                    new PatientDao($pdo),
                    new AllergyIntoleranceDao($pdo),
                    new SubstanceDao($pdo),
                    new DateTimeImmutable('today')
                )))->show();
            }
        ));
        $banner = '//header[contains(@class, "patient-banner")]';
        self::assertSame(
            $patient->getLastName(),
            trim($record->evaluate("string($banner//span[@class='last-name'])")),
            'le dossier du patient sans IPP doit s\'afficher avec son bandeau'
        );
        self::assertSame(
            'sans IPP',
            trim($record->evaluate("string($banner//dt[normalize-space()='IPP']/following-sibling::dd[1])")),
            'IPP du bandeau'
        );
    }

    /** Exécute une action de contrôleur avec ce $_GET et renvoie le HTML produit. */
    private static function capture(array $get, callable $action): string
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
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
