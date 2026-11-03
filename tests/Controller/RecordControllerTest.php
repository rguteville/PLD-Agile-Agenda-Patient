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
    protected function tearDown(): void
    {
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['CONTENT_TYPE']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSansJetonLaModificationEstRefuseeSansAppelerLeService(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->expects($this->never())->method('update');

        [$statut, $corps] = $this->appeler($service, null, [
            'entity' => 'patient', 'id' => '9', 'sejour' => '3', 'field' => 'medicalHistory', 'value' => 'HTA',
            'oldValue' => '',
        ]);

        self::assertSame(403, $statut);
        self::assertFalse($corps['ok']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAvecJetonLeServiceEstAppeleEtLaReponseEst200(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->expects($this->once())->method('update')
            ->with('patient', 9, 'medicalHistory', 'HTA', '', 'non identifié', 3)
            ->willReturn([
                'status' => RecordEditService::STATUS_SAVED, 'message' => 'Enregistré',
                'value' => 'HTA', 'display' => 'HTA',
            ]);

        [$statut, $corps] = $this->appeler($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'sejour' => '3', 'field' => 'medicalHistory', 'value' => 'HTA',
            'oldValue' => '',
        ]);

        self::assertSame(200, $statut);
        self::assertTrue($corps['ok']);
        self::assertTrue($corps['saved']);
        self::assertSame('HTA', $corps['display']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConflitDonne409AvecLaValeurActuelle(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->method('update')->willReturn([
            'status' => RecordEditService::STATUS_CONFLICT, 'message' => 'Modifié entre-temps.',
            'value' => 'HTA, diabète', 'display' => 'HTA, diabète',
        ]);

        [$statut, $corps] = $this->appeler($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'sejour' => '3', 'field' => 'medicalHistory', 'value' => 'x',
            'oldValue' => 'HTA',
        ]);

        self::assertSame(409, $statut);
        self::assertTrue($corps['conflict']);
        self::assertSame('HTA, diabète', $corps['value']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testChampInterditEtValeurInvalideDonnent422(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->method('update')->willReturn([
            'status' => RecordEditService::STATUS_FORBIDDEN_FIELD, 'message' => 'Ce champ ne peut pas être modifié.',
            'value' => null, 'display' => null,
        ]);

        [$statut, $corps] = $this->appeler($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'sejour' => '3', 'field' => 'lastName', 'value' => 'x',
            'oldValue' => '',
        ]);

        self::assertSame(422, $statut);
        self::assertFalse($corps['ok']);
        self::assertSame('Ce champ ne peut pas être modifié.', $corps['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRequeteIncompleteDonne400(): void
    {
        $service = $this->createMock(RecordEditService::class);
        $service->expects($this->never())->method('update');

        [$statut] = $this->appeler($service, Csrf::token(), ['entity' => 'patient', 'id' => 'abc', 'field' => 'x']);
        self::assertSame(400, $statut, 'id invalide');

        [$statut] = $this->appeler($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'field' => 'medicalHistory', 'value' => 'x', 'oldValue' => '',
        ]);
        self::assertSame(400, $statut, 'séjour absent');

        [$statut] = $this->appeler($service, Csrf::token(), [
            'entity' => 'patient', 'id' => '9', 'sejour' => '3', 'field' => 'medicalHistory',
            'value' => ['forgé'], 'oldValue' => '',
        ]);
        self::assertSame(400, $statut, 'valeur non scalaire : jamais interprétée comme un vidage');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPartieSansJetonRefuseeEtPartieIncompleteDonne400(): void
    {
        $edit = $this->createMock(RecordEditService::class);
        $section = $this->createMock(RecordSectionService::class);
        $section->expects($this->never())->method('updatePart');
        $controller = new RecordController($edit, $section);

        $_POST = ['sejour' => '1', 'part' => 'history', 'values' => ['a' => 'b'], 'oldValues' => []];
        ob_start();
        $controller->updatePart();
        ob_end_clean();
        self::assertSame(403, http_response_code(), 'sans jeton');

        $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
        $_POST = ['sejour' => 'abc', 'part' => 'history', 'values' => [], 'oldValues' => []];
        ob_start();
        $controller->updatePart();
        ob_end_clean();
        self::assertSame(400, http_response_code(), 'séjour invalide');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPartieEnregistreeRedirigeVersLaPartie(): void
    {
        $edit = $this->createMock(RecordEditService::class);
        $section = $this->createMock(RecordSectionService::class);
        $section->expects($this->once())->method('updatePart')
            ->with(3, 'history', ['lifestyle' => 'x'], ['lifestyle' => ''], 'PRA01', 'mdp')
            ->willReturn(['status' => RecordSectionService::STATUS_SAVED, 'errors' => [], 'message' => null]);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
        $_POST = [
            'sejour' => '3', 'part' => 'history', 'values' => ['lifestyle' => 'x'], 'oldValues' => ['lifestyle' => ''],
            'validator' => 'PRA01', 'password' => 'mdp',
        ];

        ob_start();
        (new RecordController($edit, $section))->updatePart();
        $corps = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, http_response_code());
        self::assertSame('/sejour?id=3#dossier-history', $corps['redirect']);
    }

    /**
     * @param array<string, string> $post
     * @return array{int, array<string, mixed>}
     */
    private function appeler(RecordEditService $service, ?string $token, array $post): array
    {
        $_POST = $post;
        if ($token !== null) {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        }
        ob_start();
        (new RecordController($service))->update();
        $sortie = (string) ob_get_clean();

        return [http_response_code(), json_decode($sortie, true, 512, JSON_THROW_ON_ERROR)];
    }
}
