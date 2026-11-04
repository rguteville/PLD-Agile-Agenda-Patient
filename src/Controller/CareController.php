<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\Csrf;
use Aura\Service\AppointmentService;
use DateTimeImmutable;

/**
 * POST /agenda-patient/soin (AURA-26, AURA-28) : valider un soin, le marquer non effectué ou y
 * ajouter une note, depuis l'agenda du patient ou celui du service.
 *
 * Corps (JSON envoyé par public/js/agenda.js, ou formulaire classique) : episodeOfCare, appointment,
 * action (done | not_done | note), idPerformer, password, completedAt, comment ; jeton CSRF en
 * en-tête X-CSRF-Token ou champ csrf. Réponses JSON : 200 {ok, message}, 400 requête incomplète,
 * 403 jeton, 404 soin hors du séjour, 409 déjà validé, 422 {ok:false, errors} ; sans JSON, retour
 * à la page d'origine (303).
 */
final class CareController
{
    private const HTTP_STATUS = [
        AppointmentService::STATUS_SAVED => 200,
        AppointmentService::STATUS_INVALID => 422,
        AppointmentService::STATUS_AUTH => 422,
        AppointmentService::STATUS_NOT_FOUND => 404,
        AppointmentService::STATUS_CONFLICT => 409,
    ];

    /** Identifiants numériques (séjour) et texte (soin, « SOI01 ») tels que stockés. */
    private const EPISODE_PATTERN = '/^[1-9]\d{0,9}$/';
    private const APPOINTMENT_PATTERN = '/^[A-Za-z0-9_-]{1,20}$/';

    public function __construct(private AppointmentService $appointmentService)
    {
    }

    /**
     * Traite la requête : jeton CSRF, identifiants du séjour et du soin, puis AppointmentService::apply()
     * (règles : soignant habilité, authentification factice, rotation en cours, une seule validation).
     * Répond en JSON si l'en-tête Accept le demande, sinon redirige (303) vers `back` quand c'est une
     * page d'agenda connue (agenda du patient, du service, « Mon planning »), à défaut vers l'agenda du séjour.
     */
    public function act(): void
    {
        $body = $this->readBody();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $body['csrf'] ?? null;
        if (!Csrf::check(is_string($token) ? $token : null)) {
            $this->respond(403, ['ok' => false, 'message' => 'Session expirée : rechargez la page puis réessayez.']);
            return;
        }

        $idEpisodeOfCare = $body['episodeOfCare'] ?? null;
        $idAppointment = $body['appointment'] ?? null;
        if (
            !is_string($idEpisodeOfCare) && !is_int($idEpisodeOfCare)
            || preg_match(self::EPISODE_PATTERN, (string) $idEpisodeOfCare) !== 1
            || !is_string($idAppointment) || preg_match(self::APPOINTMENT_PATTERN, $idAppointment) !== 1
        ) {
            $this->respond(400, ['ok' => false, 'message' => 'Requête incomplète.']);
            return;
        }

        $result = $this->appointmentService->apply(
            (int) $idEpisodeOfCare,
            $idAppointment,
            $body,
            new DateTimeImmutable('now')
        );
        $status = self::HTTP_STATUS[$result['status']] ?? 500;
        $payload = ['ok' => $status === 200, 'message' => $result['message'] ?? null];
        if (isset($result['errors'])) {
            $payload['errors'] = $result['errors'];
        }

        if (!$this->expectsJson()) {
            // Sans JavaScript : retour à la page d'origine, le résultat se lit dans l'agenda rechargé.
            $back = $body['back'] ?? '';
            $origin = '#^/(agenda-patient|agenda-service|mon-planning)\?[\w=&%-]*$#';
            $back = is_string($back) && preg_match($origin, $back) === 1
                ? $back : '/agenda-patient?id=' . (int) $idEpisodeOfCare;
            header('Location: ' . $back, true, 303);
            return;
        }
        $this->respond($status, $payload);
    }

    /**
     * Corps de la requête : JSON si l'en-tête Content-Type l'annonce, sinon $_POST.
     *
     * @return array<string, mixed>
     */
    private function readBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /** Vrai si le client attend du JSON (agenda.js envoie `Accept: application/json`). */
    private function expectsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /** @param array<string, mixed> $body */
    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
