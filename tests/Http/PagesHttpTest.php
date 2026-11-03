<?php

declare(strict_types=1);

namespace Aura\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Chaque page de l'application répond avec le bon code, sans erreur PHP dans le HTML, et montre ce
 * qui la caractérise (données de démo de database/test.sql). Reprend et étend l'ancien smoke shell.
 */
final class PagesHttpTest extends HttpTestCase
{
    /** @return iterable<string, array{string, int, string}> chemin, code attendu, texte attendu */
    public static function pages(): iterable
    {
        yield 'admission' => ['/', 200, 'Patient connu ?'];
        yield 'liste des patients' => ['/patients', 200, 'Simpson'];
        yield 'recherche dans la liste' => ['/patients?q=rip', 200, 'Ripley'];
        yield 'recherche IPP invalide' => ['/patients/recherche-ipp?ipp=abc', 422, 'IPP'];
        yield 'recherche de patient (JSON)' => ['/patients/recherche?q=simp', 200, 'Simpson'];
        yield 'modifier un patient' => ['/patients/modifier?id=1', 200, 'Simpson'];
        yield 'modifier un patient inconnu' => ['/patients/modifier?id=999999', 404, 'Erreur 404'];
        yield 'dossier du séjour' => ['/sejour?id=1', 200, 'Dossier du séjour'];
        yield 'dossier inconnu' => ['/sejour?id=999999', 404, 'Erreur 404'];
        yield 'dossier sans identifiant' => ['/sejour', 400, 'Erreur 400'];
        yield 'prescriptions du séjour' => ['/care-plans?idEpisodeOfCare=1', 200, 'Prescriptions du séjour'];
        yield 'nouvelle prescription' => ['/care-plans/new?idEpisodeOfCare=1', 200, 'Nouvelles prescriptions'];
        yield 'agenda du patient' => ['/agenda-patient?id=1&date=2026-10-14', 200, 'Paracétamol'];
        yield 'agenda du patient, semaine' => ['/agenda-patient?id=1&date=2026-10-14&vue=semaine', 200, 'Semaine du'];
        yield 'agenda du service par défaut' => ['/agenda-service', 200, 'Agenda du service'];
        yield 'agenda du service, jour de démo' => ['/agenda-service?date=2026-10-14', 200, 'soin en retard'];
        yield 'service inconnu' => ['/agenda-service?idHealthcareService=ZZZ', 404, 'Service inconnu'];
        yield 'équipes de la vacation' => ['/equipes?date=2026-10-14&vacation=jour', 200, 'Équipe A'];
        yield 'mon planning sans soignant' => ['/mon-planning', 200, 'Choisissez votre nom'];
        yield 'mon planning' => ['/mon-planning?idPractitioner=PRA04&date=2026-10-14&vacation=jour', 200, 'SIMPSON'];
        yield 'confirmation de suppression' => ['/patients/supprimer?id=4', 200, 'Parker'];
        yield 'suppression refusée affichée' => ['/patients/supprimer?id=1', 200, 'séjour en cours'];
        yield 'page inexistante' => ['/page-inexistante', 404, 'Erreur 404'];
    }

    #[DataProvider('pages')]
    public function testPageAnswersWithItsContentAndNoPhpError(string $path, int $status, string $text): void
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
