<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PrescriptionEntryDao;
use DateTimeImmutable;
use InvalidArgumentException;

final class PrescriptionEntryService
{
    /** Valeurs autorisées par la contrainte chk_careplan_mode (database/schema.sql). */
    public const MODES = [
        'MEDICALE' => 'Prescription médicale',
        'PARAMEDICALE' => 'Programmation paramédicale',
        'PROTOCOLE' => 'Protocole',
        'URGENCE' => 'Urgence',
    ];

    /** Fréquences d'un médicament */
    public const FREQUENCIES = [
        'ASAP' => 'Dès que possible',
        'ONCE' => 'Une fois',
        'SCHEDULED' => 'Toutes les … heures',
        'AS_NEEDED' => 'Si besoin',
        'CONTINUOUS' => 'Continu',
    ];

    /** Voies d'administration proposées  */
    public const ROUTES = ['IV', 'IVSE', 'IM', 'SC', 'PO', 'SNG', 'Aérosol', 'Rectale', 'Cutanée'];

    /** Longueur des colonnes timeConstraint et dose (database/schema.sql). */
    private const TIME_CONSTRAINT_LENGTH = 200;
    private const DOSE_LENGTH = 50;

    private const DATE_FORMAT = 'Y-m-d\TH:i';

    public function __construct(
        private PrescriptionEntryDao $dao,
        private ?DateTimeImmutable $now = null
    ) {
    }

    /**
     * Vue-modèle de la fenêtre de saisie pour un séjour en cours.
     *
     * @return array{episodeId: string, modes: array<string, string>, prescribers: array<string, string>,
     *     validators: array<string, string>,
     *     categories: list<array{id: string, label: string, kind: string}>,
     *     careTypes: array<string, string>,
     *     medications: array<string, array{label: string, forms: array<string, string>}>,
     *     frequencies: array<string, string>, routes: list<string>,
     *     pendingCarePlans: list<array{id: string, mode: string, recordedAt: string, prescriber: string,
     *         lines: list<string>}>}
     * @throws InvalidArgumentException séjour inexistant ou terminé
     */
    public function getFormData(string $episodeId): array
    {
        $this->ensureOngoingEpisode($episodeId);

        // validators = médecins (rôle habilité à valider) : leurs prescriptions sont validées d'office.
        $prescribers = [];
        $validators = [];
        foreach ($this->dao->findPrescribers() as $p) {
            $prescribers[$p['id']] = $p['name'] . ($p['role'] !== '' ? ' (' . $p['role'] . ')' : '');
            if ($p['canValidate']) {
                $validators[$p['id']] = $prescribers[$p['id']];
            }
        }

        $pendingCarePlans = array_map(static fn(array $c): array => [
            'id' => $c['id'],
            'mode' => self::MODES[$c['mode']] ?? $c['mode'],
            'recordedAt' => (new DateTimeImmutable($c['recordedAt']))->format('d/m/Y H:i'),
            'prescriber' => $c['prescriber'],
            'lines' => $c['lines'],
        ], $this->dao->findPendingCarePlans($episodeId));

        $careTypes = [];
        foreach ($this->dao->findCareTypes() as $type) {
            $careTypes[$type['id']] = $type['name'];
        }

        // Liste « 2. » d'une catégorie de médicament = DCI ; liste « 3. » = formes (idMedication).
        $medications = [];
        foreach ($this->dao->findMedications() as $m) {
            $medications[$m['inn']]['label'] = $m['inn'];
            $medications[$m['inn']]['forms'][$m['id']] = $m['form'];
        }

        return [
            'episodeId' => $episodeId,
            'modes' => self::MODES,
            'prescribers' => $prescribers,
            'validators' => $validators,
            'categories' => $this->dao->findCategories(),
            'careTypes' => $careTypes,
            'medications' => $medications,
            'frequencies' => self::FREQUENCIES,
            'routes' => self::ROUTES,
            'pendingCarePlans' => $pendingCarePlans,
        ];
    }

    /**
     * RG5 : un médecin valide une ordonnance saisie par un autre soignant (RG10 : qui et quand).
     *
     * @return array<string, string> erreurs (clé = champ) ; [] si la validation est enregistrée
     */
    public function validateCarePlan(string $carePlanId, string $validatorId): array
    {
        $carePlan = $carePlanId === '' ? null : $this->dao->findCarePlan($carePlanId);
        if ($carePlan === null) {
            return ['carePlanId' => 'Prescription introuvable.'];
        }
        if ($carePlan['validated']) {
            return ['carePlanId' => 'Cette prescription est déjà validée.'];
        }

        $validator = null;
        foreach ($this->dao->findPrescribers() as $p) {
            if ($p['id'] === $validatorId) {
                $validator = $p;
            }
        }
        if ($validator === null) {
            return ['validator' => 'Choisissez le médecin qui valide.'];
        }
        if (!$validator['canValidate']) {
            return ['validator' => 'Seul un médecin peut valider une prescription.'];
        }

        if (!$this->dao->validateCarePlan($carePlanId, $validatorId, $this->now())) {
            return ['carePlanId' => 'Cette prescription est déjà validée.'];
        }

        return [];
    }

    /**
     * Contrôle serveur de l'ordonnance envoyée par le formulaire. Les messages ne
     * reprennent jamais les valeurs saisies (données de santé).
     *
     * @param array<string, mixed> $input episodeId, mode, prescriber, lines[n][…]
     * @return array<string, string> erreurs (clé = champ ou « lines.n ») ; [] si tout est valide
     */
    public function validate(array $input): array
    {
        return $this->analyse($input)['errors'];
    }

    /**
     * Enregistre l'ordonnance après contrôle.
     *
     * @param array<string, mixed> $input
     * @return string identifiant de l'ordonnance créée
     * @throws InvalidArgumentException si les données sont invalides
     */
    public function save(array $input): string
    {
        $analysis = $this->analyse($input);
        if ($analysis['errors'] !== []) {
            throw new InvalidArgumentException(implode(' ', $analysis['errors']));
        }

        return $this->dao->insertCarePlan(
            $analysis['carePlan'],
            $analysis['careLines'],
            $analysis['medicationLines']
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{errors: array<string, string>, carePlan: array<string, mixed>,
     *     careLines: list<array<string, mixed>>, medicationLines: list<array<string, mixed>>}
     */
    private function analyse(array $input): array
    {
        $errors = [];
        $episodeId = self::text($input['episodeId'] ?? null);
        $mode = self::text($input['mode'] ?? null);
        $prescriberId = self::text($input['prescriber'] ?? null);

        try {
            $this->ensureOngoingEpisode($episodeId);
        } catch (InvalidArgumentException $e) {
            $errors['episodeId'] = $e->getMessage();
        }
        if (!array_key_exists($mode, self::MODES)) {
            $errors['mode'] = 'Le mode de prescription est invalide.';
        }

        $prescriber = null;
        foreach ($this->dao->findPrescribers() as $p) {
            if ($p['id'] === $prescriberId) {
                $prescriber = $p;
            }
        }
        if ($prescriber === null) {
            $errors['prescriber'] = 'Le prescripteur est obligatoire.';
        }

        $lines = $input['lines'] ?? [];
        if (!is_array($lines) || $lines === []) {
            $errors['lines'] = 'Ajoutez au moins une prescription avant d\'enregistrer.';
            $lines = [];
        }

        $careLines = [];
        $medicationLines = [];
        $number = 0;
        foreach ($lines as $line) {
            $number++;
            try {
                if (!is_array($line)) {
                    throw new InvalidArgumentException('saisie invalide.');
                }
                $kind = self::text($line['kind'] ?? null);
                if ($kind === 'care') {
                    $careLines[] = $this->careLine($line);
                } elseif ($kind === 'medication') {
                    $medicationLines[] = $this->medicationLine($line);
                } else {
                    throw new InvalidArgumentException('nature de prescription inconnue.');
                }
            } catch (InvalidArgumentException $e) {
                $errors["lines.$number"] = "Ligne $number : " . $e->getMessage();
            }
        }

        // RG5 : prescrite par un médecin → validée d'office ; par un autre soignant → en attente
        // de validation par un médecin (validateCarePlan()).
        $autoValidated = $prescriber['canValidate'] ?? false;

        return [
            'errors' => $errors,
            'carePlan' => [
                'episodeId' => $episodeId,
                'prescriberId' => $prescriberId,
                'mode' => $mode,
                'validatorId' => $autoValidated ? $prescriberId : null,
                'validatedAt' => $autoValidated ? $this->now() : null,
            ],
            'careLines' => $careLines,
            'medicationLines' => $medicationLines,
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @return array{careTypeId: string, start: DateTimeImmutable, end: ?DateTimeImmutable,
     *     frequencyHours: ?int, timeConstraint: ?string}
     */
    private function careLine(array $line): array
    {
        $careTypeId = self::text($line['id'] ?? null);
        if (!in_array($careTypeId, array_column($this->dao->findCareTypes(), 'id'), true)) {
            throw new InvalidArgumentException('le type de soin doit être choisi dans la liste.');
        }

        [$start, $end] = $this->period($line);
        $frequency = self::text($line['frequencyHours'] ?? null);

        $timeConstraint = self::text($line['timeConstraint'] ?? null);
        if (mb_strlen($timeConstraint) > self::TIME_CONSTRAINT_LENGTH) {
            throw new InvalidArgumentException('la contrainte horaire dépasse 200 caractères.');
        }

        return [
            'careTypeId' => $careTypeId,
            'start' => $start,
            'end' => $end,
            'frequencyHours' => $frequency === '' ? null : $this->hours($frequency),
            'timeConstraint' => $timeConstraint === '' ? null : $timeConstraint,
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @return array{careTypeId: string, medicationId: string, dose: string, route: string,
     *     start: DateTimeImmutable, end: ?DateTimeImmutable, frequencyHours: ?int, timeConstraint: ?string}
     */
    private function medicationLine(array $line): array
    {
        $category = $this->dao->findCategory(self::text($line['categoryId'] ?? null));
        if ($category === null || $category['kind'] !== 'MEDICATION') {
            throw new InvalidArgumentException('la catégorie de médicament est invalide.');
        }

        $medicationId = self::text($line['id'] ?? null);
        if ($medicationId === '' || !$this->dao->medicationExists($medicationId)) {
            throw new InvalidArgumentException('le médicament doit être choisi dans la liste.');
        }

        $frequencyType = self::text($line['frequencyType'] ?? null);
        if (!array_key_exists($frequencyType, self::FREQUENCIES)) {
            throw new InvalidArgumentException('la fréquence est obligatoire.');
        }
        $frequencyHours = $frequencyType === 'SCHEDULED'
            ? $this->hours(self::text($line['frequencyHours'] ?? null))
            : null;

        $route = self::text($line['route'] ?? null);
        if (!in_array($route, self::ROUTES, true)) {
            throw new InvalidArgumentException('la voie d\'administration est obligatoire.');
        }

        $dose = self::text($line['dose'] ?? null);
        if ($dose === '' || mb_strlen($dose) > self::DOSE_LENGTH) {
            throw new InvalidArgumentException('la dose est obligatoire (50 caractères au plus).');
        }

        [$start, $end] = $this->period($line);

        // timeConstraint : fréquence non horaire, puis « Important » et « Consignes pour IDE ».
        $parts = [];
        if ($frequencyType !== 'SCHEDULED') {
            $parts[] = self::FREQUENCIES[$frequencyType];
        }
        $important = self::text($line['important'] ?? null);
        if ($important !== '') {
            $parts[] = 'Important : ' . $important;
        }
        $nurseInstructions = self::text($line['nurseInstructions'] ?? null);
        if ($nurseInstructions !== '') {
            $parts[] = 'Consignes IDE : ' . $nurseInstructions;
        }
        $timeConstraint = implode(' ; ', $parts);
        if (mb_strlen($timeConstraint) > self::TIME_CONSTRAINT_LENGTH) {
            throw new InvalidArgumentException('« Important » et les consignes dépassent 200 caractères au total.');
        }

        return [
            'careTypeId' => $category['id'],
            'medicationId' => $medicationId,
            'dose' => $dose,
            'route' => $route,
            'start' => $start,
            'end' => $end,
            'frequencyHours' => $frequencyHours,
            'timeConstraint' => $timeConstraint === '' ? null : $timeConstraint,
        ];
    }

    /**
     * RG3 : début obligatoire ; fin (arrêt) facultative et postérieure au début.
     *
     * @param array<string, mixed> $line
     * @return array{DateTimeImmutable, ?DateTimeImmutable}
     */
    private function period(array $line): array
    {
        $start = self::parseDate(self::text($line['start'] ?? null));
        if ($start === null) {
            throw new InvalidArgumentException('la date de début est obligatoire (jj/mm/aaaa hh:mm).');
        }

        $endInput = self::text($line['end'] ?? null);
        $end = $endInput === '' ? null : self::parseDate($endInput);
        if ($endInput !== '' && $end === null) {
            throw new InvalidArgumentException('la date de fin est invalide.');
        }
        if ($end !== null && $end < $start) {
            throw new InvalidArgumentException('la date de fin doit être après la date de début.');
        }

        return [$start, $end];
    }

    private function hours(string $value): int
    {
        if (!preg_match('/^\d{1,4}$/', $value) || (int) $value < 1) {
            throw new InvalidArgumentException('la fréquence doit être un nombre d\'heures supérieur à 0.');
        }

        return (int) $value;
    }

    /** RG4 : on ne prescrit que pour un séjour existant et en cours. */
    private function ensureOngoingEpisode(string $episodeId): void
    {
        $episode = $episodeId === '' ? null : $this->dao->findEpisode($episodeId);
        if ($episode === null) {
            throw new InvalidArgumentException('Séjour introuvable.');
        }
        if (!$episode['ongoing']) {
            throw new InvalidArgumentException('Ce séjour est terminé : impossible de prescrire.');
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable();
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);

        return $date !== false && $date->format(self::DATE_FORMAT) === $value ? $date : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
