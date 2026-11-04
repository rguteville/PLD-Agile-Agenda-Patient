<?php

declare(strict_types=1);

namespace Aura\Controller;

use Aura\Core\Csrf;
use Aura\Service\RecordEditService;
use Aura\Service\RecordSectionService;
use LogicException;

/**
 * Écritures du dossier de séjour, toutes en JSON avec jeton CSRF (en-tête X-CSRF-Token ou champ csrf) :
 *  - POST /sejour/champ/modifier (update()) : un champ, édition en ligne (public/js/inline-edit.js) ;
 *  - POST /sejour/partie/modifier (updatePart()) : une partie entière du dossier ;
 *  - POST /sejour/cloturer (endStay()) : fin du séjour ;
 *  - POST /sejour/deces (declareDeath()) : décès, qui termine aussi le séjour.
 * Les trois dernières passent par la fenêtre de validation par un médecin (public/js/part-edit.js),
 * authentification FACTICE en attendant l'authentification des praticiens (AURA-50).
 *
 * Champ par champ : corps JSON {entity, id, episodeOfCare, field, value, oldValue} ou formulaire classique
 * avec les mêmes noms. `episodeOfCare` est le séjour affiché : un patient n'est modifiable que depuis le
 * dossier d'un de ses séjours. Les champs réservés au médecin (groupe sanguin, décès) y sont refusés.
 * Réponses JSON : 200 {ok:true, saved, conflict, value, display, message} ; 400 corps illisible ;
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

    /** Codes HTTP des réponses d'une partie du dossier ou de la fin de séjour. */
    private const PART_HTTP_STATUS = [
        RecordSectionService::STATUS_SAVED => 200,
        RecordSectionService::STATUS_INVALID => 422,
        RecordSectionService::STATUS_AUTH => 422,
        RecordSectionService::STATUS_CONFLICT => 409,
        RecordSectionService::STATUS_NOT_FOUND => 404,
    ];

    public function __construct(
        private RecordEditService $recordEditService,
        private ?RecordSectionService $recordSectionService = null
    ) {
    }

    /**
     * POST /sejour/partie/modifier (retours du 2026-11-02) : toute une partie du dossier d'un coup.
     * Corps JSON {episodeOfCare, part, values: {champ: valeur}, oldValues: {champ: valeur}, validator,
     * password}, en-tête X-CSRF-Token. Réponses : 200 {ok, redirect} vers l'ancre de la partie ;
     * 422 {ok:false, conflict, message, errors} (erreur d'un champ ou clé « auth ») ; 409 conflit ;
     * 404 dossier ou partie inconnue ; 403 jeton ; 400 requête incomplète.
     */
    public function updatePart(): void
    {
        $body = $this->readBody();
        if (!$this->checkToken($body)) {
            return;
        }
        $idEpisodeOfCare = $body['episodeOfCare'] ?? null;
        $part = $body['part'] ?? null;
        $values = $body['values'] ?? null;
        $oldValues = $body['oldValues'] ?? null;
        if (
            !is_string($part) || !is_array($values) || !is_array($oldValues)
            || array_filter(
                [...array_values($values), ...array_values($oldValues)],
                static fn (mixed $v): bool => !self::isPlainValue($v)
            ) !== []
            || !is_string($idEpisodeOfCare) && !is_int($idEpisodeOfCare)
            || preg_match(self::ID_PATTERN, (string) $idEpisodeOfCare) !== 1
        ) {
            $this->respond(400, ['ok' => false, 'message' => 'Requête incomplète.']);
            return;
        }

        $result = $this->sectionService()->updatePart(
            (int) $idEpisodeOfCare,
            $part,
            $values,
            $oldValues,
            self::text($body['validator'] ?? null),
            self::text($body['password'] ?? null)
        );
        $this->respondPart($result, (int) $idEpisodeOfCare, '#dossier-' . preg_replace('/[^a-z]/', '', $part));
    }

    /**
     * POST /sejour/cloturer : fin du séjour. Corps JSON {episodeOfCare, date (JJ/MM/AAAA), time (HH:MM),
     * validator, password}, en-tête X-CSRF-Token. Mêmes réponses que updatePart().
     */
    public function endStay(): void
    {
        $body = $this->readBody();
        if (!$this->checkToken($body)) {
            return;
        }
        $idEpisodeOfCare = $body['episodeOfCare'] ?? null;
        if (
            !is_string($idEpisodeOfCare) && !is_int($idEpisodeOfCare)
            || preg_match(self::ID_PATTERN, (string) $idEpisodeOfCare) !== 1
        ) {
            $this->respond(400, ['ok' => false, 'message' => 'Requête incomplète.']);
            return;
        }

        $result = $this->sectionService()->endStay(
            (int) $idEpisodeOfCare,
            self::text($body['date'] ?? null),
            self::text($body['time'] ?? null),
            self::text($body['validator'] ?? null),
            self::text($body['password'] ?? null)
        );
        $this->respondPart($result, (int) $idEpisodeOfCare, '#dossier-resume');
    }

    /**
     * POST /sejour/deces (retours du 2026-11-04) : déclaration du décès, qui met fin au séjour en cours à
     * l'heure du décès. Corps JSON {episodeOfCare, date (JJ/MM/AAAA), time (HH:MM), oldDeceased, validator,
     * password}, en-tête X-CSRF-Token. Mêmes réponses que updatePart().
     */
    public function declareDeath(): void
    {
        $body = $this->readBody();
        if (!$this->checkToken($body)) {
            return;
        }
        $idEpisodeOfCare = $body['episodeOfCare'] ?? null;
        if (
            !is_string($idEpisodeOfCare) && !is_int($idEpisodeOfCare)
            || preg_match(self::ID_PATTERN, (string) $idEpisodeOfCare) !== 1
        ) {
            $this->respond(400, ['ok' => false, 'message' => 'Requête incomplète.']);
            return;
        }

        $result = $this->sectionService()->declareDeath(
            (int) $idEpisodeOfCare,
            self::text($body['date'] ?? null),
            self::text($body['time'] ?? null),
            self::text($body['oldDeceased'] ?? null),
            self::text($body['validator'] ?? null),
            self::text($body['password'] ?? null)
        );
        $this->respondPart($result, (int) $idEpisodeOfCare, '#dossier-resume');
    }

    /**
     * Réponse commune aux parties, à la fin de séjour et au décès : 200 {ok, redirect} vers le dossier à
     * l'ancre donnée, sinon le code de PART_HTTP_STATUS avec {ok:false, conflict, message, errors}.
     *
     * @param array{status: string, errors: array<string, string>, message: ?string} $result
     */
    private function respondPart(array $result, int $idEpisodeOfCare, string $anchor): void
    {
        $status = self::PART_HTTP_STATUS[$result['status']] ?? 500;
        if ($status === 200) {
            $this->respond(200, ['ok' => true, 'redirect' => '/sejour?id=' . $idEpisodeOfCare . $anchor]);
            return;
        }
        $this->respond($status, [
            'ok' => false,
            'conflict' => $result['status'] === RecordSectionService::STATUS_CONFLICT,
            'message' => $result['message'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Vérifie le jeton CSRF (en-tête X-CSRF-Token, sinon champ csrf) ; répond 403 et renvoie false s'il est faux.
     *
     * @param array<string, mixed> $body
     */
    private function checkToken(array $body): bool
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $body['csrf'] ?? null;
        if (Csrf::check(is_string($token) ? $token : null)) {
            return true;
        }
        $this->respond(403, ['ok' => false, 'message' => 'Session expirée : rechargez la page puis réessayez.']);

        return false;
    }

    /** @throws LogicException si la route n'a pas injecté de RecordSectionService */
    private function sectionService(): RecordSectionService
    {
        if ($this->recordSectionService === null) {
            throw new LogicException('RecordController : les parties du dossier requièrent un RecordSectionService.');
        }

        return $this->recordSectionService;
    }

    /** Valeur texte du corps, chaîne vide si absente ou d'un autre type. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * POST /sejour/champ/modifier : un champ du dossier (voir l'en-tête de classe pour le contrat JSON et
     * les codes HTTP).
     */
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
        $idEpisodeOfCare = $body['episodeOfCare'] ?? null;
        $value = $body['value'] ?? null;
        $oldValue = $body['oldValue'] ?? null;
        if (
            !is_string($entity) || !is_string($field) || $field === ''
            || !is_string($id) && !is_int($id)
            || preg_match(self::ID_PATTERN, (string) $id) !== 1
            || !is_string($idEpisodeOfCare) && !is_int($idEpisodeOfCare)
            || preg_match(self::ID_PATTERN, (string) $idEpisodeOfCare) !== 1
            // Une valeur qui n'est ni texte ni nombre (tableau, objet, booléen) n'est jamais interprétée comme un
            // vidage du champ (relecture adversariale du 2026-11-04 : true effaçait la valeur).
            || !self::isPlainValue($value)
            || !self::isPlainValue($oldValue)
        ) {
            $this->respond(400, ['ok' => false, 'message' => 'Requête incomplète.']);
            return;
        }

        // Le décès se déclare par son bouton : il termine le séjour et annule les soins (POST /sejour/deces).
        if ($field === 'deceased') {
            $this->respond(422, [
                'ok' => false,
                'message' => 'Le décès se déclare avec le bouton « Déclarer le décès ».',
            ]);
            return;
        }
        // Retours du 2026-11-04 : authentification FACTICE, comme « Modifier » (médecin + mot de passe non vérifié).
        $author = $this->sectionService()->inlineAuthor(
            self::text($body['validator'] ?? null),
            self::text($body['password'] ?? null)
        );
        if ($author === null) {
            $this->respond(422, [
                'ok' => false,
                'auth' => true,
                'message' => 'Choisissez le médecin qui valide et saisissez son mot de passe.',
            ]);
            return;
        }

        $result = $this->recordEditService->update(
            $entity,
            (int) $id,
            $field,
            $value,
            $oldValue,
            $author,
            (int) $idEpisodeOfCare
        );

        $status = self::HTTP_STATUS[$result['status']] ?? 500;
        $this->respond($status, [
            'ok' => $status === 200,
            'saved' => $result['status'] === RecordEditService::STATUS_SAVED,
            'conflict' => $result['status'] === RecordEditService::STATUS_CONFLICT,
            'message' => $result['message'],
            'value' => $result['value'],
            'display' => $result['display'],
            'author' => $author,
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

    /** Valeur d'un champ envoyée par le client : texte, nombre ou null (ni tableau, ni objet, ni booléen). */
    private static function isPlainValue(mixed $value): bool
    {
        return $value === null || is_string($value) || is_int($value) || is_float($value);
    }
}
