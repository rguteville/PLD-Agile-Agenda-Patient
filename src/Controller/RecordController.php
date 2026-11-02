<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\Csrf;
use Aura\Service\RecordEditService;

/**
 * POST /sejour/champ/modifier : modification d'un champ du dossier, sans rechargement de page
 * (public/js/inline-edit.js). Corps JSON {entity, id, field, value, oldValue} ou formulaire
 * classique avec les mêmes noms ; en-tête X-CSRF-Token (ou champ csrf) obligatoire.
 *
 * Réponses JSON : 200 {ok:true, saved, value, display, message} ; 400 corps illisible ;
 * 403 jeton absent ou faux ; 404 dossier inconnu ; 409 conflit de concurrence (valeur actuelle
 * renvoyée) ; 422 champ refusé ou valeur invalide (message sous le champ).
 */
final class RecordController
{
    private const ID_PATTERN = '/^[1-9][0-9]{0,17}$/';

    private const HTTP_STATUS = [
        RecordEditService::STATUS_SAVED => 200,
        RecordEditService::STATUS_UNCHANGED => 200,
        RecordEditService::STATUS_NOT_FOUND => 404,
        RecordEditService::STATUS_FORBIDDEN_FIELD => 422,
        RecordEditService::STATUS_INVALID => 422,
        RecordEditService::STATUS_CONFLICT => 409,
    ];

    public function __construct(private RecordEditService $recordEditService)
    {
    }

    public function update(): void
    {
        $body = $this->readBody();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $body['csrf'] ?? null;
        if (!Csrf::check(is_string($token) ? $token : null)) {
            $this->respond(403, ['ok' => false, 'message' => 'Session expirée : rechargez la page puis réessayez.']);
            return;
        }

        $entity = $body['entity'] ?? null;
        $id = $body['id'] ?? null;
        $field = $body['field'] ?? null;
        if (
            !is_string($entity) || !is_string($field) || $field === ''
            || !is_string($id) && !is_int($id)
            || preg_match(self::ID_PATTERN, (string) $id) !== 1
        ) {
            $this->respond(400, ['ok' => false, 'message' => 'Requête incomplète.']);
            return;
        }

        $result = $this->recordEditService->update(
            $entity,
            (int) $id,
            $field,
            $body['value'] ?? null,
            $body['oldValue'] ?? null
        );

        $status = self::HTTP_STATUS[$result['status']] ?? 500;
        $this->respond($status, [
            'ok' => $status === 200,
            'saved' => $result['status'] === RecordEditService::STATUS_SAVED,
            'conflict' => $result['status'] === RecordEditService::STATUS_CONFLICT,
            'message' => $result['message'],
            'value' => $result['value'],
            'display' => $result['display'],
        ]);
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

    /** @param array<string, mixed> $body */
    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
