<?php
use Aura\Core\View;

/**
 * @var int $idSejour
 * @var array<int, array<string, mixed>> $careplans
 * @var array<string, mixed> $bandeau
 */

/**
 * Parcourt tous les careplans, filtre par date et trie par heure.
 */
function getAppointmentsByDate(array $careplans, string $selectedDate): array
{
    $appointments = [];

    foreach ($careplans as $plan) {
        // 1. Extraire les soins (serviceRequests)
        foreach ($plan['serviceRequests'] ?? [] as $service) {
            foreach ($service['appointments'] ?? [] as $app) {
                $plannedTime = $app['plannedTime'] ?? '';
                $appDate = date('Y-m-d', strtotime($plannedTime));

                if ($appDate === $selectedDate) {
                    $appointments[] = [
                        'type'        => 'SOIN',
                        'label'       => $service['idActivityDefinition'] ?? '',
                        'plannedTime' => $plannedTime,
                        'status'      => $app['status'] ?? '',
                        'comment'     => $app['comment'] ?? '',
                    ];
                }
            }
        }

        // 2. Extraire les médicaments (medicationRequest)
        foreach ($plan['medicationRequest'] ?? [] as $med) {
            foreach ($med['appointments'] ?? [] as $app) {
                $plannedTime = $app['plannedTime'] ?? '';
                $appDate = date('Y-m-d', strtotime($plannedTime));

                if ($appDate === $selectedDate) {
                    $appointments[] = [
                        'type'        => 'MEDICAMENT',
                        'label'       => $med['idMedication'] ?? '',
                        'plannedTime' => $plannedTime,
                        'status'      => $app['status'] ?? '',
                        'comment'     => $app['comment'] ?? '',
                    ];
                }
            }
        }
    }

    // 3. Trier par ordre chronologique
    usort($appointments, function (array $a, array $b): int {
        return strtotime($a['plannedTime']) <=> strtotime($b['plannedTime']);
    });

    return $appointments;
}

// Date sélectionnée via l'URL ou date du jour par défaut
$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Récupération globale de tous les soins et médicaments filtrés et triés
$sortedAppointments = getAppointmentsByDate($careplans, $selectedDate);
?>

<section class="agenda">
    <h1 class="form-title">Agenda du séjour n° <?= View::e((string)$idSejour) ?></h1> 
    <!-- <?= View::render('components/bandeau_patient', ['bandeau' => $bandeau]) ?> -->

    <h2>Rendez-vous et soins prévus</h2>

    <!-- Formulaire de sélection de la date -->
    <form method="GET" action="" style="margin-bottom: 20px;">
        <input type="hidden" name="id" value="<?= View::e((string)$idSejour) ?>">
        <label for="date-select"><strong>Choisir une date :</strong></label>
        <input 
            type="date" 
            id="date-select" 
            name="date" 
            value="<?= View::e($selectedDate) ?>" 
            onchange="this.form.submit()"
        >
    </form>

    <!-- Liste des rendez-vous filtrés -->
    <div class="events-list">
        <?php if (empty($sortedAppointments)): ?>
            <p>Aucun soin ou médicament prévu pour le <?= View::e(date('d/m/Y', strtotime($selectedDate))) ?>.</p>
        <?php else: ?>
            <?php foreach ($sortedAppointments as $app): ?>
                <div class="event-card">
                    <p>
                        <strong>[<?= View::e($app['type']) ?>] <?= View::e((string)$app['label']) ?></strong><br>
                        Heure prévue : <?= View::e(date('H:i', strtotime($app['plannedTime']))) ?><br>
                        Statut : <?= View::e($app['status']) ?>
                        <?php if (!empty($app['comment'])): ?>
                            <br><em>Note : <?= View::e($app['comment']) ?></em>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <br>
    <a href="/sejour?id=<?= View::e((string)$idSejour) ?>">← Retour au dossier du séjour</a>
</section>