<?php
use Aura\Core\View;

/**
 * @var int $idSejour
 * @var array<int, array<string, mixed>> $careplans
 */
?>

<section class="agenda">
    <h1 class="form-title">Agenda du séjour n° <?= View::e((string)$idSejour) ?></h1>

    <h2>Rendez-vous et soins prévus</h2>

    <?php foreach ($careplans as $plan): ?>
        
        <!-- 1. Parcours des demandes de soins (serviceRequests) -->
        <?php foreach ($plan['serviceRequests'] ?? [] as $service): ?>
            <?php foreach ($service['appointments'] ?? [] as $app): ?>
                <div class="event-card">
                    <p>
                        <strong>[SOIN] <?= View::e((string)$service['idActivityDefinition']) ?></strong><br>
                        Heure prévue : <?= View::e($app['plannedTime']) ?><br>
                        Statut : <?= View::e($app['status']) ?>
                        <?php if (!empty($app['comment'])): ?>
                            <br><em>Note : <?= View::e($app['comment']) ?></em>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <!-- 2. Parcours des demandes de médicaments (medicationRequest) -->
        <?php foreach ($plan['medicationRequest'] ?? [] as $med): ?>
            <?php foreach ($med['appointments'] ?? [] as $app): ?>
                <div class="event-card">
                    <p>
                        <strong>[MÉDICAMENT] <?= View::e((string)$med['idMedication']) ?> (<?= View::e((string)$med['dose']) ?>)</strong><br>
                        Heure prévue : <?= View::e($app['plannedTime']) ?><br>
                        Statut : <?= View::e($app['status']) ?>
                        <?php if (!empty($app['comment'])): ?>
                            <br><em>Note : <?= View::e($app['comment']) ?></em>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

    <?php endforeach; ?>

    <br>
    <a href="/sejour?idSejour=<?= View::e((string)$idSejour) ?>">← Retour au dossier du séjour</a>
</section>