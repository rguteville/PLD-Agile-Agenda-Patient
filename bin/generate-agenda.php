<?php

/**
 * Génération quotidienne de l'agenda des séjours en cours.
 *
 * Crée les soins planifiés manquants de chaque ligne de prescription active, de maintenant jusqu'à
 * la fin du jour J + AgendaGenerationService::HORIZON_DAYS. Idempotent : relancé deux fois de suite,
 * le second passage ne crée rien. Aucun soin n'est créé dans le passé ni modifié (RG9).
 *
 * Usage : php bin/generate-agenda.php     (cron quotidien : voir README, « Génération quotidienne de l'agenda »)
 * Même configuration que le site (config/config.php, ou variables DB_* comme le CI).
 * Code de sortie : 0 si tout s'est bien passé, 1 en cas d'erreur (rien n'est alors enregistré).
 */

declare(strict_types=1);

use Aura\Core\Database;
use Aura\Dao\AppointmentDao;
use Aura\Dao\CarePlanDao;
use Aura\Dao\Transaction;
use Aura\Service\AgendaGenerationService;

require dirname(__DIR__) . '/vendor/autoload.php';

// Même fuseau que public/index.php : les heures en base sont celles du service (Lyon). Sans cela, un PHP
// en UTC prendrait « maintenant » une à deux heures trop tôt et créerait des soins déjà passés.
date_default_timezone_set('Europe/Paris');

try {
    $pdo = Database::get();
    $service = new AgendaGenerationService(new CarePlanDao($pdo), new AppointmentDao($pdo), new Transaction($pdo));
    $summary = $service->generate(new DateTimeImmutable('now'));
} catch (Throwable $e) {
    // Message seul (jamais la trace, qui pourrait contenir la configuration) sur la sortie d'erreur.
    fwrite(STDERR, 'Génération de l\'agenda impossible : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$plural = static fn (int $n, string $word): string => $n . ' ' . $word . ($n > 1 ? 's' : '');
printf(
    "%s %s pour %s (%s en cours avec une prescription active, %s examinée%s).\n",
    $plural($summary['created'], 'soin'),
    $summary['created'] > 1 ? 'créés' : 'créé',
    $plural($summary['episodes'], 'séjour'),
    $plural($summary['examinedEpisodes'], 'séjour'),
    $plural($summary['lines'], 'ligne'),
    $summary['lines'] > 1 ? 's' : ''
);
exit(0);
