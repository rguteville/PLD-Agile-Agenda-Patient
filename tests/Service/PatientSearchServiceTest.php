<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\PatientDao;
use Aura\Domain\Patient;
use Aura\Service\PatientSearchService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Recherche d'un patient existant à l'admission (vigilance d'identité) : IPP reconnu, homonymes signalés,
 * liste limitée à 20, identité seulement dans les résultats. DAO doublé.
 */
final class PatientSearchServiceTest extends TestCase
{
    public function testKnownIppGivesASingleResultWithIdentityOnly(): void
    {
        $dao = $this->createStub(PatientDao::class);
        $dao->method('findByIpp')->willReturn(new Patient(9, '100200300', 'Durand', 'Alice', null, 'F', details: [
            'medicalHistory' => 'HTA SECRET',
        ]));

        $search = $this->service($dao)->search(' 100200300 ');

        self::assertSame('ipp', $search['mode']);
        self::assertSame([9], array_column($search['results'], 'idPatient'));
        self::assertStringNotContainsString('SECRET', json_encode($search, JSON_THROW_ON_ERROR), 'aucun champ médical');
    }

    public function testTooShortInputDoesNotQueryTheDatabase(): void
    {
        $dao = $this->createMock(PatientDao::class);
        $dao->expects($this->never())->method('searchByWords');

        self::assertSame([], $this->service($dao)->search('d')['results']);
    }

    public function testHomonymsAreFlagged(): void
    {
        $dao = $this->createStub(PatientDao::class);
        $dao->method('searchByWords')->willReturn([
            ['patient' => new Patient(1, null, 'Dupont', 'Jean', null, 'M'), 'lastAdmission' => null],
            ['patient' => new Patient(2, null, 'DUPONT', 'jean', null, 'M'), 'lastAdmission' => null],
        ]);

        self::assertTrue($this->service($dao)->search('dupont jean')['homonyms']);
    }

    public function testMoreThanTwentyResultsAreTruncatedAndFlagged(): void
    {
        $rows = [];
        for ($i = 1; $i <= 21; $i++) {
            $rows[] = ['patient' => new Patient($i, null, 'Dupont', 'P' . $i, null, null), 'lastAdmission' => null];
        }
        $dao = $this->createStub(PatientDao::class);
        $dao->method('searchByWords')->willReturn($rows);

        $search = $this->service($dao)->search('dupont');

        self::assertCount(20, $search['results']);
        self::assertTrue($search['truncated']);
    }

    private function service(PatientDao $dao): PatientSearchService
    {
        return new PatientSearchService($dao, null, new DateTimeImmutable('2026-10-14'));
    }
}
