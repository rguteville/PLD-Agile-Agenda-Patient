<?php

declare(strict_types=1);

namespace Aura\Tests\Controller;

use Aura\Controller\PatientController;
use Aura\Service\RechercheIppService;
use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * AURA-15 : GET /patients/recherche-ipp répond en JSON avec les noms de champs du formulaire
 * d'admission. Chaque test tourne dans un processus séparé : le contrôleur envoie des en-têtes
 * HTTP, impossibles à poser une fois la sortie de PHPUnit commencée.
 */
final class PatientControllerRechercheIppTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIppConnuRenvoieLIdentiteAuFormatDuFormulaireEtLesAllergies(): void
    {
        $service = $this->createMock(RechercheIppService::class);
        $service->method('rechercher')->with('100200300')->willReturn([
            'idPatient' => 9,
            'ipp' => '100200300',
            'nom' => 'Durand',
            'prenom' => 'Alice',
            'dateNaissance' => '1990-06-15',
            'sexe' => 'F',
            'allergies' => [['libelle' => 'Pénicilline', 'criticite' => 'Élevée']],
        ]);

        [$statut, $corps] = $this->appeler($service, ['ipp' => ' 100200300 ']);

        self::assertSame(200, $statut);
        self::assertSame([
            'ok' => true,
            'trouve' => true,
            'patient' => [
                'lastname' => 'Durand',
                'firstname' => 'Alice',
                'ipp' => '100200300',
                'birthDate' => '1990-06-15',
                'gender' => 'F',
                'lienModification' => '/patients/modifier?id=9',
            ],
            'allergies' => [['libelle' => 'Pénicilline', 'criticite' => 'Élevée']],
        ], $corps);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDateEtSexeInconnusDonnentDesChampsVides(): void
    {
        $service = $this->createMock(RechercheIppService::class);
        $service->method('rechercher')->willReturn([
            'idPatient' => 7,
            'ipp' => '123456',
            'nom' => 'X',
            'prenom' => 'X',
            'dateNaissance' => null,
            'sexe' => null,
            'allergies' => [],
        ]);

        [, $corps] = $this->appeler($service, ['ipp' => '123456']);

        self::assertSame('', $corps['patient']['birthDate']);
        self::assertSame('', $corps['patient']['gender']);
        self::assertSame([], $corps['allergies']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIppInconnuRenvoieLeMessageDeSaisieManuelle(): void
    {
        $service = $this->createMock(RechercheIppService::class);
        $service->method('rechercher')->willReturn(null);

        [$statut, $corps] = $this->appeler($service, ['ipp' => '999999999']);

        self::assertSame(200, $statut);
        self::assertSame(['ok' => true, 'trouve' => false, 'message' => 'IPP inconnu, saisie manuelle.'], $corps);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIppHorsFormatEstRefuseSansInterrogerLaBase(): void
    {
        $service = $this->createMock(RechercheIppService::class);
        $service->expects($this->never())->method('rechercher');

        [$statut, $corps] = $this->appeler($service, ['ipp' => '12']);

        self::assertSame(422, $statut);
        self::assertFalse($corps['ok']);
        self::assertSame(['ipp'], array_keys($corps['erreurs']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIppVideOuNonTextuelEstRefuse(): void
    {
        $service = $this->createMock(RechercheIppService::class);
        $service->expects($this->never())->method('rechercher');

        [$statut] = $this->appeler($service, []);
        self::assertSame(422, $statut);

        [$statut] = $this->appeler($service, ['ipp' => ['tableau', 'forgé']]);
        self::assertSame(422, $statut);
    }

    public function testSansServiceLaRechercheEstImpossible(): void
    {
        $this->expectException(LogicException::class);

        (new PatientController())->rechercherParIpp();
    }

    /**
     * Exécute GET /patients/recherche-ipp avec ce $_GET, comme public/index.php.
     *
     * @param array<string, mixed> $get
     * @return array{int, array<string, mixed>} statut HTTP et corps JSON décodé
     */
    private function appeler(RechercheIppService $service, array $get): array
    {
        $_GET = $get;
        ob_start();
        try {
            (new PatientController(rechercheIppService: $service))->rechercherParIpp();
        } finally {
            $sortie = (string) ob_get_clean();
        }

        return [(int) http_response_code(), json_decode($sortie, true, flags: JSON_THROW_ON_ERROR)];
    }
}
