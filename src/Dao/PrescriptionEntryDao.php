<?php

declare(strict_types=1);

namespace Aura\Dao;

use DateTimeInterface;
use PDO;

class PrescriptionEntryDao
{
    /** Préfixe des ActivityDefinition qui sont des catégories de prescription (figure 8). */
    public const CATEGORY_PREFIX = 'CAT';

    /** « Administration médicamenteuse » : type des lignes de médicament, pas proposé comme soin. */
    public const MEDICATION_ADMINISTRATION = 'TYP01';

    /** Natures possibles d'une catégorie (colonne description). */
    public const CATEGORY_KINDS = ['GROUP', 'CARE', 'MEDICATION'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{id: string, ongoing: bool}|null */
    public function findEpisode(string $episodeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT idEpisodeOfCare, dischargeDate FROM EpisodeOfCare WHERE idEpisodeOfCare = :id'
        );
        $stmt->execute(['id' => $episodeId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? ['id' => (string) $r['idEpisodeOfCare'], 'ongoing' => $r['dischargeDate'] === null] : null;
    }

    /** @return list<array{id: string, label: string, kind: string}> catégories, dans l'ordre du sujet */
    public function findCategories(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT idActivityDefinition, name, description FROM ActivityDefinition
             WHERE idActivityDefinition LIKE :prefix
             ORDER BY idActivityDefinition'
        );
        $stmt->execute(['prefix' => self::CATEGORY_PREFIX . '%']);

        $categories = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (in_array($r['description'], self::CATEGORY_KINDS, true)) {
                $categories[] = [
                    'id' => (string) $r['idActivityDefinition'],
                    'label' => (string) $r['name'],
                    'kind' => (string) $r['description'],
                ];
            }
        }

        return $categories;
    }

    /** @return array{id: string, label: string, kind: string}|null */
    public function findCategory(string $categoryId): ?array
    {
        foreach ($this->findCategories() as $category) {
            if ($category['id'] === $categoryId) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Types de soin proposés à la saisie : ActivityDefinition qui ne sont ni des catégories
     * ni l'administration médicamenteuse.
     *
     * @return list<array{id: string, name: string}>
     */
    public function findCareTypes(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT idActivityDefinition, name FROM ActivityDefinition
             WHERE idActivityDefinition NOT LIKE :prefix AND idActivityDefinition <> :administration
             ORDER BY name'
        );
        $stmt->execute(['prefix' => self::CATEGORY_PREFIX . '%', 'administration' => self::MEDICATION_ADMINISTRATION]);

        return array_map(static fn(array $r): array => [
            'id' => (string) $r['idActivityDefinition'],
            'name' => (string) $r['name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{id: string, inn: string, form: string}> médicaments, par DCI puis forme */
    public function findMedications(): array
    {
        $rows = $this->pdo->query(
            'SELECT idMedication, inn, form FROM Medication ORDER BY inn, form'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $r): array => [
            'id' => (string) $r['idMedication'],
            'inn' => (string) $r['inn'],
            'form' => (string) $r['form'],
        ], $rows);
    }

    public function medicationExists(string $medicationId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM Medication WHERE idMedication = :id');
        $stmt->execute(['id' => $medicationId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array{id: string, name: string, role: string, canValidate: bool}> praticiens, par nom */
    public function findPrescribers(): array
    {
        $rows = $this->pdo->query(
            'SELECT p.idPractitioner, p.firstName, p.lastName, r.description, r.canValidate
             FROM Practitioner p
             LEFT JOIN PractitionerRole r ON r.idPractitionerRole = p.idPractitionerRole
             ORDER BY p.lastName, p.firstName'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $r): array => [
            'id' => (string) $r['idPractitioner'],
            'name' => trim($r['firstName'] . ' ' . $r['lastName']),
            'role' => (string) $r['description'],
            'canValidate' => (bool) $r['canValidate'],
        ], $rows);
    }

    /**
     * Enregistre une ordonnance et ses lignes, en une seule transaction.
     * Les identifiants (VARCHAR sans AUTO_INCREMENT) sont calculés ici : plus grand + 1.
     *
     * @param array{episodeId: string, prescriberId: string, mode: string,
     *     validatorId: ?string, validatedAt: ?DateTimeInterface} $carePlan
     * @param list<array{careTypeId: string, start: DateTimeInterface, end: ?DateTimeInterface,
     *     frequencyHours: ?int, timeConstraint: ?string}> $careLines
     * @param list<array{careTypeId: string, medicationId: string, dose: string, route: string,
     *     start: DateTimeInterface, end: ?DateTimeInterface, frequencyHours: ?int,
     *     timeConstraint: ?string}> $medicationLines
     * @return string identifiant de l'ordonnance (idCarePlan)
     */
    public function insertCarePlan(array $carePlan, array $careLines, array $medicationLines): string
    {
        return (new Transaction($this->pdo))->run(function () use ($carePlan, $careLines, $medicationLines): string {
            $carePlanId = (string) $this->nextId('CarePlan', 'idCarePlan');
            $this->pdo->prepare(
                'INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idValidator, validatedAt)
                 VALUES (:id, :episodeId, :prescriberId, :mode, :validatorId, :validatedAt)'
            )->execute([
                'id' => $carePlanId,
                'episodeId' => $carePlan['episodeId'],
                'prescriberId' => $carePlan['prescriberId'],
                'mode' => $carePlan['mode'],
                'validatorId' => $carePlan['validatorId'],
                'validatedAt' => self::sqlDate($carePlan['validatedAt']),
            ]);

            $careStatement = $this->pdo->prepare(
                'INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition,
                     startDate, endDate, frequencyHours, timeConstraint)
                 VALUES (:id, :carePlanId, :careTypeId, :start, :end, :frequencyHours, :timeConstraint)'
            );
            foreach ($careLines as $line) {
                $careStatement->execute([
                    'id' => (string) $this->nextId('ServiceRequest', 'idServiceRequest'),
                    'carePlanId' => $carePlanId,
                    'careTypeId' => $line['careTypeId'],
                    'start' => self::sqlDate($line['start']),
                    'end' => self::sqlDate($line['end']),
                    'frequencyHours' => $line['frequencyHours'],
                    'timeConstraint' => $line['timeConstraint'],
                ]);
            }

            $medicationStatement = $this->pdo->prepare(
                'INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition,
                     idMedication, dose, route, startDate, endDate, frequencyHours, timeConstraint)
                 VALUES (:id, :carePlanId, :careTypeId, :medicationId, :dose, :route, :start, :end,
                     :frequencyHours, :timeConstraint)'
            );
            foreach ($medicationLines as $line) {
                $medicationStatement->execute([
                    'id' => (string) $this->nextId('MedicationRequest', 'idMedicationRequest'),
                    'carePlanId' => $carePlanId,
                    'careTypeId' => $line['careTypeId'],
                    'medicationId' => $line['medicationId'],
                    'dose' => $line['dose'],
                    'route' => $line['route'],
                    'start' => self::sqlDate($line['start']),
                    'end' => self::sqlDate($line['end']),
                    'frequencyHours' => $line['frequencyHours'],
                    'timeConstraint' => $line['timeConstraint'],
                ]);
            }

            return $carePlanId;
        });
    }

    /** @return array{id: string, episodeId: string, validated: bool}|null */
    public function findCarePlan(string $carePlanId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT idCarePlan, idEpisodeOfCare, idValidator FROM CarePlan WHERE idCarePlan = :id'
        );
        $stmt->execute(['id' => $carePlanId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? [
            'id' => (string) $r['idCarePlan'],
            'episodeId' => (string) $r['idEpisodeOfCare'],
            'validated' => $r['idValidator'] !== null,
        ] : null;
    }

    /**
     * Ordonnances du séjour en attente de validation par un médecin (RG5), de la plus
     * ancienne à la plus récente, avec le résumé de leurs lignes.
     *
     * @return list<array{id: string, mode: string, recordedAt: string, prescriber: string,
     *     lines: list<string>}>
     */
    public function findPendingCarePlans(string $episodeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.idCarePlan, c.mode, c.recordedAt, p.firstName, p.lastName
             FROM CarePlan c
             JOIN Practitioner p ON p.idPractitioner = c.idPrescriber
             WHERE c.idEpisodeOfCare = :episodeId AND c.idValidator IS NULL
             ORDER BY c.recordedAt, CAST(c.idCarePlan AS UNSIGNED)'
        );
        $stmt->execute(['episodeId' => $episodeId]);

        $carePlans = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $carePlans[] = [
                'id' => (string) $r['idCarePlan'],
                'mode' => (string) $r['mode'],
                'recordedAt' => (string) $r['recordedAt'],
                'prescriber' => trim($r['firstName'] . ' ' . $r['lastName']),
                'lines' => $this->lineSummaries((string) $r['idCarePlan']),
            ];
        }

        return $carePlans;
    }

    /**
     * Valide une ordonnance encore non validée (RG5, RG10 : qui et quand).
     *
     * @return bool false si l'ordonnance n'existe pas ou était déjà validée
     */
    public function validateCarePlan(string $carePlanId, string $validatorId, DateTimeInterface $validatedAt): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE CarePlan SET idValidator = :validatorId, validatedAt = :validatedAt
             WHERE idCarePlan = :id AND idValidator IS NULL'
        );
        $stmt->execute([
            'validatorId' => $validatorId,
            'validatedAt' => self::sqlDate($validatedAt),
            'id' => $carePlanId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Résumé lisible des lignes d'une ordonnance : « [IV] Paracétamol (Solution injectable) 1 g »,
     * « Prise des constantes ».
     *
     * @return list<string>
     */
    private function lineSummaries(string $carePlanId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT CONCAT(\'[\', mr.route, \'] \', m.inn, \' (\', m.form, \') \', mr.dose) AS summary
             FROM MedicationRequest mr
             JOIN Medication m ON m.idMedication = mr.idMedication
             WHERE mr.idCarePlan = :medicationPlan
             UNION ALL
             SELECT a.name
             FROM ServiceRequest sr
             JOIN ActivityDefinition a ON a.idActivityDefinition = sr.idActivityDefinition
             WHERE sr.idCarePlan = :carePlan'
        );
        $stmt->execute(['medicationPlan' => $carePlanId, 'carePlan' => $carePlanId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Plus grand identifiant numérique de la table + 1, table verrouillée jusqu'à la fin de la transaction. */
    private function nextId(string $table, string $column): int
    {
        return (int) $this->pdo
            ->query("SELECT COALESCE(MAX(CAST($column AS UNSIGNED)), 0) + 1 FROM $table FOR UPDATE")
            ->fetchColumn();
    }

    private static function sqlDate(?DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d H:i:s');
    }
}
