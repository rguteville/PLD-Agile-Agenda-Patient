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
    public function testTooShortInputIsRejectedWithoutQueryingService(): void
    {
        $service = $this->createMock(PatientSearchService::class);
        $service->expects($this->never())->method('search');

        [$status, $body] = $this->call($service, 'search', ['q' => ' d ']);

        self::assertSame(422, $status);
        self::assertSame(['patientSearch'], array_keys($body['errors']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnknownNameGivesEmptyListWithoutError(): void
    {
        $service = $this->createMock(PatientSearchService::class);
        $service->method('search')->with('zzinconnu')->willReturn([
            'mode' => 'name', 'results' => [], 'truncated' => false, 'homonyms' => false,
        ]);

        [$status, $body] = $this->call($service, 'search', ['q' => 'zzinconnu']);

        self::assertSame(200, $status);
        self::assertSame([
            'ok' => true, 'mode' => 'name', 'results' => [], 'total' => 0, 'truncated' => false, 'homonyms' => false,
        ], $body);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResultsReturnedWithHomonymsAndTruncation(): void
    {
        $result = ['idPatient' => 9, 'lastName' => 'Durand', 'firstName' => 'Alice', 'birthLastName' => null,
            'birthDate' => '15/06/1990', 'age' => 36, 'sex' => 'F', 'ipp' => null, 'lastEpisodeOfCare' => null];
        $service = $this->createMock(PatientSearchService::class);
        $service->method('search')->willReturn([
            'mode' => 'name', 'results' => [$result, $result], 'truncated' => true, 'homonyms' => true,
        ]);

        [$status, $body] = $this->call($service, 'search', ['q' => 'durand']);

        self::assertSame(200, $status);
        self::assertSame(2, $body['total']);
        self::assertTrue($body['truncated']);
        self::assertTrue($body['homonyms']);
        self::assertSame(
            [
                'idPatient', 'lastName', 'firstName', 'birthLastName', 'birthDate', 'age', 'sex', 'ipp',
                'lastEpisodeOfCare',
            ],
            array_keys($body['results'][0]),
            'aucun champ médical dans la réponse'
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnknownCardGives404(): void
    {
        $service = $this->createMock(PatientSearchService::class);
        $service->method('record')->with(404)->willReturn(null);

        [$status] = $this->call($service, 'record', ['id' => '404']);

        self::assertSame(404, $status);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCardInFormFormatWithAllergies(): void
    {
        $service = $this->createMock(PatientSearchService::class);
        $service->method('record')->with(9)->willReturn([
            'identity' => ['idPatient' => '9', 'lastName' => 'Durand', 'firstName' => 'Alice', 'ipp' => null,
                'birthDate' => '1990-06-15', 'sex' => 'F'],
            'details' => ['medicalHistory' => 'HTA', 'lifestyle' => null],
            'allergies' => [['idSubstance' => 'ALG01', 'label' => 'Pénicilline']],
        ]);

        [$status, $body] = $this->call($service, 'record', ['id' => '9']);

        self::assertSame(200, $status);
        self::assertSame('Durand', $body['patient']['lastname']);
        self::assertSame('', $body['patient']['ipp']);
        self::assertSame('F', $body['patient']['gender']);
        self::assertSame('15/06/1990', $body['patient']['birthDate'], 'date au format saisi : JJ/MM/AAAA');
        self::assertSame('HTA', $body['patient']['medicalHistory']);
        self::assertSame('', $body['patient']['lifestyle']);
        self::assertArrayNotHasKey('searchMode', $body['patient']);
        self::assertSame(['ALG01'], $body['allergies']);
        self::assertSame(['Pénicilline'], $body['allergyLabels']);
    }

    /**
     * @param array<string, string> $get
     * @return array{int, array<string, mixed>}
     */
    private function call(PatientSearchService $service, string $method, array $get): array
    {
        $_GET = $get;
        $controller = new PatientController(patientSearchService: $service);
        ob_start();
        $controller->$method();
        $sortie = (string) ob_get_clean();

        return [http_response_code(), json_decode($sortie, true, 512, JSON_THROW_ON_ERROR)];
    }
}
