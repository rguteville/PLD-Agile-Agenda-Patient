<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\Csrf;
use Aura\Core\View;
use Aura\Dao\CareTeamDao;
use Aura\Service\AgendaService;
use Aura\Service\AppointmentService;
use Aura\Service\CareTeamService;
use DateTimeImmutable;

/**
 * Équipes et rotations et agenda par soignant.
 *
 * - GET /equipes?idHealthcareService=…&date=AAAA-MM-JJ&rotation=matin|apres-midi|nuit : équipes de la rotation
 *   (rotation en cours par défaut) et formulaire de composition ;
 * - POST /equipes/creer, POST /equipes/retirer : jeton CSRF, puis retour à la rotation (303) ;
 * - GET /mon-planning?idPractitioner=…&date=…&rotation=… : soins des patients des équipes du
 *   soignant pendant la rotation. Sans identification des praticiens, le soignant se
 *   choisit dans une liste.
 */
final class TeamController
{
    private const SCRIPTS = ['/js/agenda.js', '/js/auto-submit.js'];

    /**
     * @param array<string, string> $services référentiel des services : id => nom
     * @param array<string, string> $practitioners soignants : id => « Prénom Nom (rôle) »
     */
    public function __construct(
        private CareTeamService $careTeamService,
        private CareTeamDao $careTeamDao,
        private AgendaService $agendaService,
        private array $services,
        private array $practitioners,
        private ?AppointmentService $appointmentService = null,
    ) {
    }

    /** GET /equipes : équipes de la rotation demandée (voir context() pour les paramètres) et formulaire. */
    public function teams(): void
    {
        $this->renderTeams($_GET);
    }

    /**
     * POST /equipes/creer : jeton CSRF, puis CareTeamService::create() (nom unique dans la rotation, au moins
     * un soignant, un patient dans une seule équipe par rotation). Refus : 422 et formulaire réaffiché avec
     * la saisie ; succès : 303 vers la rotation. 403 jeton faux, 404 service inconnu.
     */
    public function create(): void
    {
        if (!Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            View::error(403, 'Session expirée : rechargez la page puis réessayez.');
            return;
        }
        [$service, $date, $shift] = $this->context($_POST);
        if ($service === null) {
            View::error(404, 'Service inconnu.');
            return;
        }
        $result = $this->careTeamService->create(
            $service,
            $date,
            $shift,
            $_POST,
            $this->practitioners,
            $this->episodeLabels($this->agendaService->getAgendaService($service))
        );
        if ($result['status'] !== CareTeamService::STATUS_SAVED) {
            http_response_code(422);
            $this->renderTeams($_POST, $result['errors'] ?? [], $_POST);
            return;
        }
        header('Location: ' . self::teamsUrl($service, $date, $shift), true, 303);
    }

    /**
     * POST /equipes/retirer : jeton CSRF, puis suppression logique de l'équipe `idCareTeam` (removedAt) et
     * retour à la rotation (303). 403 jeton faux, 400 service ou identifiant invalide. Une équipe déjà
     * retirée ou inconnue ne lève pas d'erreur : le retour se fait quand même.
     */
    public function remove(): void
    {
        if (!Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            View::error(403, 'Session expirée : rechargez la page puis réessayez.');
            return;
        }
        [$service, $date, $shift] = $this->context($_POST);
        $id = $_POST['idCareTeam'] ?? '';
        if ($service === null || !is_string($id) || preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
            View::error(400, 'Requête incomplète.');
            return;
        }
        $this->careTeamService->remove((int) $id, new DateTimeImmutable('now'));
        header('Location: ' . self::teamsUrl($service, $date, $shift), true, 303);
    }

    /**
     * GET /mon-planning : soins des patients des équipes du soignant `idPractitioner` pendant la rotation
     * (`date`, `rotation`), avec les mêmes actions que l'agenda (valider, non effectué, note). Sans soignant
     * choisi ou avec un identifiant inconnu : la page n'affiche que la liste de choix.
     */
    public function planning(): void
    {
        [, $date, $shift] = $this->context($_GET);
        $idPractitioner = $_GET['idPractitioner'] ?? '';
        $idPractitioner = is_string($idPractitioner) && isset($this->practitioners[$idPractitioner])
            ? $idPractitioner : null;

        $teams = [];
        $planning = null;
        if ($idPractitioner !== null) {
            $teams = $this->careTeamDao->findForPractitioner($idPractitioner, $date, $shift);
            $episodes = [];
            foreach ($teams as $team) {
                $ids = $team->getEpisodeIds();
                foreach ($this->agendaService->getAgendaService($team->getIdHealthcareService()) as $episode) {
                    if (in_array((int) $episode['idEpisodeOfCare'], $ids, true)) {
                        $episodes[] = $episode;
                    }
                }
            }
            [$start, $end] = CareTeamService::window($date, $shift);
            $planning = AgendaService::presentShift($episodes, $start, $end, new DateTimeImmutable('now'));
        }

        $validationEnabled = $this->appointmentService !== null && $idPractitioner !== null;
        View::page('team/planning', [
            'practitioners' => $this->practitioners,
            'idPractitioner' => $idPractitioner,
            'shiftView' => $this->shiftView($date, $shift),
            'teams' => $teams,
            'planning' => $planning,
            'validationEnabled' => $validationEnabled,
            'validators' => $this->appointmentService?->performers() ?? [],
            'csrfToken' => $validationEnabled ? Csrf::token() : null,
        ], 'AURA - Mon planning', self::SCRIPTS);
    }

    /**
     * @param array<string, mixed> $source $_GET ou $_POST
     * @param array<string, string> $errors
     * @param array<string, mixed> $values saisie à réafficher après un refus
     */
    private function renderTeams(array $source, array $errors = [], array $values = []): void
    {
        [$service, $date, $shift] = $this->context($source);
        if ($service === null) {
            View::error(404, 'Service inconnu.');
            return;
        }
        $episodes = $this->agendaService->getAgendaService($service);
        View::page('team/teams', [
            'services' => $this->services,
            'idHealthcareService' => $service,
            'shiftView' => $this->shiftView($date, $shift),
            'teams' => $this->careTeamDao->findForShift($service, $date, $shift),
            'episodes' => $episodes,
            'practitioners' => $this->practitioners,
            'errors' => $errors,
            'values' => $values,
            'csrfToken' => Csrf::token(),
        ], 'AURA - Équipes de la rotation', ['/js/confirm.js', '/js/auto-submit.js']);
    }

    /**
     * Service, date et rotation demandés ; par défaut le premier service du référentiel et la rotation
     * en cours. Paramètres lus : `idHealthcareService`, `date` (AAAA-MM-JJ), `rotation` (matin, apres-midi ou
     * nuit, casse indifférente ; l'ancien paramètre `vacation` est encore lu). Service inconnu : null en
     * première position.
     *
     * @param array<string, mixed> $source
     * @return array{0: ?string, 1: DateTimeImmutable, 2: string}
     */
    private function context(array $source): array
    {
        $service = $source['idHealthcareService'] ?? '';
        if (!is_string($service) || $service === '') {
            $ids = array_map('strval', array_keys($this->services));
            sort($ids);
            $service = $ids[0] ?? null;
        }
        if ($service !== null && !isset($this->services[$service])) {
            $service = null;
        }

        $now = new DateTimeImmutable('now');
        $current = CareTeamService::currentShift($now);
        $shift = CareTeamService::parseShift($source['rotation'] ?? $source['vacation'] ?? null);
        $date = isset($source['date']) && $source['date'] !== ''
            ? AgendaService::parseDay($source['date'], $now) : $current['date'];

        return [$service, $date, $shift ?? $current['shift']];
    }

    /** @return array<string, mixed> rotation affichée et ses voisines, pour la navigation */
    private function shiftView(DateTimeImmutable $date, string $shift): array
    {
        ['previous' => $previous, 'next' => $next] = CareTeamService::neighbours($date, $shift);

        return [
            'date' => $date->format('Y-m-d'),
            'shift' => $shift,
            'label' => sprintf(
                '%s du %s (%s)',
                CareTeamService::SHIFTS[$shift]['label'],
                AgendaService::dayLabel($date),
                CareTeamService::SHIFTS[$shift]['hours']
            ),
            'previous' => ['date' => $previous[0]->format('Y-m-d'), 'shift' => $previous[1]],
            'next' => ['date' => $next[0]->format('Y-m-d'), 'shift' => $next[1]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $episodes
     * @return array<int, string> séjour => « Lit L01, SIMPSON Marge »
     */
    private function episodeLabels(array $episodes): array
    {
        $labels = [];
        foreach ($episodes as $e) {
            $labels[(int) $e['idEpisodeOfCare']] = ($e['bed'] !== null ? 'Lit ' . $e['bed'] . ', ' : '')
                . mb_strtoupper((string) $e['lastName']) . ' ' . $e['firstName'];
        }

        return $labels;
    }

    /** Adresse de la page des équipes pour une rotation (paramètre `rotation` : matin, apres-midi, nuit). */
    private static function teamsUrl(string $service, DateTimeImmutable $date, string $shift): string
    {
        return '/equipes?idHealthcareService=' . rawurlencode($service) . '&date=' . $date->format('Y-m-d')
            . '&rotation=' . CareTeamService::urlValue($shift);
    }
}
