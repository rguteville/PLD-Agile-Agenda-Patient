<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Service\AppointmentService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validation d'un soin (RG7), contrôle pur sans base. Authentification factice : un soignant de la liste et
 * un mot de passe non vide. Les règles de rotation sont dans AppointmentIntegrationTest.
 */
final class AppointmentServiceTest extends TestCase
{
    private const PERFORMERS = ['PRA01' => 'Claire Morel (Médecin)', 'PRA04' => 'Léa Moreau (IDE)'];
    private const AUTH = ['idPerformer' => 'PRA04', 'password' => 'x'];

    public function testCareDoneNowByAListedCaregiverIsAccepted(): void
    {
        $input = self::AUTH + ['action' => 'done', 'completedAt' => '2026-10-14T10:30'];

        self::assertSame([], AppointmentService::validate($input, self::PERFORMERS, $this->now()));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'soignant hors liste' => [
            ['action' => 'done', 'completedAt' => '2026-10-14T10:00', 'idPerformer' => 'ZZZ', 'password' => 'x'],
            'auth',
        ];
        yield 'mot de passe vide' => [
            ['action' => 'done', 'completedAt' => '2026-10-14T10:00', 'idPerformer' => 'PRA04', 'password' => ' '],
            'auth',
        ];
        yield 'heure de réalisation à venir' => [
            self::AUTH + ['action' => 'done', 'completedAt' => '2026-10-14T12:00'],
            'completedAt',
        ];
        yield 'non effectué sans motif' => [self::AUTH + ['action' => 'not_done', 'comment' => '  '], 'comment'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidInputs')]
    public function testInvalidActionGivesAnErrorOnThatField(array $input, string $field): void
    {
        self::assertArrayHasKey($field, AppointmentService::validate($input, self::PERFORMERS, $this->now()));
    }

    public function testCareAssistantCannotValidateAMedication(): void
    {
        self::assertFalse(AppointmentService::canPerform('Aide-soignant(e)', true));
        self::assertTrue(AppointmentService::canPerform('Aide-soignant(e)', false), 'un soin, oui');
        self::assertTrue(AppointmentService::canPerform('Infirmier(e) diplômé(e) d\'État', true));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-14 10:30:00');
    }
}
