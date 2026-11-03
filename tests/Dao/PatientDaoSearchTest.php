<?php

declare(strict_types=1);

namespace Aura\Tests\Dao;

use Aura\Core\Database;
use Aura\Dao\PatientDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Recherche par mots du nom / prénom (PR « admission-recherche-patient ») sur la base MariaDB du
 * CI : casse, accents (collation utf8mb4_unicode_ci), ordre des mots, tirets et apostrophes,
 * limite, tri (nom exact d'abord), date du dernier séjour. Transaction annulée en fin de test.
 */
final class PatientDaoSearchTest extends TestCase
{
    private PDO $pdo;
    private PatientDao $dao;
    /** @var array<string, int> */
    private array $ids = [];

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

        $this->ids['lefevre'] = $this->dao->insert($this->patient('Zzlefèvre', 'Jean-Pierre', '987654401'));
        $this->ids['lefevre2'] = $this->dao->insert($this->patient('Zzlefèvre', 'Odette', '987654402'));
        $this->ids['darc'] = $this->dao->insert($this->patient("Zzd'Arc", 'Jeanne', null, birthLastName: 'Zzromée'));
        $this->ids['lefevrard'] = $this->dao->insert($this->patient('Zzlefevrard', 'Pierre', null));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testCaseAndAccentsIgnored(): void
    {
        $names = $this->names($this->dao->searchByWords(['ZZLEFEVRE'], 21));

        self::assertSame(['Zzlefèvre Jean-Pierre', 'Zzlefèvre Odette'], $names);
    }

    public function testEachWordStartsAWordOfLastOrFirstNameInAnyOrder(): void
    {
        $direct = $this->names($this->dao->searchByWords(['zzlef', 'odette'], 21));
        $inverse = $this->names($this->dao->searchByWords(['odette', 'zzlef'], 21));

        self::assertSame(['Zzlefèvre Odette'], $direct);
        self::assertSame($direct, $inverse);
    }

    public function testHyphenAndApostropheInStoredNamesAreMatched(): void
    {
        // « pierre » + « zzlef » : Zzlefèvre Jean-Pierre (2e mot du prénom) et Zzlefevrard Pierre.
        self::assertSame(
            ['Zzlefevrard Pierre', 'Zzlefèvre Jean-Pierre'],
            $this->names($this->dao->searchByWords(['pierre', 'zzlef'], 21))
        );
        self::assertSame(["Zzd'Arc Jeanne"], $this->names($this->dao->searchByWords(['arc'], 21)));
    }

    public function testBirthNameIsSearched(): void
    {
        self::assertSame(["Zzd'Arc Jeanne"], $this->names($this->dao->searchByWords(['zzrom'], 21)));
    }

    public function testWordMatchesOnlyAtWordStart(): void
    {
        self::assertSame(
            [],
            $this->names($this->dao->searchByWords(['efèvre'], 21)),
            'pas de correspondance au milieu'
        );
    }

    public function testExactNameFirstThenAlphabetical(): void
    {
        $names = $this->names($this->dao->searchByWords(['zzlefevr'], 21));
        self::assertSame(['Zzlefevrard Pierre', 'Zzlefèvre Jean-Pierre', 'Zzlefèvre Odette'], $names, 'alphabétique');

        $names = $this->names($this->dao->searchByWords(['zzlefevre'], 21));
        self::assertSame(['Zzlefèvre Jean-Pierre', 'Zzlefèvre Odette'], $names, 'nom exact (sans accent) d\'abord');
    }

    public function testLimitAndEscapedWildcards(): void
    {
        self::assertCount(1, $this->dao->searchByWords(['zzlef'], 1));
        self::assertSame([], $this->dao->searchByWords(['%'], 21));
        self::assertSame([], $this->dao->searchByWords(['zz_'], 21));
        self::assertSame([], $this->dao->searchByWords([], 21));
    }

    public function testLastEpisodeDate(): void
    {
        $episodesOfCare = new EpisodeOfCareDao($this->pdo);
        $episodesOfCare->insert(
            $this->episodeOfCare($this->ids['lefevre2'], '2026-01-05 10:00:00', '2026-01-09 10:00:00')
        );
        $episodesOfCare->insert(
            $this->episodeOfCare($this->ids['lefevre2'], '2026-03-02 08:00:00', '2026-03-04 08:00:00')
        );

        $rows = $this->dao->searchByWords(['odette', 'zzlef'], 21);

        self::assertSame('2026-03-02', $rows[0]['lastAdmission']?->format('Y-m-d'));
        self::assertNull($this->dao->searchByWords(['jeanne', 'zzd'], 21)[0]['lastAdmission']);
        self::assertSame('2026-03-02', $this->dao->lastAdmissionDate($this->ids['lefevre2'])?->format('Y-m-d'));
    }

    /** @param list<array{patient: Patient, lastAdmission: ?DateTimeImmutable}> $rows */
    private function names(array $rows): array
    {
        return array_map(
            static fn (array $r): string => $r['patient']->getLastName() . ' ' . $r['patient']->getFirstName(),
            $rows
        );
    }

    private function patient(string $lastName, string $firstName, ?string $ipp, ?string $birthLastName = null): Patient
    {
        return new Patient(null, $ipp, $lastName, $firstName, new DateTimeImmutable('1970-01-01'), 'F', details: [
            'birthLastName' => $birthLastName,
        ]);
    }

    private function episodeOfCare(int $idPatient, string $admission, string $sortie): EpisodeOfCare
    {
        return new EpisodeOfCare(
            idEpisodeOfCare: null,
            idPatient: $idPatient,
            episodeNumber: '',
            admissionDate: new DateTimeImmutable($admission),
            dischargeDate: new DateTimeImmutable($sortie)
        );
    }
}
