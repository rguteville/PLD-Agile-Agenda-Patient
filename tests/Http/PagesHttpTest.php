<?php

declare(strict_types=1);

namespace Aura\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Les écrans principaux répondent avec le bon code, sans erreur PHP dans le HTML, et montrent ce qui les
 * caractérise (données de démo de database/test.sql). Les saisies malformées donnent 404 ou 400, jamais
 * un plantage.
 */
final class PagesHttpTest extends HttpTestCase
{
    /** @return iterable<string, array{string, int, string}> chemin, code attendu, texte attendu */
    public static function pages(): iterable
    {
        yield 'admission' => ['/', 200, 'Patient connu ?'];
        yield 'liste des patients' => ['/patients', 200, 'Simpson'];
        yield 'dossier du séjour' => ['/sejour?id=1', 200, 'Dossier du séjour'];
        yield 'prescriptions du séjour' => ['/care-plans?idEpisodeOfCare=1', 200, 'Prescriptions du séjour'];
        yield 'agenda du patient' => ['/agenda-patient?id=1&date=2026-10-14', 200, 'Paracétamol'];
        yield 'agenda du service' => ['/agenda-service?date=2026-10-14', 200, 'soins en retard'];
        yield 'équipes de la rotation' => ['/equipes?date=2026-10-14&rotation=matin', 200, 'Équipe A'];
        yield 'page inexistante' => ['/page-inexistante', 404, 'Erreur 404'];
        yield 'dossier inconnu' => ['/sejour?id=999999', 404, 'Erreur 404'];
        yield 'dossier sans identifiant' => ['/sejour', 400, 'Erreur 400'];
        yield 'numéro de séjour malformé' => ['/agenda-patient?numSejour=nope', 404, 'Erreur 404'];
        yield 'numéro de séjour en tableau' => ['/agenda-patient?numSejour[]=1', 404, 'Erreur 404'];
        yield 'octet UTF-8 invalide' => ['/patients?q=a%FF', 400, 'Requête invalide'];
    }

    #[DataProvider('pages')]
    public function testPageAnswersWithItsCodeAndContentWithoutPhpError(string $path, int $status, string $text): void
    {
        $response = $this->get($path);

        self::assertSame($status, $response->status, $path);
        self::assertStringContainsString($text, $response->body, $path);
        foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $phpError) {
            self::assertStringNotContainsString($phpError, $response->body, $path . ' : erreur PHP dans la page');
        }
    }

    public function testEveryScreenOfAnEpisodeShowsThePatientBannerWithAllergies(): void
    {
        foreach (['/sejour?id=1', '/agenda-patient?id=1', '/care-plans?idEpisodeOfCare=1'] as $path) {
            $body = $this->get($path)->body;
            self::assertStringContainsString('class="patient-banner"', $body, $path . ' : RG2, bandeau');
            self::assertStringContainsString('Pénicilline', $body, $path . ' : RG2, allergies');
        }
    }
}
