<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\CarePlanDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Domain\CarePlan;
use Aura\Domain\MedicationRequest;
use Aura\Domain\Practitioner;
use Aura\Domain\RequestLine;
use Aura\Domain\ServiceRequest;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Prescriptions d'un séjour (Epic 4) : liste (tableau des prescriptions, figure 10 du cahier
 * des charges), saisie d'une ordonnance (écran B3 « Nouvelles prescriptions », figures 7 à 9)
 * et validation par un médecin (RG5, AURA-23).
 *
 * Une ordonnance (CarePlan) est saisie en une fois avec toutes ses lignes : chaque « OK » de
 * l'écran B3 ajoute une ligne, « Enregistrer l'ordonnance » envoie le tout.
 * Les clés des données saisies sont les noms des colonnes (idPrescriber, idMedication,
 * frequencyHours…), et ce sont aussi les noms des champs envoyés : un seul vocabulaire.
 * checkInput() est pure (aucun accès à la base) ; prescribe() applique ensuite les règles qui
 * demandent la base : séjour en cours, prescripteur et référentiels existants, droits (RG5).
 */
final class CarePlanService
{
    /** Modes de prescription (figure 7) : code en base => libellé affiché. */
    public const MODE_LABELS = [
        'MEDICAL' => 'Prescription médicale',
        'PARAMEDICAL' => 'Programmation paramédicale',
        'EMERGENCY' => 'Urgence',
    ];

    /** Nature d'une ligne : un médicament (MedicationRequest) ou un soin (ServiceRequest). */
    public const KIND_MEDICATION = 'MEDICATION';
    public const KIND_CARE = 'CARE';
    /** Nature d'une catégorie qui regroupe plusieurs prescriptions (protocole) : pas encore en base. */
    public const KIND_GROUP = 'GROUP';

    /**
     * Catégories de la liste « 1. » de l'écran B3 (figure 8) : id => libellé et nature.
     * La nature décide de ce que proposent les listes « 2. » et « 3. ».
     * Une catégorie de plus = une ligne ici, rien d'autre à changer.
     */
    public const CATEGORIES = [
        'GROUPS' => ['label' => 'Groupes de prescriptions', 'kind' => self::KIND_GROUP],
        'MEDICATIONS' => ['label' => 'Médicaments', 'kind' => self::KIND_MEDICATION],
        'CARE' => ['label' => 'Soins', 'kind' => self::KIND_CARE],
    ];

    /** Voies d'administration : code en base => libellé affiché. */
    public const ROUTES = [
        'IV' => 'IV',
        'IVSE' => 'IVSE',
        'PO' => 'PO',
        'SC' => 'SC',
        'IM' => 'IM',
        'SNG' => 'SNG',
        'INH' => 'Inhalée',
    ];

    /**
     * Fréquence d'une ligne (RG3) : une seule fois (frequencyHours vide en base), toutes les
     * N heures (frequencyHours = N) ou tous les N jours, saisi en jours pour que le médecin n'ait
     * pas à convertir : la base ne stocke que des heures (frequencyHours = N × 24).
     */
    public const FREQUENCY_TYPES = [
        'ONCE' => 'Une fois',
        'SCHEDULED' => 'Toutes les … heures',
        'DAYS' => 'Tous les … jours',
    ];

    /** RG6 : type de soin donné d'office à une ligne de médicament (ActivityDefinition.name). */
    public const MEDICATION_ACTIVITY = 'Administration médicamenteuse';

    /** Champs d'une ligne envoyés par l'écran B3 (lines[n][…]) : mêmes noms que les colonnes. */
    public const LINE_FIELDS = [
        'kind',
        'idActivityDefinition',
        'idMedication',
        'dose',
        'route',
        'important',
        'nurseInstructions',
        'maxDose',
        'dilutionVolume',
        'solvent',
        'administrationDuration',
        'antibioticReason',
        'germ',
        'renalAdjustment',
        'adjustmentCriterion',
        'offGhsReason',
        'frequencyType',
        'frequencyHours',
        'frequencyDays',
        'startDate',
        'endDate',
        'timeConstraint',
    ];

    /** Format envoyé par un champ <input type="datetime-local"> : 2026-10-19T08:00. */
    public const INPUT_DATE_FORMAT = 'Y-m-d\TH:i';

    /**
     * Longueurs maximales : colonnes VARCHAR(50) (dose, dose max, volume de dilution, durée
     * d'administration) et VARCHAR(200) (autres textes libres).
     */
    public const DOSE_MAX_LENGTH = 50;
    public const TEXT_MAX_LENGTH = 200;
    /** frequencyHours est un SMALLINT : 32767 au plus. */
    public const FREQUENCY_MAX_HOURS = 32767;
    /** « Tous les N jours » : N × 24 doit tenir dans frequencyHours. */
    public const FREQUENCY_MAX_DAYS = 1365;
    /** Garde-fou contre une requête forgée : une ordonnance réelle en est très loin. */
    public const MAX_LINES = 50;

    public function __construct(
        private CarePlanDao $carePlanDao,
        private ReferenceDataDao $referenceDataDao,
        private DossierPatientService $dossierPatientService
    ) {
    }

    /**
     * Données de la page « Prescriptions du séjour » : bandeau (RG2) et une ligne par soin ou
     * médicament prescrit, de la prescription la plus récente à la plus ancienne.
     *
     * @return array<string, mixed>|null null si le séjour n'existe pas
     */
    public function listing(int $idEpisodeOfCare): ?array
    {
        $dossier = $this->dossierPatientService->dossier($idEpisodeOfCare);
        if ($dossier === null) {
            return null;
        }

        return [
            // Bandeau identité + allergies : vue-modèle de DossierPatientService (clés existantes).
            'banner' => $dossier['bandeau'],
            'idEpisodeOfCare' => $idEpisodeOfCare,
            'isOpen' => $dossier['sejour']['enCours'],
            'lines' => $this->presentedLines($idEpisodeOfCare),
        ];
    }

    /**
     * Prescriptions affichées dans le dossier du séjour : les lignes des ordonnances validées par
     * un médecin (RG5), sauf celles qui ont été arrêtées. Une ordonnance « non validée » n'y
     * apparaît qu'une fois validée.
     *
     * @return list<array<string, mixed>> vue-modèle de presentLine()
     */
    public function validatedLines(int $idEpisodeOfCare): array
    {
        return array_values(array_filter(
            $this->presentedLines($idEpisodeOfCare),
            static fn(array $line) => $line['status'] === CarePlan::STATUS_VALIDATED
        ));
    }

    /**
     * Toutes les lignes des ordonnances du séjour, de l'ordonnance la plus récente à la plus ancienne.
     *
     * @return list<array<string, mixed>> vue-modèle de presentLine()
     */
    private function presentedLines(int $idEpisodeOfCare): array
    {
        $carePlans = $this->carePlanDao->findByEpisodeOfCare($idEpisodeOfCare);
        if ($carePlans === []) {
            return [];
        }

        $medications = $this->referenceDataDao->medications();
        $activityDefinitions = $this->referenceDataDao->activityDefinitions();
        $practitioners = $this->referenceDataDao->practitioners();

        $lines = [];
        foreach ($carePlans as $carePlan) {
            foreach ($carePlan->getLines() as $line) {
                $lines[] = self::presentLine($carePlan, $line, $medications, $activityDefinitions, $practitioners);
            }
        }

        return $lines;
    }

    /**
     * Données de l'écran B3 : bandeau (RG2), contenu des listes, et ordonnances du séjour en
     * attente de validation par un médecin (RG5).
     *
     * $savedCarePlanId / $validatedCarePlanId (lus dans l'URL après un enregistrement ou une
     * validation) ne sont repris que s'ils désignent une ordonnance de ce séjour : sinon le
     * message « Ordonnance n° … enregistrée » pourrait être fabriqué avec un faux lien.
     *
     * @return array<string, mixed>|null null si le séjour n'existe pas
     */
    public function form(
        int $idEpisodeOfCare,
        ?int $savedCarePlanId = null,
        ?int $validatedCarePlanId = null
    ): ?array {
        $dossier = $this->dossierPatientService->dossier($idEpisodeOfCare);
        if ($dossier === null) {
            return null;
        }

        $practitioners = $this->referenceDataDao->practitioners();
        $prescribers = [];
        $validators = [];
        foreach ($practitioners as $id => $practitioner) {
            $prescribers[$id] = self::nameAndRole($practitioner);
            if ($practitioner->canValidate()) {
                $validators[$id] = $prescribers[$id];
            }
        }

        $medications = $this->referenceDataDao->medications();
        $activityDefinitions = $this->referenceDataDao->activityDefinitions();
        $carePlanIds = [];
        $pendingCarePlans = [];
        foreach ($this->carePlanDao->findByEpisodeOfCare($idEpisodeOfCare) as $carePlan) {
            $carePlanIds[] = $carePlan->getIdCarePlan();
            if ($carePlan->isValidated()) {
                continue;
            }
            $lines = [];
            foreach ($carePlan->getLines() as $line) {
                $lines[] = self::summary(
                    self::presentLine($carePlan, $line, $medications, $activityDefinitions, $practitioners)
                );
            }
            $pendingCarePlans[] = [
                'idCarePlan' => $carePlan->getIdCarePlan(),
                'mode' => self::MODE_LABELS[$carePlan->getMode()] ?? $carePlan->getMode(),
                'recordedAt' => $carePlan->getRecordedAt()?->format('d/m/Y H:i'),
                'prescriber' => self::name($practitioners, $carePlan->getIdPrescriber()),
                'lines' => $lines,
            ];
        }

        return [
            'banner' => $dossier['bandeau'],
            'idEpisodeOfCare' => $idEpisodeOfCare,
            'isOpen' => $dossier['sejour']['enCours'],
            'savedCarePlanId' => in_array($savedCarePlanId, $carePlanIds, true) ? $savedCarePlanId : null,
            'validatedCarePlanId' => in_array($validatedCarePlanId, $carePlanIds, true) ? $validatedCarePlanId : null,
            'modes' => self::MODE_LABELS,
            'prescribers' => $prescribers,
            'validators' => $validators,
            'categories' => self::CATEGORIES,
            'careTypes' => self::careTypes($activityDefinitions),
            'medications' => $this->referenceDataDao->medicationsByInn(),
            'frequencyTypes' => self::FREQUENCY_TYPES,
            'routes' => self::ROUTES,
            'pendingCarePlans' => $pendingCarePlans,
        ];
    }

    /**
     * Contrôle de la saisie, sans accès à la base. Une clé absente est traitée comme vide.
     * Les messages (affichés) sont en français et ne reprennent jamais la valeur saisie.
     *
     * @param array<string, mixed> $input mode, idPrescriber, lines (liste de lignes, clés LINE_FIELDS)
     * @return array<string, string> erreurs indexées par champ (lines[0][dose]…) ; [] si tout est valide
     */
    public function checkInput(array $input): array
    {
        $errors = [];

        if (!in_array(self::text($input['mode'] ?? null), CarePlan::MODES, true)) {
            $errors['mode'] = 'Choisissez un mode de prescription.';
        }
        if (self::text($input['idPrescriber'] ?? null) === '') {
            $errors['idPrescriber'] = 'Choisissez le prescripteur (RG4).';
        }

        $lines = $input['lines'] ?? [];
        if (!is_array($lines) || $lines === []) {
            $errors['lines'] = 'L\'ordonnance est vide : ajoutez au moins une prescription avec « OK ».';
        } elseif (count($lines) > self::MAX_LINES) {
            $errors['lines'] = sprintf('Une ordonnance ne peut pas dépasser %d lignes.', self::MAX_LINES);
        } else {
            foreach (array_values($lines) as $i => $line) {
                foreach (self::checkLine(is_array($line) ? $line : []) as $field => $message) {
                    $errors[sprintf('lines[%d][%s]', $i, $field)] = sprintf('Ligne %d : %s', $i + 1, $message);
                }
            }
        }

        return $errors;
    }

    /**
     * Enregistre une ordonnance et toutes ses lignes pour un séjour en cours ; renvoie son idCarePlan.
     *
     * RG5 : un médecin (droit de validation) peut prescrire dans tous les modes et son ordonnance
     * est validée d'office. Un autre soignant ne peut pas faire de prescription médicale ; en
     * urgence ou en paramédical, son ordonnance est enregistrée « non validée » et reste
     * visible jusqu'à la validation par un médecin (validateCarePlan()).
     *
     * @param array<string, mixed> $input voir checkInput()
     * @throws InvalidArgumentException saisie invalide ou ordonnance refusée (message affichable)
     */
    public function prescribe(int $idEpisodeOfCare, array $input): int
    {
        $errors = $this->checkInput($input);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $dossier = $this->dossierPatientService->dossier($idEpisodeOfCare);
        if ($dossier === null) {
            throw new InvalidArgumentException('Ce séjour n\'existe pas.');
        }
        if (!$dossier['sejour']['enCours']) {
            throw new InvalidArgumentException('Ce séjour est clos : on ne peut plus y ajouter de prescription.');
        }

        $prescriber = $this->referenceDataDao->practitioner(self::text($input['idPrescriber']));
        if ($prescriber === null) {
            throw new InvalidArgumentException('Ce prescripteur n\'existe pas.');
        }
        $mode = self::text($input['mode']);
        if ($mode === 'MEDICAL' && !$prescriber->canValidate()) {
            throw new InvalidArgumentException(
                'Seul un médecin peut faire une prescription médicale : '
                . 'choisissez le mode urgence ou paramédical (RG5).'
            );
        }

        $activityDefinitions = $this->referenceDataDao->activityDefinitions();
        $careTypes = self::careTypes($activityDefinitions);
        $medicationActivity = array_search(self::MEDICATION_ACTIVITY, $activityDefinitions, true);
        $medications = $this->referenceDataDao->medications();

        // Chaque soin et chaque médicament doit exister ; RG6 : chaque ligne a un type de soin.
        $lines = [];
        foreach (array_values($input['lines']) as $i => $line) {
            $number = $i + 1;
            if (self::text($line['kind']) === self::KIND_CARE) {
                if (!array_key_exists(self::text($line['idActivityDefinition']), $careTypes)) {
                    throw new InvalidArgumentException(sprintf('Ligne %d : ce soin n\'existe pas.', $number));
                }
                $lines[] = self::buildLine($line, self::text($line['idActivityDefinition']));
                continue;
            }
            if (!array_key_exists(self::text($line['idMedication']), $medications)) {
                throw new InvalidArgumentException(sprintf('Ligne %d : ce médicament n\'existe pas.', $number));
            }
            if ($medicationActivity === false) {
                throw new InvalidArgumentException(sprintf(
                    'Le type de soin « %s » manque dans la base (RG6).',
                    self::MEDICATION_ACTIVITY
                ));
            }
            $lines[] = self::buildLine($line, (string) $medicationActivity);
        }

        $validatedOnRecord = $prescriber->canValidate();
        $carePlan = new CarePlan(
            idCarePlan: null,
            idEpisodeOfCare: $idEpisodeOfCare,
            idPrescriber: $prescriber->getIdPractitioner(),
            mode: $mode,
            lines: $lines,
            idValidator: $validatedOnRecord ? $prescriber->getIdPractitioner() : null,
            // Seulement pour dire « validée » : la date réellement enregistrée est celle de la base.
            validatedAt: $validatedOnRecord ? new DateTimeImmutable() : null
        );

        return $this->carePlanDao->insert($carePlan);
    }

    /**
     * RG5 / AURA-23 : un médecin valide une ordonnance « non validée » de ce séjour.
     * La validation porte sur toute l'ordonnance ; qui et quand sont enregistrés (RG10).
     *
     * @throws InvalidArgumentException validation refusée (message affichable)
     */
    public function validateCarePlan(int $idEpisodeOfCare, int $idCarePlan, string $idValidator): void
    {
        $validator = $idValidator !== '' ? $this->referenceDataDao->practitioner($idValidator) : null;
        if ($validator === null) {
            throw new InvalidArgumentException('Choisissez le médecin qui valide.');
        }
        if (!$validator->canValidate()) {
            throw new InvalidArgumentException('Seul un médecin peut valider une ordonnance (RG5).');
        }

        $carePlan = null;
        foreach ($this->carePlanDao->findByEpisodeOfCare($idEpisodeOfCare) as $candidate) {
            if ($candidate->getIdCarePlan() === $idCarePlan) {
                $carePlan = $candidate;
            }
        }
        if ($carePlan === null) {
            throw new InvalidArgumentException('Cette ordonnance n\'existe pas dans ce séjour.');
        }
        // Deuxième test : un autre médecin a pu valider entre la lecture et l'écriture.
        if ($carePlan->isValidated() || !$this->carePlanDao->markValidated($idCarePlan, $idValidator)) {
            throw new InvalidArgumentException('Cette ordonnance est déjà validée.');
        }
    }

    /**
     * AURA-22 : un médecin arrête une ligne (soin ou médicament) d'une ordonnance de ce séjour.
     * La ligne quitte alors « Prescriptions en cours » du dossier ; qui et quand sont enregistrés (RG10).
     *
     * @throws InvalidArgumentException arrêt refusé (message affichable)
     */
    public function stopLine(int $idEpisodeOfCare, bool $isMedication, int $idLine, string $idStoppedBy): void
    {
        $doctor = $idStoppedBy !== '' ? $this->referenceDataDao->practitioner($idStoppedBy) : null;
        if ($doctor === null) {
            throw new InvalidArgumentException('Choisissez le médecin qui arrête la prescription.');
        }
        if (!$doctor->canValidate()) {
            throw new InvalidArgumentException('Seul un médecin peut arrêter une prescription.');
        }

        $dossier = $this->dossierPatientService->dossier($idEpisodeOfCare);
        if ($dossier === null || !$dossier['sejour']['enCours']) {
            throw new InvalidArgumentException('Ce séjour est clos : ses prescriptions ne peuvent plus changer.');
        }

        $found = null;
        foreach ($this->carePlanDao->findByEpisodeOfCare($idEpisodeOfCare) as $carePlan) {
            foreach ($carePlan->getLines() as $line) {
                if ($line->getId() === $idLine && ($line instanceof MedicationRequest) === $isMedication) {
                    $found = $line;
                }
            }
        }
        if ($found === null) {
            throw new InvalidArgumentException('Cette prescription n\'existe pas dans ce séjour.');
        }
        // Deuxième test : un autre médecin a pu l'arrêter entre la lecture et l'écriture.
        if ($found->isStopped() || !$this->carePlanDao->markStopped($isMedication, $idLine, $idStoppedBy)) {
            throw new InvalidArgumentException('Cette prescription est déjà arrêtée.');
        }
    }

    /**
     * Médecins (droit de validation, RG5) : ceux qui peuvent valider ou arrêter une prescription.
     *
     * @return array<string, string> idPractitioner => « nom (rôle) »
     */
    public function doctors(): array
    {
        $doctors = [];
        foreach ($this->referenceDataDao->practitioners() as $id => $practitioner) {
            if ($practitioner->canValidate()) {
                $doctors[$id] = self::nameAndRole($practitioner);
            }
        }

        return $doctors;
    }

    /**
     * Erreurs d'une ligne, sans accès à la base. Messages sans majuscule : checkInput() les
     * préfixe par « Ligne n : ».
     *
     * @param array<string, mixed> $line clés de self::LINE_FIELDS
     * @return array<string, string>
     */
    private static function checkLine(array $line): array
    {
        $errors = [];

        $kind = self::text($line['kind'] ?? null);
        if ($kind === self::KIND_MEDICATION) {
            if (self::text($line['idMedication'] ?? null) === '') {
                $errors['idMedication'] = 'choisissez un médicament.';
            }
            $dose = self::text($line['dose'] ?? null);
            if ($dose === '') {
                $errors['dose'] = 'la dose est obligatoire.';
            } elseif (mb_strlen($dose) > self::DOSE_MAX_LENGTH) {
                $errors['dose'] = sprintf('« Dose » dépasse %d caractères.', self::DOSE_MAX_LENGTH);
            }
            if (!array_key_exists(self::text($line['route'] ?? null), self::ROUTES)) {
                $errors['route'] = 'choisissez la voie d\'administration.';
            }
        } elseif ($kind === self::KIND_CARE) {
            if (self::text($line['idActivityDefinition'] ?? null) === '') {
                $errors['idActivityDefinition'] = 'choisissez un soin.';
            }
        } else {
            return ['kind' => 'ce n\'est ni un médicament ni un soin.'];
        }

        // RG3 : une fois, toutes les N heures ou tous les N jours ; début obligatoire, arrêt facultatif.
        $frequencyType = self::text($line['frequencyType'] ?? null);
        if (!array_key_exists($frequencyType, self::FREQUENCY_TYPES)) {
            $errors['frequencyType'] = 'la fréquence est obligatoire.';
        } elseif ($frequencyType === 'SCHEDULED' && self::hours($line['frequencyHours'] ?? null) === null) {
            $errors['frequencyHours'] = sprintf(
                'indiquez toutes les combien d\'heures (nombre entier de 1 à %d).',
                self::FREQUENCY_MAX_HOURS
            );
        } elseif ($frequencyType === 'DAYS' && self::days($line['frequencyDays'] ?? null) === null) {
            $errors['frequencyDays'] = sprintf(
                'indiquez tous les combien de jours (nombre entier de 1 à %d).',
                self::FREQUENCY_MAX_DAYS
            );
        }

        $startText = self::text($line['startDate'] ?? null);
        $start = self::date($startText);
        if ($startText === '') {
            $errors['startDate'] = 'la date de début est obligatoire (RG3).';
        } elseif ($start === null) {
            $errors['startDate'] = 'la date de début est invalide.';
        }

        $endText = self::text($line['endDate'] ?? null);
        if ($endText !== '') {
            $end = self::date($endText);
            if ($end === null) {
                $errors['endDate'] = 'la date d\'arrêt est invalide.';
            } elseif ($frequencyType === 'ONCE') {
                $errors['endDate'] = 'une prescription « Une fois » n\'a pas de date d\'arrêt.';
            } elseif ($start !== null && $end < $start) {
                $errors['endDate'] = 'l\'arrêt doit être après le début.';
            }
        }

        // Textes facultatifs : libellé affiché et longueur de la colonne.
        $texts = [
            'timeConstraint' => ['Contrainte horaire', self::TEXT_MAX_LENGTH],
            'important' => ['Important', self::TEXT_MAX_LENGTH],
            'nurseInstructions' => ['Consignes pour IDE', self::TEXT_MAX_LENGTH],
            'maxDose' => ['Dose max', self::DOSE_MAX_LENGTH],
            'dilutionVolume' => ['Volume de dilution', self::DOSE_MAX_LENGTH],
            'solvent' => ['Solvant', self::TEXT_MAX_LENGTH],
            'administrationDuration' => ['Durée d\'administration', self::DOSE_MAX_LENGTH],
            'antibioticReason' => ['Motif (si antibiotique)', self::TEXT_MAX_LENGTH],
            'germ' => ['Germe (si antibiotique)', self::TEXT_MAX_LENGTH],
            'renalAdjustment' => ['Adaptation rénale', self::TEXT_MAX_LENGTH],
            'adjustmentCriterion' => ['Critère d\'adaptation', self::TEXT_MAX_LENGTH],
            'offGhsReason' => ['Motif (si hors GHS)', self::TEXT_MAX_LENGTH],
        ];
        foreach ($texts as $field => [$label, $maxLength]) {
            if (mb_strlen(self::text($line[$field] ?? null)) > $maxLength) {
                $errors[$field] = sprintf('« %s » dépasse %d caractères.', $label, $maxLength);
            }
        }

        return $errors;
    }

    /**
     * Ligne de soin ou de médicament, selon sa nature. Saisie déjà contrôlée par checkLine().
     *
     * @param array<string, mixed> $line
     */
    private static function buildLine(array $line, string $idActivityDefinition): RequestLine
    {
        $start = self::date(self::text($line['startDate']));
        $end = self::date(self::text($line['endDate'] ?? null));
        $frequencyHours = match (self::text($line['frequencyType'])) {
            'SCHEDULED' => self::hours($line['frequencyHours']),
            'DAYS' => self::days($line['frequencyDays']) * 24,
            default => null,
        };

        if (self::text($line['kind']) === self::KIND_CARE) {
            return new ServiceRequest(
                id: null,
                idActivityDefinition: $idActivityDefinition,
                startDate: $start,
                endDate: $end,
                frequencyHours: $frequencyHours,
                timeConstraint: self::textOrNull($line['timeConstraint'] ?? null)
            );
        }

        return new MedicationRequest(
            id: null,
            idActivityDefinition: $idActivityDefinition,
            idMedication: self::text($line['idMedication']),
            dose: self::text($line['dose']),
            route: self::text($line['route']),
            startDate: $start,
            endDate: $end,
            frequencyHours: $frequencyHours,
            important: self::textOrNull($line['important'] ?? null),
            nurseInstructions: self::textOrNull($line['nurseInstructions'] ?? null),
            maxDose: self::textOrNull($line['maxDose'] ?? null),
            dilutionVolume: self::textOrNull($line['dilutionVolume'] ?? null),
            solvent: self::textOrNull($line['solvent'] ?? null),
            administrationDuration: self::textOrNull($line['administrationDuration'] ?? null),
            antibioticReason: self::textOrNull($line['antibioticReason'] ?? null),
            germ: self::textOrNull($line['germ'] ?? null),
            renalAdjustment: self::textOrNull($line['renalAdjustment'] ?? null),
            adjustmentCriterion: self::textOrNull($line['adjustmentCriterion'] ?? null),
            offGhsReason: self::textOrNull($line['offGhsReason'] ?? null)
        );
    }

    /**
     * Une ligne du tableau des prescriptions, prête pour la vue (style de la figure 10 :
     * « [IV] Paracétamol (Solution injectable) 1 g »). Les valeurs sont les textes affichés.
     *
     * @param array<string, string> $medications idMedication => libellé
     * @param array<string, string> $activityDefinitions idActivityDefinition => libellé
     * @param array<string, Practitioner> $practitioners indexés par idPractitioner
     * @return array<string, mixed>
     */
    public static function presentLine(
        CarePlan $carePlan,
        RequestLine $line,
        array $medications,
        array $activityDefinitions,
        array $practitioners
    ): array {
        $activity = $activityDefinitions[$line->getIdActivityDefinition()] ?? $line->getIdActivityDefinition();
        if ($line instanceof MedicationRequest) {
            $label = sprintf(
                '[%s] %s %s',
                $line->getRoute(),
                $medications[$line->getIdMedication()] ?? $line->getIdMedication(),
                $line->getDose()
            );
        } else {
            $label = $activity;
        }

        $validation = null;
        if ($carePlan->isValidated()) {
            $validation = sprintf(
                '%s, le %s',
                self::name($practitioners, $carePlan->getIdValidator()),
                $carePlan->getValidatedAt()?->format('d/m/Y H:i')
            );
        }
        $stop = null;
        if ($line->isStopped()) {
            $stop = sprintf(
                '%s, le %s',
                self::name($practitioners, $line->getIdStoppedBy()),
                $line->getStoppedAt()?->format('d/m/Y H:i')
            );
        }

        return [
            'idCarePlan' => $carePlan->getIdCarePlan(),
            // idServiceRequest ou idMedicationRequest selon isMedication (bouton « Arrêter »).
            'idLine' => $line->getId(),
            'isMedication' => $line instanceof MedicationRequest,
            'activity' => $activity,
            'label' => $label,
            'frequency' => self::frequencyLabel($line->getFrequencyHours()),
            'startDate' => $line->getStartDate()->format('d/m/Y H:i'),
            'endDate' => $line->getEndDate()?->format('d/m/Y H:i'),
            'timeConstraint' => $line->getTimeConstraint(),
            'important' => $line instanceof MedicationRequest ? $line->getImportant() : null,
            'nurseInstructions' => $line instanceof MedicationRequest ? $line->getNurseInstructions() : null,
            'maxDose' => $line instanceof MedicationRequest ? $line->getMaxDose() : null,
            'dilutionVolume' => $line instanceof MedicationRequest ? $line->getDilutionVolume() : null,
            'solvent' => $line instanceof MedicationRequest ? $line->getSolvent() : null,
            'administrationDuration' => $line instanceof MedicationRequest ? $line->getAdministrationDuration() : null,
            'antibioticReason' => $line instanceof MedicationRequest ? $line->getAntibioticReason() : null,
            'germ' => $line instanceof MedicationRequest ? $line->getGerm() : null,
            'renalAdjustment' => $line instanceof MedicationRequest ? $line->getRenalAdjustment() : null,
            'adjustmentCriterion' => $line instanceof MedicationRequest ? $line->getAdjustmentCriterion() : null,
            'offGhsReason' => $line instanceof MedicationRequest ? $line->getOffGhsReason() : null,
            'mode' => self::MODE_LABELS[$carePlan->getMode()] ?? $carePlan->getMode(),
            'prescriber' => self::name($practitioners, $carePlan->getIdPrescriber()),
            'recordedAt' => $carePlan->getRecordedAt()?->format('d/m/Y H:i'),
            'status' => $carePlan->statusOf($line),
            'validation' => $validation,
            'stop' => $stop,
        ];
    }

    /**
     * Une ligne en une phrase, comme dans « Prescriptions non sauvegardées » : « [IV] Paracétamol
     * (Solution injectable) 1 g (Toutes les 6 h) -> consignes du 20/10/2026 08:00 au … ».
     *
     * @param array<string, mixed> $line vue-modèle de presentLine()
     */
    public static function summary(array $line): string
    {
        $text = sprintf('%s (%s)', $line['label'], $line['frequency']);
        foreach (['nurseInstructions', 'timeConstraint'] as $note) {
            if ($line[$note] !== null) {
                $text .= ' -> ' . $line[$note];
            }
        }
        $text .= ' du ' . $line['startDate'];
        if ($line['endDate'] !== null) {
            $text .= ' au ' . $line['endDate'];
        }

        return $text;
    }

    /** « Une fois », « Toutes les 6 h », « Une fois par jour », « Tous les 2 jours »… */
    public static function frequencyLabel(?int $hours): string
    {
        return match (true) {
            $hours === null => self::FREQUENCY_TYPES['ONCE'],
            $hours === 1 => 'Toutes les heures',
            $hours === 24 => 'Une fois par jour',
            $hours % 24 === 0 => sprintf('Tous les %d jours', intdiv($hours, 24)),
            default => sprintf('Toutes les %d h', $hours),
        };
    }

    /**
     * Soins proposés dans la liste « 2. » : tous les types de soin, sauf l'administration
     * médicamenteuse, qui se prescrit en choisissant un médicament.
     *
     * @param array<string, string> $activityDefinitions idActivityDefinition => name
     * @return array<string, string>
     */
    private static function careTypes(array $activityDefinitions): array
    {
        return array_filter($activityDefinitions, static fn(string $name) => $name !== self::MEDICATION_ACTIVITY);
    }

    /** @param array<string, Practitioner> $practitioners */
    private static function name(array $practitioners, ?string $idPractitioner): string
    {
        if ($idPractitioner === null) {
            return '';
        }

        return isset($practitioners[$idPractitioner])
            ? self::nameAndRole($practitioners[$idPractitioner])
            : $idPractitioner;
    }

    /** « Claire Morel (Médecin réanimateur) » */
    private static function nameAndRole(Practitioner $practitioner): string
    {
        $role = $practitioner->getRole();

        return $role !== null && $role !== ''
            ? sprintf('%s (%s)', $practitioner->getFullName(), $role)
            : $practitioner->getFullName();
    }

    /** Date saisie dans un champ datetime-local, ou null si vide ou invalide (ex. 2026-02-30T10:00). */
    private static function date(string $input): ?DateTimeImmutable
    {
        if ($input === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!' . self::INPUT_DATE_FORMAT, $input);

        return $date !== false && $date->format(self::INPUT_DATE_FORMAT) === $input ? $date : null;
    }

    /** « Toutes les N heures » : N entier de 1 à FREQUENCY_MAX_HOURS, sinon null. */
    private static function hours(mixed $input): ?int
    {
        return self::positiveInt($input, self::FREQUENCY_MAX_HOURS);
    }

    /** « Tous les N jours » : N entier de 1 à FREQUENCY_MAX_DAYS, sinon null. */
    private static function days(mixed $input): ?int
    {
        return self::positiveInt($input, self::FREQUENCY_MAX_DAYS);
    }

    /** Entier écrit en chiffres, de 1 à $max, sinon null (« 1e1 », « 06 », « -2 » refusés). */
    private static function positiveInt(mixed $input, int $max): ?int
    {
        $text = self::text($input);
        if (preg_match('/^[1-9][0-9]{0,4}$/', $text) !== 1 || (int) $text > $max) {
            return null;
        }

        return (int) $text;
    }

    /** Texte facultatif : null s'il est vide (colonne NULL en base). */
    private static function textOrNull(mixed $value): ?string
    {
        $text = self::text($value);

        return $text !== '' ? $text : null;
    }

    /** Valeur saisie nettoyée ; une valeur non textuelle (formulaire forgé) compte comme vide. */
    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return '';
    }
}
