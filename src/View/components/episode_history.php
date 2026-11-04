<?php

use Aura\Core\View;

/**
 * Historique des séjours d'un patient, du plus récent au plus
 * ancien : numéro, statut, dates d'admission et de sortie, service et motif ; chaque séjour mène à son
 * dossier. Le séjour affiché est signalé par le texte « Séjour affiché » et aria-current, pas par la
 * couleur seule. Inclus par pages/patient/list.php (dans un bloc dépliable) et pages/episode/record.php.
 *
 * Usage : View::render('components/episode_history', ['episodes' => …])
 *
 * @var list<array<string, mixed>> $episodes vue-modèle de EpisodeHistoryService::present() (idEpisodeOfCare,
 *      episodeNumber, ongoing, displayed, admissionDate, dischargeDate, service, admissionReason)
 * @var string|null $label nom accessible de la liste (« Séjours de DUPONT Marie »), null par défaut
 */
$label = $label ?? null;
?>
<ol class="episode-history"<?= $label !== null ? ' aria-label="' . View::e($label) . '"' : '' ?>>
    <?php foreach ($episodes as $episode) : ?>
        <li class="episode-history__item<?= $episode['displayed'] ? ' episode-history__item--displayed' : '' ?>">
            <p class="episode-history__head">
                <a href="/sejour?id=<?= View::e($episode['idEpisodeOfCare']) ?>"<?= $episode['displayed']
                    ? ' aria-current="page"' : '' ?>>Séjour n° <?= View::e($episode['episodeNumber']) ?></a>
                <?= View::render('components/status_badge', $episode['ongoing']
                    ? ['status' => 'planned', 'label' => 'En cours']
                    : ['status' => 'stopped', 'label' => 'Clos']) ?>
                <?php if ($episode['displayed']) : ?>
                    <span class="episode-history__displayed">Séjour affiché</span>
                <?php endif; ?>
            </p>
            <p class="episode-history__dates">
                Admission le <?= View::e($episode['admissionDate']) ?>,
                <?= $episode['dischargeDate'] !== null
                    ? 'sortie le ' . View::e($episode['dischargeDate'])
                    : 'pas encore de date de sortie' ?>
            </p>
            <p class="episode-history__meta">
                Service : <?= View::e($episode['service'] ?? 'non renseigné') ?>
                <br>Motif : <?= View::e($episode['admissionReason'] ?? 'non renseigné') ?>
            </p>
        </li>
    <?php endforeach; ?>
</ol>
