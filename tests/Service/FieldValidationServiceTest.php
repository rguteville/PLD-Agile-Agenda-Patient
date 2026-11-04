<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Domain\RecordFields;
use Aura\Service\FieldValidationService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Champs complémentaires de l'admission et du dossier : validation pure contre le catalogue RecordFields
 * (échelles, dates, longueurs, e-mail). Une clé hors catalogue n'atteint jamais la base.
 */
final class FieldValidationServiceTest extends TestCase
{
    private FieldValidationService $service;

    protected function setUp(): void
    {
        $this->service = new FieldValidationService(new DateTimeImmutable('2026-10-14'));
    }

    public function testUnknownOrForgedKeyIsIgnored(): void
    {
        self::assertSame([], $this->service->validate(RecordFields::PATIENT, ['idPatient' => '1; DROP TABLE']));
        self::assertSame([], $this->service->normalize(RecordFields::PATIENT, ['idPatient' => '1']));
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidValues(): iterable
    {
        yield 'score ASA hors échelle' => [['asaScore' => 'VII'], 'asaScore', 'échelle'];
        yield 'date de chirurgie inexistante' => [['surgeryDate' => '2026-02-30'], 'surgeryDate', 'date invalide'];
        yield 'antécédents trop longs' => [
            ['medicalHistory' => str_repeat('x', RecordFields::TEXT_MAX + 1)], 'medicalHistory', '10000',
        ];
        yield 'e-mail invalide' => [['trustedPersonEmail' => 'pas-un-mail'], 'trustedPersonEmail', 'e-mail'];
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('invalidValues')]
    public function testInvalidValueGivesErrorOnThatField(array $values, string $field, string $excerpt): void
    {
        $errors = $this->service->validate(RecordFields::PATIENT + RecordFields::EPISODE, $values);

        self::assertSame([$field], array_keys($errors));
        self::assertStringContainsString($excerpt, $errors[$field]);
    }
}
