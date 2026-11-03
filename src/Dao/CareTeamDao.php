<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\CareTeam;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;

/**
 * Accès aux équipes de vacation (AURA-30) : CareTeam, CareTeamMember, CareTeamPatient. Seules les
 * équipes actives (removedAt NULL) sont lues ; retirer une équipe garde sa ligne (suppression logique).
 */
class CareTeamDao
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Équipes actives d'une vacation, par nom.
     *
     * @return list<CareTeam>
     */
    public function findForShift(string $idHealthcareService, DateTimeInterface $date, string $shift): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT idCareTeam, idHealthcareService, shiftDate, shift, name FROM CareTeam
             WHERE idHealthcareService = :service AND shiftDate = :date AND shift = :shift AND removedAt IS NULL
             ORDER BY name'
        );
        $stmt->execute(['service' => $idHealthcareService, 'date' => $date->format('Y-m-d'), 'shift' => $shift]);

        return $this->withDetails($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Équipes actives d'une vacation dont le soignant fait partie (AURA-31 : « mon planning »).
     *
     * @return list<CareTeam>
     */
    public function findForPractitioner(string $idPractitioner, DateTimeInterface $date, string $shift): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.idCareTeam, t.idHealthcareService, t.shiftDate, t.shift, t.name FROM CareTeam t
             JOIN CareTeamMember m ON m.idCareTeam = t.idCareTeam
             WHERE m.idPractitioner = :practitioner AND t.shiftDate = :date AND t.shift = :shift
               AND t.removedAt IS NULL
             ORDER BY t.name'
        );
        $stmt->execute(['practitioner' => $idPractitioner, 'date' => $date->format('Y-m-d'), 'shift' => $shift]);

        return $this->withDetails($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Crée l'équipe, ses membres et ses patients ; à appeler dans Transaction::run(). */
    public function insert(CareTeam $team): int
    {
        $this->pdo->prepare(
            'INSERT INTO CareTeam (idHealthcareService, shiftDate, shift, name)
             VALUES (:service, :date, :shift, :name)'
        )->execute([
            'service' => $team->getIdHealthcareService(),
            'date' => $team->getShiftDate()->format('Y-m-d'),
            'shift' => $team->getShift(),
            'name' => $team->getName(),
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $member = $this->pdo->prepare('INSERT INTO CareTeamMember (idCareTeam, idPractitioner) VALUES (?, ?)');
        foreach (array_keys($team->getMembers()) as $idPractitioner) {
            $member->execute([$id, (string) $idPractitioner]);
        }
        $patient = $this->pdo->prepare('INSERT INTO CareTeamPatient (idCareTeam, idEpisodeOfCare) VALUES (?, ?)');
        foreach ($team->getEpisodeIds() as $idEpisodeOfCare) {
            $patient->execute([$id, (string) $idEpisodeOfCare]);
        }

        return $id;
    }

    /** Retire une équipe active (suppression logique) ; false si elle n'existe pas ou est déjà retirée. */
    public function remove(int $idCareTeam, DateTimeInterface $removedAt): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE CareTeam SET removedAt = :removedAt WHERE idCareTeam = :id AND removedAt IS NULL'
        );
        $stmt->execute(['removedAt' => $removedAt->format('Y-m-d H:i:s'), 'id' => $idCareTeam]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Membres (« Prénom Nom ») et séjours de chaque équipe lue.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<CareTeam>
     */
    private function withDetails(array $rows): array
    {
        $members = $this->pdo->prepare(
            'SELECT p.idPractitioner, p.firstName, p.lastName FROM CareTeamMember m
             JOIN Practitioner p ON p.idPractitioner = m.idPractitioner
             WHERE m.idCareTeam = ? ORDER BY p.lastName, p.firstName'
        );
        $patients = $this->pdo->prepare(
            'SELECT idEpisodeOfCare FROM CareTeamPatient WHERE idCareTeam = ?
             ORDER BY CAST(idEpisodeOfCare AS UNSIGNED)'
        );

        $teams = [];
        foreach ($rows as $r) {
            $id = (int) $r['idCareTeam'];
            $members->execute([$id]);
            $names = [];
            foreach ($members->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $names[(string) $m['idPractitioner']] = trim($m['firstName'] . ' ' . $m['lastName']);
            }
            $patients->execute([$id]);
            $teams[] = new CareTeam(
                $id,
                (string) $r['idHealthcareService'],
                new DateTimeImmutable((string) $r['shiftDate']),
                (string) $r['shift'],
                (string) $r['name'],
                $names,
                array_map('intval', $patients->fetchAll(PDO::FETCH_COLUMN))
            );
        }

        return $teams;
    }
}
