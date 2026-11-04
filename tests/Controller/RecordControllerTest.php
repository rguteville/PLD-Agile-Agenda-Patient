<?php

declare(strict_types=1);

namespace Aura\Tests\Controller;

use Aura\Controller\RecordController;
use Aura\Core\Csrf;
use Aura\Service\RecordEditService;
use Aura\Service\RecordSectionService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Édition en ligne (POST /sejour/champ/modifier) : jeton CSRF, authentification factice d'un médecin,
 * décès jamais en ligne, codes HTTP. Processus séparé : en-têtes HTTP et session.
 */
final class RecordControllerTest extends TestCase
{
    private const AUTHOR = 'Claire Morel (médecin, authentification factice)';

    protected function tearDown(): void
    {
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['CONTENT_TYPE']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutCsrfTokenNothingIsWritten(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->expects($this->never())->method('update');

        [$status, $body] = $this->call($service, null, [
            'entity' => 'patient', 'id' => '9', 'episodeOfCare' => '3', 'field' => 'medicalHistory', 'value' => 'HTA',
            'oldValue' => '',
        ]);

        self::assertSame(403, $status);
        self::assertFalse($body['ok']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAuthenticatedEditIsSavedUnderTheDoctorName(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->expects($this->once())->method('update')
            ->with('patient', 9, 'medicalHistory', 'HTA', '', self::AUTHOR, 3)
            ->willReturn([
                'status' => RecordEditService::STATUS_SAVED, 'message' => 'Enregistré',
                'value' => 'HTA', 'display' => 'HTA',
            ]);

        [$status, $body] = $this->call($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'episodeOfCare' => '3', 'field' => 'medicalHistory', 'value' => 'HTA',
            'oldValue' => '', 'validator' => 'PRA01', 'password' => 'x',
        ]);

        self::assertSame(200, $status);
        self::assertTrue($body['ok']);
        self::assertTrue($body['saved']);
        self::assertSame('HTA', $body['display']);
        self::assertSame(self::AUTHOR, $body['author'], 'l\'historique affichera le médecin');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInlineEditWithoutDoctorPasswordIsRefused(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->expects($this->never())->method('update');

        [$status, $body] = $this->call($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'episodeOfCare' => '3', 'field' => 'medicalHistory', 'value' => 'HTA',
            'oldValue' => '', 'validator' => 'PRA01', 'password' => '',
        ]);
        self::assertSame(422, $status);
        self::assertTrue($body['auth']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeathIsNeverChangedInline(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->expects($this->never())->method('update');

        [$status, $body] = $this->call($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'episodeOfCare' => '3', 'field' => 'deceased', 'value' => '1',
            'oldValue' => '0', 'validator' => 'PRA01', 'password' => 'x',
        ]);
        self::assertSame(422, $status);
        self::assertStringContainsString('Déclarer le décès', $body['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBloodTypeIsNeverChangedInlineEvenByADoctor(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->expects($this->never())->method('update');

        [$status, $body] = $this->call($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'episodeOfCare' => '3', 'field' => 'bloodType', 'value' => 'O+',
            'oldValue' => 'A+', 'validator' => 'PRA01', 'password' => 'x',
        ]);
        self::assertSame(422, $status);
        self::assertStringContainsString('Modifier', $body['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConflictGives409WithCurrentValue(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->method('update')->willReturn([
            'status' => RecordEditService::STATUS_CONFLICT, 'message' => 'Modifié entre-temps.',
            'value' => 'HTA, diabète', 'display' => 'HTA, diabète',
        ]);

        [$status, $body] = $this->call($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'episodeOfCare' => '3', 'field' => 'medicalHistory', 'value' => 'x',
            'oldValue' => 'HTA', 'validator' => 'PRA01', 'password' => 'x',
        ]);

        self::assertSame(409, $status);
        self::assertTrue($body['conflict']);
        self::assertSame('HTA, diabète', $body['value']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMalformedRequestGives400(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->expects($this->never())->method('update');

        [$status] = $this->call($service, Csrf::token(), ['entity' => 'patient', 'id' => 'abc', 'field' => 'x']);
        self::assertSame(400, $status, 'id invalide');

        [$status] = $this->call($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'field' => 'medicalHistory', 'value' => 'x', 'oldValue' => '',
        ]);
        self::assertSame(400, $status, 'séjour absent');

        [$status] = $this->call($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'episodeOfCare' => '3', 'field' => 'medicalHistory',
            'value' => ['forgé'], 'oldValue' => '',
        ]);
        self::assertSame(400, $status, 'valeur non scalaire : jamais interprétée comme un vidage');
    }

    /**
     * @param array<string, string> $post
     * @return array{int, array<string, mixed>}
     */
    private function call(RecordEditService $service, ?string $token, array $post): array
    {
        $_POST = $post;
        if ($token !== null) {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        }
        // Authentification factice simulée : PRA01 avec un mot de passe non vide.
        $section = $this->createMock(RecordSectionService::class);
        $section->method('inlineAuthor')->willReturnCallback(
            static fn (string $validator, string $password): ?string
                => $validator === 'PRA01' && trim($password) !== '' ? self::AUTHOR : null
        );
        ob_start();
        (new RecordController($service, $section))->update();
        $sortie = (string) ob_get_clean();

        return [http_response_code(), json_decode($sortie, true, 512, JSON_THROW_ON_ERROR)];
    }
}
