<?php

declare(strict_types=1);

namespace Aura\Tests\Controller;

use Aura\Controller\PatientController;
use Aura\Service\IppSearchService;
use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * AURA-15 : GET /patients/recherche-ipp répond en JSON avec les noms de champs du formulaire
 * d'admission. Chaque test tourne dans un processus séparé : le contrôleur envoie des en-têtes
 * HTTP, impossibles à poser une fois la sortie de PHPUnit commencée.
 */
final class PatientControllerIppSearchTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testKnownIppReturnsIdentityInFormFormatAndAllergies(): void
    {
        $service = $this->createMock(IppSearchService::class);
        $service->method('search')->with('100200300')->willReturn([
            'idPatient' => 9,
            'ipp' => '100200300',
            'lastName' => 'Durand',
            'firstName' => 'Alice',
            'birthDate' => '1990-06-15',
            'sex' => 'F',
            'allergies' => [['label' => 'Pénicilline', 'criticality' => 'Élevée']],
        ]);

        [$status, $body] = $this->call($service, ['ipp' => ' 100200300 ']);

        self::assertSame(200, $status);
        self::assertSame([
            'ok' => true,
            'found' => true,
            'patient' => [
                'lastname' => 'Durand',
                'firstname' => 'Alice',
                'ipp' => '100200300',
                'birthDate' => '1990-06-15',
                'gender' => 'F',
                'editLink' => '/patients/modifier?id=9',
            ],
            'allergies' => [['label' => 'Pénicilline', 'criticality' => 'Élevée']],
        ], $body);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnknownDateAndSexGiveEmptyFields(): void
    {
        $service = $this->createMock(IppSearchService::class);
        $service->method('search')->willReturn([
            'idPatient' => 7,
            'ipp' => '123456',
            'lastName' => 'X',
            'firstName' => 'X',
            'birthDate' => null,
            'sex' => null,
            'allergies' => [],
        ]);

        [, $body] = $this->call($service, ['ipp' => '123456']);

        self::assertSame('', $body['patient']['birthDate']);
        self::assertSame('', $body['patient']['gender']);
        self::assertSame([], $body['allergies']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnknownIppReturnsManualEntryMessage(): void
    {
        $service = $this->createMock(IppSearchService::class);
        $service->method('search')->willReturn(null);

        [$status, $body] = $this->call($service, ['ipp' => '999999999']);

        self::assertSame(200, $status);
        self::assertSame(['ok' => true, 'found' => false, 'message' => 'IPP inconnu, saisie manuelle.'], $body);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMalformedIppIsRejectedWithoutQueryingDatabase(): void
    {
        $service = $this->createMock(IppSearchService::class);
        $service->expects($this->never())->method('search');

        [$status, $body] = $this->call($service, ['ipp' => '12']);

        self::assertSame(422, $status);
        self::assertFalse($body['ok']);
        self::assertSame(['ipp'], array_keys($body['errors']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEmptyOrNonTextualIppIsRejected(): void
    {
        $service = $this->createMock(IppSearchService::class);
        $service->expects($this->never())->method('search');

        [$status] = $this->call($service, []);
        self::assertSame(422, $status);

        [$status] = $this->call($service, ['ipp' => ['table', 'forgé']]);
        self::assertSame(422, $status);
    }

    public function testWithoutServiceSearchIsImpossible(): void
    {
        $this->expectException(LogicException::class);

        (new PatientController())->searchByIpp();
    }

    /**
     * Exécute GET /patients/recherche-ipp avec ce $_GET, comme public/index.php.
     *
     * @param array<string, mixed> $get
     * @return array{int, array<string, mixed>} statut HTTP et corps JSON décodé
     */
    private function call(IppSearchService $service, array $get): array
    {
        $_GET = $get;
        ob_start();
        try {
            (new PatientController(ippSearchService: $service))->searchByIpp();
        } finally {
            $sortie = (string) ob_get_clean();
        }

        return [(int) http_response_code(), json_decode($sortie, true, flags: JSON_THROW_ON_ERROR)];
    }
}
