<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\AuditEvent;
use DateTimeImmutable;
use PDO;

/**
 * Accès à l'historique des modifications du dossier (table AuditEvent). Journal en ajout seul :
 * aucune mise à jour ni suppression.
 */
class AuditEventDao
{
    private const COLUMNS = 'idAuditEvent, entity, entityId, field, oldValue, newValue, author, detail, recordedAt';

    public function __construct(private PDO $pdo)
    {
    }

    /** Enregistre une modification et renvoie son identifiant (AUTO_INCREMENT). */
    public function insert(AuditEvent $e): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO AuditEvent (entity, entityId, field, oldValue, newValue, author, detail)
             VALUES (:entity, :entityId, :field, :oldValue, :newValue, :author, :detail)'
        );
        $stmt->execute([
            'entity' => $e->getEntity(),
            'entityId' => (string) $e->getEntityId(),
            'field' => $e->getField(),
            'oldValue' => $e->getOldValue(),
            'newValue' => $e->getNewValue(),
            'author' => $e->getAuthor(),
            'detail' => $e->getDetail(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Dernières modifications d'un dossier (patient + séjour), de la plus récente à la plus ancienne.
     *
     * @return AuditEvent[]
     */
    public function findForRecord(int $idPatient, int $idEpisode, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM AuditEvent
             WHERE (entity = :patientEntity AND entityId = :idPatient)
                OR (entity = :episodeEntity AND entityId = :idEpisode)
             ORDER BY recordedAt DESC, idAuditEvent DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute([
            'patientEntity' => AuditEvent::ENTITY_PATIENT,
            'idPatient' => (string) $idPatient,
            'episodeEntity' => AuditEvent::ENTITY_EPISODE,
            'idEpisode' => (string) $idEpisode,
        ]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapToEntity(array $r): AuditEvent
    {
        return new AuditEvent(
            idAuditEvent: (int) $r['idAuditEvent'],
            entity: (string) $r['entity'],
            entityId: (int) $r['entityId'],
            field: (string) $r['field'],
            oldValue: $r['oldValue'],
            newValue: $r['newValue'],
            author: (string) $r['author'],
            recordedAt: new DateTimeImmutable($r['recordedAt']),
            detail: $r['detail']
        );
    }
}
