<?php

declare(strict_types=1);

namespace Aura\Tests\Service;

use Aura\Domain\RecordFields;
use Aura\Service\FieldValidationService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Champs complémentaires de l'admission et du dossier : validation pure contre le catalogue
 * RecordFields (longueurs, échelles, dates, e-mail, téléphone) et normalisation.
 */
final class FieldValidationServiceTest extends TestCase
{
    private FieldValidationService $service;

    protected function setUp(): void
    {
        $this->service = new FieldValidationService(new DateTimeImmutable('2026-10-14'));
    }

    public function testToutVideEstValide(): void
    {
        $vides = array_fill_keys(array_keys(RecordFields::PATIENT + RecordFields::EPISODE), '');

        self::assertSame([], $this->service->validate(RecordFields::PATIENT, $vides));
        self::assertSame([], $this->service->validate(RecordFields::EPISODE, $vides));
    }

    public function testUneCleInconnueEstIgnoree(): void
    {
        self::assertSame([], $this->service->validate(RecordFields::PATIENT, ['idPatient' => '1; DROP TABLE']));
        self::assertSame([], $this->service->normalize(RecordFields::PATIENT, ['idPatient' => '1']));
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function valeursInvalides(): iterable
    {
        yield 'score ASA hors échelle' => [['asaScore' => 'VII'], 'asaScore', 'échelle'];
        yield 'GIR 0 non proposé' => [['girScore' => '0'], 'girScore', 'échelle'];
        yield 'date de chirurgie inexistante' => [['surgeryDate' => '2026-02-30'], 'surgeryDate', 'date invalide'];
        yield 'date de chirurgie mal formée' => [['surgeryDate' => '30-01-2026'], 'surgeryDate', 'JJ/MM/AAAA'];
        yield 'prothèses trop longues' => [
            ['removableProstheses' => str_repeat('a', 201)], 'removableProstheses', '200',
        ];
        yield 'motif trop long' => [['admissionReason' => str_repeat('é', 1001)], 'admissionReason', '1000'];
        yield 'note trop longue' => [
            ['medicalHistory' => str_repeat('x', RecordFields::TEXT_MAX + 1)], 'medicalHistory', '10000',
        ];
        yield 'e-mail invalide' => [['trustedPersonEmail' => 'pas-un-mail'], 'trustedPersonEmail', 'e-mail'];
        yield 'téléphone avec lettres' => [
            ['trustedPersonPhone' => '06 AB 00 00 00'], 'trustedPersonPhone', 'téléphone',
        ];
        yield 'nom de naissance trop long' => [['birthLastName' => str_repeat('N', 51)], 'birthLastName', '50'];
    }

    /** @param array<string, mixed> $valeurs */
    #[DataProvider('valeursInvalides')]
    public function testValeurInvalideDonneUneErreurSurCeChamp(array $valeurs, string $champ, string $extrait): void
    {
        $erreurs = $this->service->validate(RecordFields::PATIENT + RecordFields::EPISODE, $valeurs);

        self::assertSame([$champ], array_keys($erreurs));
        self::assertStringContainsString($extrait, $erreurs[$champ]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function valeursValides(): iterable
    {
        yield 'toutes les échelles à une valeur officielle' => [[
            'asaScore' => 'III', 'nyhaClass' => 'IV', 'mcCabeScore' => '2', 'knausScore' => 'D',
            'girScore' => '6', 'intermacsProfile' => '7',
        ]];
        yield 'date de chirurgie valide' => [['surgeryDate' => '2026-10-13']];
        yield 'date de chirurgie future (intervention programmée)' => [['surgeryDate' => '2026-12-01']];
        yield 'coordonnées de la personne de confiance' => [[
            'trustedPersonName' => 'Jeanne Martin', 'trustedPersonPhone' => '+33 6 00 00 00 00',
            'trustedPersonEmail' => 'jeanne@example.com',
        ]];
        yield 'notes multilignes avec CRLF' => [['medicalHistory' => "HTA\r\nDiabète de type 2"]];
    }

    /** @param array<string, mixed> $valeurs */
    #[DataProvider('valeursValides')]
    public function testValeurValideNeDonneAucuneErreur(array $valeurs): void
    {
        self::assertSame([], $this->service->validate(RecordFields::PATIENT + RecordFields::EPISODE, $valeurs));
    }

    public function testNormaliseEspacesFinsDeLigneEtVides(): void
    {
        $clean = $this->service->normalize(RecordFields::PATIENT, [
            'medicalHistory' => "  HTA\r\nDiabète  ",
            'lifestyle' => '   ',
            'comorbidities' => ['tableau forgé'],
        ]);

        self::assertSame(
            ['medicalHistory' => "HTA\nDiabète", 'lifestyle' => null, 'comorbidities' => null],
            $clean
        );
    }

    public function testLeMessageNeRepeteJamaisLaValeurSaisie(): void
    {
        $erreurs = $this->service->validate(RecordFields::PATIENT, ['trustedPersonEmail' => 'secret@patient']);

        self::assertStringNotContainsString('secret', $erreurs['trustedPersonEmail']);
    }
}
