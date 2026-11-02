<?php

declare(strict_types=1);

namespace Aura\Tests\Controller;

use Aura\Controller\PatientController;
use Aura\Service\PatientSearchService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * GET /patients/recherche et GET /patients/fiche (PR « admission-recherche-patient »), en JSON.
 * Processus séparé : le contrôleur envoie des en-têtes HTTP.
 */
final class PatientControllerSearchTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSaisieTropCourteEstRefuseeSansInterrogerLeService(): void
    {
        $service = $this->createMock(PatientSearchService::class);
        $service->expects($this->never())->method('search');

        [$statut, $corps] = $this->appeler($service, 'search', ['q' => ' d ']);

        self::assertSame(422, $statut);
        self::assertSame(['patientSearch'], array_keys($corps['erreurs']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNomInconnuDonneUneListeVideSansErreur(): void
    {
        $service = $this->createMock(PatientSearchService::class);
        $service->method('search')->with('zzinconnu')->willReturn([
            'mode' => 'name', 'results' => [], 'truncated' => false, 'homonyms' => false,
        ]);

        [$statut, $corps] = $this->appeler($service, 'search', ['q' => 'zzinconnu']);

        self::assertSame(200, $statut);
        self::assertSame([
            'ok' => true, 'mode' => 'name', 'resultats' => [], 'total' => 0, 'tronque' => false, 'homonymes' => false,
        ], $corps);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResultatsRenvoyesAvecHomonymesEtTroncature(): void
    {
        $resultat = ['idPatient' => 9, 'nom' => 'Durand', 'prenom' => 'Alice', 'nomNaissance' => null,
            'dateNaissance' => '15/06/1990', 'age' => 36, 'sexe' => 'F', 'ipp' => null, 'dernierSejour' => null];
        $service = $this->createMock(PatientSearchService::class);
        $service->method('search')->willReturn([
            'mode' => 'name', 'results' => [$resultat, $resultat], 'truncated' => true, 'homonyms' => true,
        ]);

        [$statut, $corps] = $this->appeler($service, 'search', ['q' => 'durand']);

        self::assertSame(200, $statut);
        self::assertSame(2, $corps['total']);
        self::assertTrue($corps['tronque']);
        self::assertTrue($corps['homonymes']);
        self::assertSame(
            ['idPatient', 'nom', 'prenom', 'nomNaissance', 'dateNaissance', 'age', 'sexe', 'ipp', 'dernierSejour'],
            array_keys($corps['resultats'][0]),
            'aucun champ médical dans la réponse'
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFicheInconnueDonne404(): void
    {
        $service = $this->createMock(PatientSearchService::class);
        $service->method('record')->with(404)->willReturn(null);

        [$statut] = $this->appeler($service, 'record', ['id' => '404']);

        self::assertSame(404, $statut);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFicheAuFormatDuFormulaireAvecAllergies(): void
    {
        $service = $this->createMock(PatientSearchService::class);
        $service->method('record')->with(9)->willReturn([
            'identite' => ['idPatient' => '9', 'nom' => 'Durand', 'prenom' => 'Alice', 'ipp' => null,
                'dateNaissance' => '1990-06-15', 'sexe' => 'F'],
            'details' => ['medicalHistory' => 'HTA', 'lifestyle' => null],
            'allergies' => [['idSubstance' => 'ALG01', 'libelle' => 'Pénicilline']],
        ]);

        [$statut, $corps] = $this->appeler($service, 'record', ['id' => '9']);

        self::assertSame(200, $statut);
        self::assertSame('Durand', $corps['patient']['lastname']);
        self::assertSame('', $corps['patient']['ipp']);
        self::assertSame('F', $corps['patient']['gender']);
        self::assertSame('15/06/1990', $corps['patient']['birthDate'], 'date au format saisi : JJ/MM/AAAA');
        self::assertSame('HTA', $corps['patient']['medicalHistory']);
        self::assertSame('', $corps['patient']['lifestyle']);
        self::assertArrayNotHasKey('searchMode', $corps['patient']);
        self::assertSame(['ALG01'], $corps['allergies']);
        self::assertSame(['Pénicilline'], $corps['allergiesLibelles']);
    }

    /**
     * @param array<string, string> $get
     * @return array{int, array<string, mixed>}
     */
    private function appeler(PatientSearchService $service, string $methode, array $get): array
    {
        $_GET = $get;
        $controller = new PatientController(patientSearchService: $service);
        ob_start();
        $controller->$methode();
        $sortie = (string) ob_get_clean();

        return [http_response_code(), json_decode($sortie, true, 512, JSON_THROW_ON_ERROR)];
    }
}
