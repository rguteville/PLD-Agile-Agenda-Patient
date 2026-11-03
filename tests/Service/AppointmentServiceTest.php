<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\AppointmentService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AURA-26 / AURA-28 : contrôle d'une action sur un soin (pur, sans base). L'authentification est
 * factice : un soignant de la liste et un mot de passe non vide.
 */
final class AppointmentServiceTest extends TestCase
{
    private const PERFORMERS = ['PRA01' => 'Claire Morel (Médecin)', 'PRA04' => 'Léa Moreau (IDE)'];
    private const NOW = '2026-10-14 10:30:00';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function validInputs(): iterable
    {
        $auth = ['idPerformer' => 'PRA04', 'password' => 'x'];
        yield 'réalisé maintenant' => [$auth + ['action' => 'done', 'completedAt' => '2026-10-14T10:30']];
        yield 'réalisé plus tôt avec note' => [$auth + [
            'action' => 'done', 'completedAt' => '2026-10-14T06:05', 'comment' => 'Patient au scanner',
        ]];
        yield 'réalisé 3 min dans le futur (horloges décalées)' => [$auth + [
            'action' => 'done', 'completedAt' => '2026-10-14T10:33',
        ]];
        yield 'non effectué avec motif' => [$auth + ['action' => 'not_done', 'comment' => 'Refus du patient']];
        yield 'note seule' => [$auth + ['action' => 'note', 'comment' => 'À jeun depuis minuit']];
    }

    #[DataProvider('validInputs')]
    public function testValidActionGivesNoError(array $input): void
    {
        self::assertSame([], AppointmentService::validate($input, self::PERFORMERS, $this->now()));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): iterable
    {
        $auth = ['idPerformer' => 'PRA04', 'password' => 'x'];
        yield 'soignant absent' => [['action' => 'note', 'comment' => 'x', 'password' => 'x'], 'auth'];
        yield 'soignant hors liste' => [
            ['action' => 'note', 'comment' => 'x', 'idPerformer' => 'ZZZ', 'password' => 'x'], 'auth',
        ];
        yield 'mot de passe vide' => [
            ['action' => 'note', 'comment' => 'x', 'idPerformer' => 'PRA04', 'password' => '  '], 'auth',
        ];
        yield 'heure absente' => [$auth + ['action' => 'done'], 'completedAt'];
        yield 'heure mal formée' => [$auth + ['action' => 'done', 'completedAt' => '14/10/2026 08:00'], 'completedAt'];
        yield 'heure inexistante' => [$auth + ['action' => 'done', 'completedAt' => '2026-02-30T08:00'], 'completedAt'];
        yield 'heure à venir' => [$auth + ['action' => 'done', 'completedAt' => '2026-10-14T12:00'], 'completedAt'];
        yield 'non effectué sans motif' => [$auth + ['action' => 'not_done', 'comment' => '  '], 'comment'];
        yield 'note vide' => [$auth + ['action' => 'note'], 'comment'];
        yield 'note trop longue' => [$auth + ['action' => 'note', 'comment' => str_repeat('a', 101)], 'comment'];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidActionGivesErrorOnThatField(array $input, string $field): void
    {
        $errors = AppointmentService::validate($input, self::PERFORMERS, $this->now());

        self::assertArrayHasKey($field, $errors);
    }

    public function testUnknownActionIsRejectedAlone(): void
    {
        self::assertSame(
            ['action' => 'Action inconnue.'],
            AppointmentService::validate(['action' => 'delete'], self::PERFORMERS, $this->now())
        );
    }

    public function testValidationNeverRepeatsThePassword(): void
    {
        $errors = AppointmentService::validate(
            ['action' => 'done', 'idPerformer' => 'ZZZ', 'password' => 'secret-à-ne-pas-afficher'],
            self::PERFORMERS,
            $this->now()
        );

        self::assertStringNotContainsString('secret', implode(' ', $errors));
    }

    /** @return iterable<string, array{?string, bool, bool}> */
    public static function categories(): iterable
    {
        yield 'IDE, médicament' => ['Infirmier(e) diplômé(e) d\'État', true, true];
        yield 'médecin, médicament' => ['Médecin réanimateur', true, true];
        yield 'aide-soignant, soin' => ['Aide-soignant(e)', false, true];
        yield 'aide-soignant, médicament' => ['Aide-soignant(e)', true, false];
        yield 'rôle inconnu, médicament' => [null, true, true];
    }

    #[DataProvider('categories')]
    public function testOnlyQualifiedCategoriesValidateMedication(?string $role, bool $isMedication, bool $can): void
    {
        self::assertSame($can, AppointmentService::canPerform($role, $isMedication));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
