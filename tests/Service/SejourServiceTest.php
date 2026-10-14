<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Dao\SejourDao;
use Aura\Domain\Sejour;
use Aura\Service\SejourService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AURA-10.3 : ouverture, emplacement et clôture d'un séjour.
 * Le numéro de séjour lui-même est généré par SejourDao (cf. SejourDaoTest).
 */
final class SejourServiceTest extends TestCase
{
    private SejourDao&MockObject $dao;
    private SejourService $service;

    protected function setUp(): void
    {
        $this->dao = $this->createMock(SejourDao::class);
        $this->service = new SejourService($this->dao);
    }

    public function testOuvrirSejourInsereUnSejourSansNumeroNiSortie(): void
    {
        $this->dao->method('findByPatient')->with(5)->willReturn([]);
        $this->dao->method('findEnCours')->willReturn([]);
        $this->dao->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Sejour $s): bool {
                $this->assertNull($s->getIdSejour());
                $this->assertSame(5, $s->getIdPatient());
                $this->assertSame('', $s->getNumSejour(), 'le numéro est attribué par le DAO, jamais saisi');
                $this->assertNull($s->getDateSortie());
                $this->assertTrue($s->estEnCours());
                $this->assertEqualsWithDelta(time(), $s->getDateAdmission()->getTimestamp(), 5);
                $this->assertSame('L1', $s->getNumLit());
                $this->assertNull($s->getNumBox(), 'un box vide devient null');

                return true;
            }))
            ->willReturnCallback(fn(Sejour $s) => $this->sejour(10, 5, numLit: $s->getNumLit()));

        $sejour = $this->service->ouvrirSejour(5, ' L1 ', '  ');

        $this->assertSame(10, $sejour->getIdSejour());
    }

    /** AURA-10.1 : les mesures de l'admission sont portées par le séjour inséré. */
    public function testOuvrirSejourTransmetLesMesuresAuDao(): void
    {
        $this->dao->method('findByPatient')->willReturn([]);
        $this->dao->method('findEnCours')->willReturn([]);
        $this->dao->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Sejour $s): bool {
                $this->assertSame(68.5, $s->getPoids());
                $this->assertSame(172.0, $s->getTaille());
                $this->assertSame('Isolement contact', $s->getPrecautions());

                return true;
            }))
            ->willReturn($this->sejour(10, 5));

        $this->service->ouvrirSejour(5, poids: 68.5, taille: 172.0, precautions: 'Isolement contact');
    }

    public function testOuvrirSejourRefuseSiLePatientEstDejaPresent(): void
    {
        $this->dao->method('findByPatient')->with(5)->willReturn([
            $this->sejour(1, 5, dateSortie: new DateTimeImmutable('-1 day')),
            $this->sejour(2, 5),
        ]);
        $this->dao->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('déjà un séjour en cours');

        $this->service->ouvrirSejour(5);
    }

    public function testUnPatientSortiPeutRevenir(): void
    {
        $this->dao->method('findByPatient')->with(5)->willReturn([
            $this->sejour(1, 5, dateSortie: new DateTimeImmutable('-1 day')),
        ]);
        $this->dao->method('findEnCours')->willReturn([]);
        $this->dao->expects($this->once())->method('insert')->willReturn($this->sejour(2, 5));

        $this->assertSame(2, $this->service->ouvrirSejour(5)->getIdSejour());
    }

    public function testOuvrirSejourRefuseUnLitOccupe(): void
    {
        $this->dao->method('findByPatient')->willReturn([]);
        $this->dao->method('findEnCours')->willReturn([$this->sejour(1, 9, numLit: 'L1')]);
        $this->dao->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lit L1 est déjà occupé');

        $this->service->ouvrirSejour(5, 'L1');
    }

    public function testOuvrirSejourRefuseUnBoxOccupe(): void
    {
        $this->dao->method('findByPatient')->willReturn([]);
        $this->dao->method('findEnCours')->willReturn([$this->sejour(1, 9, numBox: 'B2')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('box B2 est déjà occupé');

        $this->service->ouvrirSejour(5, null, 'B2');
    }

    public function testNumeroDeLitTropLongEstRefuse(): void
    {
        $this->dao->method('findByPatient')->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('20 caractères');

        $this->service->ouvrirSejour(5, str_repeat('L', 21));
    }

    public function testChangerEmplacementIgnoreLeSejourLuiMeme(): void
    {
        $sejour = $this->sejour(1, 5, numLit: 'L1', numBox: 'B1');
        $this->dao->method('findById')->with(1)->willReturn($sejour);
        $this->dao->method('findEnCours')->willReturn([$sejour]);
        $this->dao->expects($this->once())->method('updateEmplacement')->with(1, 'L1', 'B2');

        $this->service->changerEmplacement(1, 'L1', 'B2');
    }

    public function testChangerEmplacementDUnSejourTermineEstRefuse(): void
    {
        $this->dao->method('findById')->with(1)
            ->willReturn($this->sejour(1, 5, dateSortie: new DateTimeImmutable('-1 hour')));
        $this->dao->expects($this->never())->method('updateEmplacement');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('terminé');

        $this->service->changerEmplacement(1, 'L2', null);
    }

    public function testCloturerSejourEnregistreLaSortie(): void
    {
        $this->dao->method('findById')->with(1)->willReturnOnConsecutiveCalls(
            $this->sejour(1, 5),
            $this->sejour(1, 5, dateSortie: new DateTimeImmutable())
        );
        $this->dao->expects($this->once())
            ->method('updateSortie')
            ->with(1, $this->isInstanceOf(DateTimeImmutable::class));

        $this->assertFalse($this->service->cloturerSejour(1)->estEnCours());
    }

    public function testCloturerDeuxFoisEstRefuse(): void
    {
        $this->dao->method('findById')->willReturn($this->sejour(1, 5, dateSortie: new DateTimeImmutable('-1 hour')));
        $this->dao->expects($this->never())->method('updateSortie');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('déjà terminé');

        $this->service->cloturerSejour(1);
    }

    public function testSortieAvantAdmissionEstRefusee(): void
    {
        $this->dao->method('findById')->willReturn($this->sejour(1, 5));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('précéder');

        $this->service->cloturerSejour(1, new DateTimeImmutable('2000-01-01'));
    }

    public function testSortieDansLeFuturEstRefusee(): void
    {
        $this->dao->method('findById')->willReturn($this->sejour(1, 5));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('futur');

        $this->service->cloturerSejour(1, new DateTimeImmutable('+1 day'));
    }

    public function testGetSejourInconnuEstRefuse(): void
    {
        $this->dao->method('findById')->with(99)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Séjour 99 introuvable');

        $this->service->getSejour(99);
    }

    private function sejour(
        int $id,
        int $idPatient,
        ?DateTimeImmutable $dateSortie = null,
        ?string $numLit = null,
        ?string $numBox = null
    ): Sejour {
        return new Sejour(
            idSejour: $id,
            idPatient: $idPatient,
            numSejour: sprintf('2026-%06d', $id),
            dateAdmission: new DateTimeImmutable('-2 days'),
            dateSortie: $dateSortie,
            numLit: $numLit,
            numBox: $numBox
        );
    }
}
