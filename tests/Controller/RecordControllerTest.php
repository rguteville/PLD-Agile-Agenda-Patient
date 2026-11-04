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
 * POST /sejour/champ/modifier : jeton de session obligatoire, corps JSON ou formulaire, codes HTTP
 * selon le statut du service. Processus séparé : en-têtes HTTP et session.
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
    public function testWithoutTokenUpdateIsRejectedWithoutCallingService(): void
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
    public function testWithTokenServiceIsCalledAndResponseIs200(): void
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

    /**
     * Retours du 2026-11-04 : une modification en ligne exige l'authentification factice (médecin et mot de
     * passe), sinon 422 sans toucher au dossier ; le décès ne se déclare jamais en ligne.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInlineEditNeedsFactitiousAuthenticationAndNeverDeclaresDeath(): void
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
    public function testForbiddenFieldAndInvalidValueGive422(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->method('update')->willReturn([
            'status' => RecordEditService::STATUS_FORBIDDEN_FIELD, 'message' => 'Ce champ ne peut pas être modifié.',
            'value' => null, 'display' => null,
        ]);

        [$status, $body] = $this->call($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'episodeOfCare' => '3', 'field' => 'lastName', 'value' => 'x',
            'oldValue' => '', 'validator' => 'PRA01', 'password' => 'x',
        ]);

        self::assertSame(422, $status);
        self::assertFalse($body['ok']);
        self::assertSame('Ce champ ne peut pas être modifié.', $body['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIncompleteRequestGives400(): void
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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPartWithoutTokenRejectedAndIncompletePartGives400(): void
    {
        $edit = $this->createMock(RecordEditService::class);
        $section = $this->createMock(RecordSectionService::class);
        $section->expects($this->never())->method('updatePart');
        $controller = new RecordController($edit, $section);

        $_POST = ['episodeOfCare' => '1', 'part' => 'history', 'values' => ['a' => 'b'], 'oldValues' => []];
        ob_start();
        $controller->updatePart();
        ob_end_clean();
        self::assertSame(403, http_response_code(), 'sans jeton');

        $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
        $_POST = ['episodeOfCare' => 'abc', 'part' => 'history', 'values' => [], 'oldValues' => []];
        ob_start();
        $controller->updatePart();
        ob_end_clean();
        self::assertSame(400, http_response_code(), 'séjour invalide');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSavedPartRedirectsToPart(): void
    {
        $edit = $this->createMock(RecordEditService::class);
        $section = $this->createMock(RecordSectionService::class);
        $section->expects($this->once())->method('updatePart')
            ->with(3, 'history', ['lifestyle' => 'x'], ['lifestyle' => ''], 'PRA01', 'password')
            ->willReturn(['status' => RecordSectionService::STATUS_SAVED, 'errors' => [], 'message' => null]);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
        $_POST = [
            'episodeOfCare' => '3', 'part' => 'history', 'values' => ['lifestyle' => 'x'],
            'oldValues' => ['lifestyle' => ''],
            'validator' => 'PRA01', 'password' => 'password',
        ];

        ob_start();
        (new RecordController($edit, $section))->updatePart();
        $body = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, http_response_code());
        self::assertSame('/sejour?id=3#dossier-history', $body['redirect']);
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
