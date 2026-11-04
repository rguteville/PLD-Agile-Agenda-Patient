<?php

declare(strict_types=1);

namespace Aura\Tests\Controller;

use Aura\Controller\PatientController;
use Aura\Service\IppSearchService;
use Aura\Service\PatientSearchService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Recherches JSON de l'admission (IPP, nom) et fiche d'un patient. Processus séparé : le contrôleur
 * envoie des en-têtes HTTP.
 */
final class PatientControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMalformedIppIsRejectedWithoutQueryingTheDatabase(): void
    {
        $service = $this->createMock(IppSearchService::class);
        $service->expects($this->never())->method('search');
        $controller = new PatientController(ippSearchService: $service);

        foreach (['12', 'abc123', ['100000001']] as $ipp) {
            [$status, $body] = $this->call(static fn () => $controller->searchByIpp(), ['ipp' => $ipp]);
            self::assertSame(422, $status);
            self::assertSame(['ipp'], array_keys($body['errors']));
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNameSearchReturnsIdentityOnlyWithHomonymWarning(): void
    {
        $result = ['idPatient' => 9, 'lastName' => 'Durand', 'firstName' => 'Alice', 'birthLastName' => null,
            'birthDate' => '15/06/1990', 'age' => 36, 'sex' => 'F', 'ipp' => null, 'lastEpisodeOfCare' => null];
        $service = $this->createMock(PatientSearchService::class);
        $service->method('search')->willReturn([
            'mode' => 'name', 'results' => [$result, $result], 'truncated' => false, 'homonyms' => true,
        ]);
        $controller = new PatientController(patientSearchService: $service);

        [$status, $body] = $this->call(static fn () => $controller->search(), ['q' => 'durand']);

        self::assertSame(200, $status);
        self::assertTrue($body['homonyms']);
        self::assertArrayNotHasKey('medicalHistory', $body['results'][0], 'aucun champ médical dans la réponse');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnknownPatientCardGives404(): void
    {
        $service = $this->createMock(PatientSearchService::class);
        $service->method('record')->willReturn(null);
        $controller = new PatientController(patientSearchService: $service);

        [$status] = $this->call(static fn () => $controller->record(), ['id' => '404']);

        self::assertSame(404, $status);
    }

    /**
     * @param array<string, mixed> $get
     * @return array{int, array<string, mixed>} code HTTP et corps JSON
     */
    private function call(callable $action, array $get): array
    {
        $_GET = $get;
        ob_start();
        $action();
        $output = (string) ob_get_clean();

        return [(int) http_response_code(), json_decode($output, true, 512, JSON_THROW_ON_ERROR)];
    }
}
