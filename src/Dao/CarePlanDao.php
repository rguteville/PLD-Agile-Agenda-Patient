<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\CarePlan;
use Aura\Domain\MedicationRequest;
use Aura\Domain\RequestLine;
use Aura\Domain\ServiceRequest;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;

/**
 * Accès aux prescriptions (Epic 4) : CarePlan = la prescription (l'ordonnance), ServiceRequest =
 * ses lignes de soin, MedicationRequest = ses lignes de médicament. Classes et colonnes portent
 * les mêmes noms : rien n'est traduit ici, le DAO ne fait que lire et écrire.
 */
class CarePlanDao
{
    private const SQL_DATE_FORMAT = 'Y-m-d H:i:s';

    private const SERVICE_COLUMNS = 'idServiceRequest, idCarePlan, idActivityDefinition, startDate, endDate,
        frequencyHours, timeConstraint, idStoppedBy, stoppedAt';

    private const MEDICATION_COLUMNS = 'idMedicationRequest, idCarePlan, idActivityDefinition, idMedication, dose,
        route, important, nurseInstructions, maxDose, dilutionVolume, solvent, administrationDuration,
        antibioticReason, germ, renalAdjustment, adjustmentCriterion, offGhsReason, startDate, endDate,
        frequencyHours, timeConstraint, idStoppedBy, stoppedAt';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Prescriptions d'un séjour, de la plus récente à la plus ancienne, avec leurs lignes
     * (triées par date de début). Les prescriptions des autres séjours du patient sont exclues.
     *
     * @return CarePlan[]
     */
    public function findByEpisodeOfCare(int $idEpisodeOfCare): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT idCarePlan, idEpisodeOfCare, idPrescriber, mode, recordedAt, idValidator, validatedAt
             FROM CarePlan
             WHERE idEpisodeOfCare = :idEpisodeOfCare
             ORDER BY recordedAt DESC, CAST(idCarePlan AS UNSIGNED) DESC'
        );
        $stmt->execute(['idEpisodeOfCare' => (string) $idEpisodeOfCare]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $lines = $this->linesOfEpisodeOfCare($idEpisodeOfCare);

        return array_map(
            fn(array $r) => $this->mapToCarePlan($r, $lines[(int) $r['idCarePlan']] ?? []),
            $rows
        );
    }

    /**
     * Lignes de médicament d'une prescription, triées par date de début.
     *
     * @return MedicationRequest[]
     */
    public function findMedicationByCarePlan(int $idCarePlan): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::MEDICATION_COLUMNS . ' FROM MedicationRequest
             WHERE idCarePlan = :idCarePlan
             ORDER BY startDate, CAST(idMedicationRequest AS UNSIGNED)'
        );
        $stmt->execute(['idCarePlan' => (string) $idCarePlan]);

        return array_map(fn(array $r) => $this->mapToMedicationRequest($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Lignes de soin d'une prescription, triées par date de début.
     *
     * @return ServiceRequest[]
     */
    public function findServiceByCarePlan(int $idCarePlan): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SERVICE_COLUMNS . ' FROM ServiceRequest
             WHERE idCarePlan = :idCarePlan
             ORDER BY startDate, CAST(idServiceRequest AS UNSIGNED)'
        );
        $stmt->execute(['idCarePlan' => (string) $idCarePlan]);

        return array_map(fn(array $r) => $this->mapToServiceRequest($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Enregistre une prescription et toutes ses lignes, et renvoie son idCarePlan.
     * Tout est écrit dans une transaction : si une ligne est refusée, rien n'est enregistré.
     *
     * L'horodatage de saisie (recordedAt, RG10) est rempli par la base. Une prescription validée
     * dès la saisie (prescripteur médecin, RG5) reçoit le même horodatage comme date de validation :
     * les deux viennent de l'horloge de la base, jamais de celle de PHP, qui peut être sur un autre
     * fuseau horaire (une prescription paraîtrait alors validée avant d'avoir été saisie).
     *
     * Comme pour Patient et EpisodeOfCare, les identifiants n'ont pas d'AUTO_INCREMENT : ils sont
     * calculés ici (plus grand + 1), tables verrouillées (FOR UPDATE) jusqu'à la fin de la transaction.
     */
    public function insert(CarePlan $carePlan): int
    {
        return (new Transaction($this->pdo))->run(function () use ($carePlan): int {
            $idCarePlan = $this->nextId('CarePlan', 'idCarePlan');

            $this->pdo->prepare(
                'INSERT INTO CarePlan (idCarePlan, idEpisodeOfCare, idPrescriber, mode, idValidator, validatedAt)
                 VALUES (:idCarePlan, :idEpisodeOfCare, :idPrescriber, :mode, :idValidator,
                         IF(:validatedOnRecord, CURRENT_TIMESTAMP, NULL))'
            )->execute([
                'idCarePlan' => (string) $idCarePlan,
                'idEpisodeOfCare' => (string) $carePlan->getIdEpisodeOfCare(),
                'idPrescriber' => $carePlan->getIdPrescriber(),
                'mode' => $carePlan->getMode(),
                'idValidator' => $carePlan->isValidated() ? $carePlan->getIdValidator() : null,
                'validatedOnRecord' => $carePlan->isValidated() ? 1 : 0,
            ]);

            foreach ($carePlan->getLines() as $line) {
                if ($line instanceof MedicationRequest) {
                    $this->insertMedicationRequest($idCarePlan, $line);
                } else {
                    $this->insertServiceRequest($idCarePlan, $line);
                }
            }

            return $idCarePlan;
        });
    }

    /**
     * RG5 / AURA-23 : un médecin valide une prescription « non validée ». La date de validation
     * vient de l'horloge de la base (RG10), comme celle de la saisie.
     *
     * Renvoie false si la prescription n'existe pas ou est déjà validée : la condition
     * « validatedAt IS NULL » est dans la requête elle-même, si bien que deux médecins qui
     * valident en même temps ne peuvent pas écraser la validation du premier.
     */
    public function markValidated(int $idCarePlan, string $idValidator): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE CarePlan SET idValidator = :idValidator, validatedAt = CURRENT_TIMESTAMP
             WHERE idCarePlan = :idCarePlan AND validatedAt IS NULL'
        );
        $stmt->execute(['idValidator' => $idValidator, 'idCarePlan' => (string) $idCarePlan]);

        return $stmt->rowCount() === 1;
    }

    /**
     * AURA-22 : arrête une ligne (soin ou médicament). Qui et quand sont enregistrés (RG10) ;
     * l'heure vient de l'horloge de la base, comme la saisie et la validation.
     *
     * Renvoie false si la ligne n'existe pas ou est déjà arrêtée : comme pour markValidated(),
     * la condition « stoppedAt IS NULL » est dans la requête, si bien qu'un second arrêt
     * simultané ne peut pas écraser le premier.
     */
    public function markStopped(bool $isMedication, int $idLine, string $idStoppedBy): bool
    {
        // Noms fixés ici, jamais saisis : les concaténer ne permet aucune injection.
        [$table, $column] = $isMedication
            ? ['MedicationRequest', 'idMedicationRequest']
            : ['ServiceRequest', 'idServiceRequest'];
        $stmt = $this->pdo->prepare(
            "UPDATE $table SET idStoppedBy = :idStoppedBy, stoppedAt = CURRENT_TIMESTAMP
             WHERE $column = :idLine AND stoppedAt IS NULL"
        );
        $stmt->execute(['idStoppedBy' => $idStoppedBy, 'idLine' => (string) $idLine]);

        return $stmt->rowCount() === 1;
    }

    private function insertServiceRequest(int $idCarePlan, RequestLine $line): void
    {
        $this->pdo->prepare(
            'INSERT INTO ServiceRequest (idServiceRequest, idCarePlan, idActivityDefinition, startDate, endDate,
                                         frequencyHours, timeConstraint, idStoppedBy, stoppedAt)
             VALUES (:id, :idCarePlan, :idActivityDefinition, :startDate, :endDate,
                     :frequencyHours, :timeConstraint, :idStoppedBy, :stoppedAt)'
        )->execute(['id' => (string) $this->nextId('ServiceRequest', 'idServiceRequest')]
            + $this->commonColumns($idCarePlan, $line));
    }

    private function insertMedicationRequest(int $idCarePlan, MedicationRequest $line): void
    {
        $this->pdo->prepare(
            'INSERT INTO MedicationRequest (idMedicationRequest, idCarePlan, idActivityDefinition, idMedication,
                                            dose, route, important, nurseInstructions, maxDose, dilutionVolume,
                                            solvent, administrationDuration, antibioticReason, germ,
                                            renalAdjustment, adjustmentCriterion, offGhsReason, startDate,
                                            endDate, frequencyHours, timeConstraint, idStoppedBy, stoppedAt)
             VALUES (:id, :idCarePlan, :idActivityDefinition, :idMedication, :dose, :route, :important,
                     :nurseInstructions, :maxDose, :dilutionVolume, :solvent, :administrationDuration,
                     :antibioticReason, :germ, :renalAdjustment, :adjustmentCriterion, :offGhsReason,
                     :startDate, :endDate, :frequencyHours, :timeConstraint, :idStoppedBy, :stoppedAt)'
        )->execute(['id' => (string) $this->nextId('MedicationRequest', 'idMedicationRequest')]
            + $this->commonColumns($idCarePlan, $line)
            + [
                'idMedication' => $line->getIdMedication(),
                'dose' => $line->getDose(),
                'route' => $line->getRoute(),
                'important' => $line->getImportant(),
                'nurseInstructions' => $line->getNurseInstructions(),
                'maxDose' => $line->getMaxDose(),
                'dilutionVolume' => $line->getDilutionVolume(),
                'solvent' => $line->getSolvent(),
                'administrationDuration' => $line->getAdministrationDuration(),
                'antibioticReason' => $line->getAntibioticReason(),
                'germ' => $line->getGerm(),
                'renalAdjustment' => $line->getRenalAdjustment(),
                'adjustmentCriterion' => $line->getAdjustmentCriterion(),
                'offGhsReason' => $line->getOffGhsReason(),
            ]);
    }

    /**
     * Valeurs des colonnes que les deux tables de lignes ont en commun.
     *
     * @return array<string, mixed>
     */
    private function commonColumns(int $idCarePlan, RequestLine $line): array
    {
        return [
            'idCarePlan' => (string) $idCarePlan,
            'idActivityDefinition' => $line->getIdActivityDefinition(),
            'startDate' => self::toSql($line->getStartDate()),
            'endDate' => self::toSql($line->getEndDate()),
            'frequencyHours' => $line->getFrequencyHours(),
            'timeConstraint' => $line->getTimeConstraint(),
            'idStoppedBy' => $line->getIdStoppedBy(),
            'stoppedAt' => self::toSql($line->getStoppedAt()),
        ];
    }

    /**
     * Plus grand identifiant + 1. Les noms de table et de colonne viennent uniquement de ce
     * fichier (jamais d'une saisie) : les concaténer dans la requête ne permet aucune injection.
     */
    private function nextId(string $table, string $column): int
    {
        return (int) $this->pdo
            ->query("SELECT COALESCE(MAX(CAST($column AS UNSIGNED)), 0) + 1 FROM $table FOR UPDATE")
            ->fetchColumn();
    }

    /**
     * Lignes de toutes les prescriptions du séjour, regroupées par idCarePlan.
     *
     * @return array<int, RequestLine[]>
     */
    private function linesOfEpisodeOfCare(int $idEpisodeOfCare): array
    {
        $byCarePlan = [];

        $services = $this->pdo->prepare(
            'SELECT ' . self::SERVICE_COLUMNS . ' FROM ServiceRequest
             WHERE idCarePlan IN (SELECT idCarePlan FROM CarePlan WHERE idEpisodeOfCare = :idEpisodeOfCare)'
        );
        $services->execute(['idEpisodeOfCare' => (string) $idEpisodeOfCare]);
        foreach ($services->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byCarePlan[(int) $r['idCarePlan']][] = $this->mapToServiceRequest($r);
        }

        $medications = $this->pdo->prepare(
            'SELECT ' . self::MEDICATION_COLUMNS . ' FROM MedicationRequest
             WHERE idCarePlan IN (SELECT idCarePlan FROM CarePlan WHERE idEpisodeOfCare = :idEpisodeOfCare)'
        );
        $medications->execute(['idEpisodeOfCare' => (string) $idEpisodeOfCare]);
        foreach ($medications->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byCarePlan[(int) $r['idCarePlan']][] = $this->mapToMedicationRequest($r);
        }

        // Dans une prescription, soins et médicaments mélangés par date de début.
        foreach ($byCarePlan as &$lines) {
            usort($lines, static fn(RequestLine $a, RequestLine $b) => $a->getStartDate() <=> $b->getStartDate());
        }
        unset($lines);

        return $byCarePlan;
    }

    /** @param RequestLine[] $lines */
    private function mapToCarePlan(array $r, array $lines): CarePlan
    {
        return new CarePlan(
            idCarePlan: (int) $r['idCarePlan'],
            idEpisodeOfCare: (int) $r['idEpisodeOfCare'],
            idPrescriber: (string) $r['idPrescriber'],
            mode: (string) $r['mode'],
            lines: $lines,
            recordedAt: new DateTimeImmutable($r['recordedAt']),
            idValidator: $r['idValidator'],
            validatedAt: self::date($r['validatedAt'])
        );
    }

    private function mapToServiceRequest(array $r): ServiceRequest
    {
        return new ServiceRequest(
            id: (int) $r['idServiceRequest'],
            idActivityDefinition: (string) $r['idActivityDefinition'],
            startDate: new DateTimeImmutable($r['startDate']),
            endDate: self::date($r['endDate']),
            frequencyHours: $r['frequencyHours'] !== null ? (int) $r['frequencyHours'] : null,
            timeConstraint: $r['timeConstraint'],
            idStoppedBy: $r['idStoppedBy'],
             stoppedAt: self::date($r['stoppedAt']),
            idCarePlan: (int) $r['idCarePlan']
        );
    }

    private function mapToMedicationRequest(array $r): MedicationRequest
    {
        return new MedicationRequest(
            id: (int) $r['idMedicationRequest'],
            idActivityDefinition: (string) $r['idActivityDefinition'],
            idMedication: (string) $r['idMedication'],
            dose: (string) $r['dose'],
            route: (string) $r['route'],
            startDate: new DateTimeImmutable($r['startDate']),
            endDate: self::date($r['endDate']),
            frequencyHours: $r['frequencyHours'] !== null ? (int) $r['frequencyHours'] : null,
            timeConstraint: $r['timeConstraint'],
            idStoppedBy: $r['idStoppedBy'],
             stoppedAt: self::date($r['stoppedAt']),
            idCarePlan: (int) $r['idCarePlan'],
            important: $r['important'],
            nurseInstructions: $r['nurseInstructions'],
            maxDose: $r['maxDose'],
            dilutionVolume: $r['dilutionVolume'],
            solvent: $r['solvent'],
            administrationDuration: $r['administrationDuration'],
            antibioticReason: $r['antibioticReason'],
            germ: $r['germ'],
            renalAdjustment: $r['renalAdjustment'],
            adjustmentCriterion: $r['adjustmentCriterion'],
            offGhsReason: $r['offGhsReason']
        );
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        return $value !== null ? new DateTimeImmutable($value) : null;
    }

    private static function toSql(?DateTimeInterface $date): ?string
    {
        return $date?->format(self::SQL_DATE_FORMAT);
    }
}
