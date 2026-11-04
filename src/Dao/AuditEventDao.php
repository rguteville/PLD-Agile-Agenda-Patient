<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\AuditEvent;
use DateTimeImmutable;
use PDO;

/**
 * Journal de traçabilité (RG10) : table AuditEvent, ressource HL7 du même nom. Il trace les
 * modifications du dossier (Patient, EpisodeOfCare), les validations et notes des soins
 * (Appointment) et les prescriptions confirmées malgré une allergie (CarePlan). Journal en ajout
 * seul : aucune mise à jour ni suppression. Seule table à id AUTO_INCREMENT avec CareTeam.
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
     * Les événements des soins et des ordonnances du séjour n'en font pas partie.
     *
     * @param int $limit nombre maximal de lignes (au moins 1)
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

    /**
     * Événements tracés sur un objet (ex. ordonnance : AuditEvent::ENTITY_CARE_PLAN et son idCarePlan),
     * du plus ancien au plus récent.
     *
     * @return AuditEvent[]
     */
    public function findByEntity(string $entity, string $entityId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM AuditEvent
             WHERE entity = :entity AND entityId = :entityId
             ORDER BY recordedAt, idAuditEvent'
        );
        $stmt->execute(['entity' => $entity, 'entityId' => $entityId]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Dernier auteur d'un changement de statut, par soin planifié (AURA-26 : « réalisé par … »).
     *
     * @param list<string> $idAppointments
     * @return array<string, string> idAppointment => auteur
     */
    public function appointmentAuthors(array $idAppointments): array
    {
        if ($idAppointments === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($idAppointments), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT entityId, author FROM AuditEvent
             WHERE entity = ? AND field = ? AND entityId IN (' . $placeholders . ')
             ORDER BY recordedAt, idAuditEvent'
        );
        $stmt->execute([AuditEvent::ENTITY_APPOINTMENT, 'status', ...array_values($idAppointments)]);

        $authors = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // Lignes triées de la plus ancienne à la plus récente : la dernière l'emporte.
            $authors[(string) $r['entityId']] = (string) $r['author'];
        }

        return $authors;
    }

    /**
     * Auteur de la note actuelle de chaque soin (AURA-28 : note « signée par son auteur ») : dernière
     * écriture de la note, seule (champ comment) ou avec une validation (statut avec détail).
     *
     * @param list<string> $idAppointments
     * @return array<string, string> idAppointment => auteur
     */
    public function appointmentNoteAuthors(array $idAppointments): array
    {
        if ($idAppointments === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($idAppointments), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT entityId, author FROM AuditEvent
             WHERE entity = ? AND entityId IN (' . $placeholders . ')
               AND (field = ? OR (field = ? AND detail IS NOT NULL))
             ORDER BY recordedAt, idAuditEvent'
        );
        $stmt->execute([AuditEvent::ENTITY_APPOINTMENT, ...array_values($idAppointments), 'comment', 'status']);

        $authors = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $authors[(string) $r['entityId']] = (string) $r['author'];
        }

        return $authors;
    }

    /**
     * Ligne SQL vers l'entité. entityId est un VARCHAR : il redevient un entier s'il n'a que des
     * chiffres (id de patient, de séjour), il reste une chaîne sinon (soin « SOI01 » des données de démo).
     *
     * @param array<string, ?string> $r
     */
    private function mapToEntity(array $r): AuditEvent
    {
        return new AuditEvent(
            idAuditEvent: (int) $r['idAuditEvent'],
            entity: (string) $r['entity'],
            entityId: ctype_digit((string) $r['entityId']) ? (int) $r['entityId'] : (string) $r['entityId'],
            field: (string) $r['field'],
            oldValue: $r['oldValue'],
            newValue: $r['newValue'],
            author: (string) $r['author'],
            recordedAt: new DateTimeImmutable($r['recordedAt']),
            detail: $r['detail']
        );
    }
}
