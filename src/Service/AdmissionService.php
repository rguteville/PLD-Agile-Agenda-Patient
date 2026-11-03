<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\AllergyIntolerance;
use Aura\Domain\AuditEvent;
use Aura\Domain\Patient;
use Aura\Domain\RecordFields;
use Aura\Domain\EpisodeOfCare;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PDOException;

/**
 * Règles métier de l'admission d'un patient (AURA-10).
 *
 * AURA-10.2 : contrôle serveur des champs. La validation est pure : aucun accès
 * à la base, aucune dépendance PDO, aucune sortie HTTP.
 * AURA-10.4 : admettre() enregistre le patient (ou le retrouve par son IPP) et
 * ouvre son séjour (numéro généré, AURA-10.3) dans une seule transaction.
 * PR « admission-champs-complets » : les champs facultatifs du catalogue RecordFields
 * (antécédents, histoire de la maladie, scores, administratif), le service hospitalier et
 * les allergies choisies dans le référentiel sont validés et enregistrés dans la même
 * transaction. Leur règle de validation vit dans FieldValidationService.
 * Retours des médecins du 2026-11-03 : médecin référent du séjour (responsable de soin), facultatif
 * (« à désigner » pour une admission de garde), choisi dans la liste des médecins.
 */
class AdmissionService
{
    /** Valeurs de `sexe` acceptées (colonne Patient.sexe VARCHAR(1), cf. database/test.sql). */
    public const ALLOWED_SEXES = ['M', 'F'];

    /** Format attendu pour la date de naissance (AAAA-MM-JJ). */
    public const FORMAT_DATE = 'Y-m-d';

    /** IPP : 6 à 9 chiffres (colonne Patient.ipp INT avec CHECK entre 100000 et 999999999). */
    public const IPP_PATTERN = '/^\d{6,9}$/';

    /** Plus grande taille (cm) ou plus grand poids (kg) enregistrable : colonnes Sejour DECIMAL(5,2). */
    public const MEASURE_MAX = 999.99;

    /** Longueur maximale des précautions, en caractères (colonne Sejour.precautions VARCHAR(500)). */
    public const PRECAUTIONS_MAX_LENGTH = 500;

    /** AURA-12.3 : IPP saisi qui appartient à un patient supprimé (il reste réservé). */
    public const REMOVED_PATIENT_IPP_MESSAGE =
        'Cet IPP appartient à un patient supprimé de la liste : il ne peut pas être réutilisé.';

    private FieldValidationService $fields;

    /**
     * @param DateTimeImmutable|null $aujourdhui date de référence (injectable pour les tests)
     * @param PatientDao|null $patientDao accès aux patients, requis par admettre() seulement
     * @param EpisodeOfCareService|null $sejourService ouverture du séjour, requis par admettre() seulement
     * @param Transaction|null $transaction patient + séjour validés ensemble, requis par admettre() seulement
     * @param AllergyIntoleranceDao|null $allergieDao enregistrement des allergies choisies à l'admission (facultatif)
     * @param AuditEventDao|null $auditEventDao trace du rattachement d'un séjour à un patient existant
     * @param RecordEditService|null $recordEditService mise à jour tracée, champ par champ, d'un patient existant
     */
    public function __construct(
        private ?DateTimeImmutable $today = null,
        private ?PatientDao $patientDao = null,
        private ?EpisodeOfCareService $episodeOfCareService = null,
        private ?Transaction $transaction = null,
        private ?AllergyIntoleranceDao $allergyIntoleranceDao = null,
        private ?AuditEventDao $auditEventDao = null,
        private ?RecordEditService $recordEditService = null
    ) {
        $this->fields = new FieldValidationService($today);
    }

    /**
     * Admet un patient : validation, puis insertion du patient (ou réutilisation du
     * patient déjà connu sous cet IPP) et ouverture de son séjour, le tout dans une
     * transaction. Si le séjour est refusé (patient déjà hospitalisé, lit occupé…),
     * rien n'est écrit : aucun patient orphelin.
     *
     * Taille, poids et précautions sont des mesures du séjour (AURA-10.1) : elles sont
     * enregistrées sur le séjour ouvert, pas sur le patient.
     *
     * Nouveau patient sans IPP saisi : un IPP est attribué automatiquement (PatientDao::nextIpp()).
     *
     * Patient existant, deux cas :
     *  - sélectionné dans la recherche (clé idPatient, PR « admission-recherche-patient ») : le
     *    formulaire a été pré-rempli depuis sa fiche ; identité et champs complémentaires modifiés
     *    sont enregistrés sur la fiche existante et tracés champ par champ (AuditEvent), y compris
     *    un champ vidé ;
     *  - retrouvé par son IPP sans sélection : son identité n'est pas modifiée, les champs
     *    complémentaires saisis non vides complètent son dossier (les vides ne l'effacent pas).
     * Dans les deux cas, le rattachement du séjour au patient est tracé (mode de recherche) et
     * les allergènes choisis qu'il n'a pas encore sont ajoutés, les autres ignorés.
     *
     * @param array<string, mixed> $donnees clés du domaine (voir valider())
     * @param array<string, array<string, string>> $referenceDataSets voir valider()
     * @return Sejour le séjour ouvert, avec son identifiant et son numéro
     * @throws InvalidArgumentException données invalides ou admission refusée (message affichable)
     * @throws LogicException si le service a été construit sans accès à la base
     */
    public function admit(array $data, array $referenceDataSets = []): EpisodeOfCare
    {
        if ($this->patientDao === null || $this->episodeOfCareService === null || $this->transaction === null) {
            throw new LogicException(
                'AdmissionService::admit() requiert PatientDao, EpisodeOfCareService et Transaction.'
            );
        }

        $errors = $this->validate($data, $referenceDataSets);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $patientDetails = $this->fields->normalize(RecordFields::admissionPatientFields(), $data);
        $patient = $this->buildPatient($data, $patientDetails);
        $weight = $this->measure($data['weight'] ?? null);
        $height = $this->measure($data['height'] ?? null);
        $precautions = $this->precautions($data['precautions'] ?? null);
        $idService = $this->text($data['idService'] ?? null);
        $idReferringPractitioner = $this->text($data['idReferringPractitioner'] ?? null);
        $episodeDetails = $this->fields->normalize(RecordFields::EPISODE, $data);
        $allergies = $this->allergens($data['allergies'] ?? null);
        $criticalities = $this->criticalities($data['allergyCriticalities'] ?? null);
        if ($allergies !== [] && $this->allergyIntoleranceDao === null) {
            throw new LogicException(
                'AdmissionService::admit() requiert AllergyIntoleranceDao pour enregistrer des allergies.'
            );
        }
        $selectedId = $this->text($data['idPatient'] ?? null);
        $selectedId = $selectedId !== '' ? (int) $selectedId : null;
        $searchMode = $this->text($data['searchMode'] ?? null);
        $patientDao = $this->patientDao;
        $episodeOfCareService = $this->episodeOfCareService;
        $allergyIntoleranceDao = $this->allergyIntoleranceDao;

        // Deux admissions simultanées se disputent les mêmes verrous (MAX(...) FOR UPDATE sur Patient) :
        // MariaDB interrompt l'une sur interblocage (1213) ou la contrainte UNIQUE la refuse (1062).
        // Rien n'a été écrit (transaction annulée) : on rejoue l'admission, au plus ADMISSION_ATTEMPTS fois.
        for ($attempt = 1;; $attempt++) {
            try {
                return $this->runAdmission(
                    $patient,
                    $patientDao,
                    $episodeOfCareService,
                    $allergyIntoleranceDao,
                    $weight,
                    $height,
                    $precautions,
                    $idService,
                    $patientDetails,
                    $episodeDetails,
                    $allergies,
                    $criticalities,
                    $selectedId,
                    $searchMode,
                    $idReferringPractitioner
                );
            } catch (PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                $retryable = in_array($code, [self::ERROR_DEADLOCK, self::ERROR_DUPLICATE], true);
                if (!$retryable || $attempt >= self::ADMISSION_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /** Codes MariaDB rejouables : interblocage, doublon sur une clé unique. */
    private const ERROR_DEADLOCK = 1213;
    private const ERROR_DUPLICATE = 1062;
    private const ADMISSION_ATTEMPTS = 3;

    /**
     * Écritures de l'admission dans une transaction (voir admettre()).
     *
     * @param array<string, ?string> $patientDetails
     * @param array<string, ?string> $episodeDetails
     * @param list<string> $allergies
     * @param array<string, string> $criticites
     */
    private function runAdmission(
        Patient $patient,
        PatientDao $patientDao,
        EpisodeOfCareService $episodeOfCareService,
        ?AllergyIntoleranceDao $allergyIntoleranceDao,
        ?float $weight,
        ?float $height,
        ?string $precautions,
        string $idService,
        array $patientDetails,
        array $episodeDetails,
        array $allergies,
        array $criticalities,
        ?int $selectedId,
        string $searchMode,
        string $idReferringPractitioner
    ): EpisodeOfCare {
        return $this->transaction->run(
            function () use (
                $patient,
                $patientDao,
                $episodeOfCareService,
                $allergyIntoleranceDao,
                $weight,
                $height,
                $precautions,
                $idService,
                $patientDetails,
                $episodeDetails,
                $allergies,
                $criticalities,
                $selectedId,
                $searchMode,
                $idReferringPractitioner
            ): EpisodeOfCare {
                $rattachement = null;
                if ($selectedId !== null) {
                    $existing = $patientDao->findById($selectedId);
                    if ($existing === null) {
                        throw new InvalidArgumentException(
                            'Le patient sélectionné est introuvable : relancez la recherche.'
                        );
                    }
                    $idPatient = $selectedId;
                    $this->updateSelectedPatient($existing, $patient, $patientDetails);
                    $rattachement = $searchMode === PatientSearchService::MODE_IPP
                        ? 'rattachement après recherche par IPP'
                        : 'rattachement après recherche par nom';
                } else {
                    $existing = $patient->getIpp() !== null ? $patientDao->findByIpp($patient->getIpp()) : null;
                    $removedOwner = $existing === null && $patient->getIpp() !== null
                        && $patientDao->existsByIpp($patient->getIpp());
                    if ($removedOwner) {
                        // AURA-12.3 : IPP d'un patient supprimé, toujours réservé (colonne UNIQUE).
                        throw new InvalidArgumentException(self::REMOVED_PATIENT_IPP_MESSAGE);
                    }
                    if ($existing !== null) {
                        $idPatient = (int) $existing->getIdPatient();
                        $this->completePatient(
                            $existing,
                            array_filter($patientDetails, static fn (?string $v): bool => $v !== null)
                        );
                        $rattachement = 'rattachement par IPP saisi';
                    } else {
                        // Nouveau patient sans IPP saisi : un IPP est attribué automatiquement (retours
                        // du 2026-11-02), dans la même transaction que l'insertion.
                        $generatedIpp = null;
                        if ($patient->getIpp() === null) {
                            try {
                                $generatedIpp = (string) $patientDao->nextIpp();
                            } catch (LogicException) {
                                throw new InvalidArgumentException(
                                    'Aucun IPP ne peut être attribué automatiquement : saisissez l\'IPP du patient.'
                                );
                            }
                            $patient = self::withIpp($patient, $generatedIpp);
                        }
                        $idPatient = $patientDao->insert($patient);
                        if ($generatedIpp !== null) {
                            // Traçabilité : cet IPP vient d'AURA, pas du système d'identité de l'hôpital.
                            $this->auditEventDao?->insert(new AuditEvent(
                                null,
                                AuditEvent::ENTITY_PATIENT,
                                $idPatient,
                                'ipp',
                                null,
                                $generatedIpp,
                                AuditEvent::AUTHOR_UNKNOWN,
                                detail: 'IPP attribué automatiquement par AURA à l\'admission'
                            ));
                        }
                    }
                }

                $episodeOfCare = $episodeOfCareService->openEpisodeOfCare(
                    $idPatient,
                    weight: $weight,
                    height: $height,
                    precautions: $precautions,
                    idService: $idService !== '' ? $idService : null,
                    details: $episodeDetails,
                    idReferringPractitioner: $idReferringPractitioner !== '' ? $idReferringPractitioner : null
                );

                if ($rattachement !== null) {
                    $this->auditEventDao?->insert(new AuditEvent(
                        null,
                        AuditEvent::ENTITY_EPISODE,
                        (int) $episodeOfCare->getIdEpisodeOfCare(),
                        'idPatient',
                        null,
                        (string) $idPatient,
                        AuditEvent::AUTHOR_UNKNOWN,
                        detail: $rattachement
                    ));
                }

                if ($allergies !== [] && $allergyIntoleranceDao !== null) {
                    $knownOnes = [];
                    foreach ($allergyIntoleranceDao->findByPatient($idPatient) as $known) {
                        $knownOnes[$known->getIdSubstance()] = $known;
                    }
                    foreach ($allergies as $idSubstance) {
                        $criticality = $criticalities[$idSubstance] ?? null;
                        if (!isset($knownOnes[$idSubstance])) {
                            $allergyIntoleranceDao->insert(
                                new AllergyIntolerance(null, $idPatient, $idSubstance, '', $criticality)
                            );
                            continue;
                        }
                        // Allergie déjà connue : une criticité choisie différente de celle enregistrée est
                        // appliquée et tracée (jamais ignorée en silence). Une criticité laissée vide ne
                        // remplace pas une criticité connue.
                        $known = $knownOnes[$idSubstance];
                        $before = $known->getCriticality() !== null && trim($known->getCriticality()) !== ''
                            ? trim($known->getCriticality())
                            : null;
                        if ($criticality !== null && $criticality !== $before) {
                            $allergyIntoleranceDao->updateCriticality(
                                (int) $known->getIdAllergyIntolerance(),
                                $idPatient,
                                $criticality
                            );
                            $label = trim($known->getSubstance());
                            $this->auditEventDao?->insert(new AuditEvent(
                                null,
                                AuditEvent::ENTITY_PATIENT,
                                $idPatient,
                                'allergyCriticality',
                                $label . ' : ' . ($before ?? 'non évaluée'),
                                $label . ' : ' . $criticality,
                                AuditEvent::AUTHOR_UNKNOWN,
                                detail: 'modifié à l\'admission'
                            ));
                        }
                    }
                }

                return $episodeOfCare;
            }
        );
    }

    /**
     * Patient sélectionné dans la recherche : identité modifiée enregistrée et tracée champ par
     * champ, puis champs complémentaires (y compris vidés) par completerPatient().
     *
     * @param array<string, ?string> $details
     * @throws InvalidArgumentException si l'IPP saisi appartient à un autre patient
     */
    private function updateSelectedPatient(Patient $existing, Patient $entered, array $details): void
    {
        $idPatient = (int) $existing->getIdPatient();
        $before = self::identity($existing);
        $after = self::identity($entered);
        $changes = array_filter(
            $after,
            static fn (?string $v, string $k): bool => $v !== $before[$k],
            ARRAY_FILTER_USE_BOTH
        );

        if ($changes !== []) {
            if (isset($changes['ipp']) && $changes['ipp'] !== null) {
                // Patients supprimés compris (AURA-12.3) : leur IPP reste réservé.
                $carrier = $this->patientDao?->ippOwnerId($changes['ipp']);
                if ($carrier !== null && $carrier !== $idPatient) {
                    throw new InvalidArgumentException('Cet IPP est déjà utilisé par un autre patient.');
                }
            }
            $this->patientDao?->update(new Patient(
                idPatient: $idPatient,
                ipp: $entered->getIpp(),
                lastName: $entered->getLastName(),
                firstName: $entered->getFirstName(),
                birthDate: $entered->getBirthDate(),
                sex: $entered->getSex(),
                phone: $existing->getPhone(),
                email: $existing->getEmail(),
                details: $existing->getDetails()
            ));
            foreach ($changes as $field => $value) {
                $this->auditEventDao?->insert(new AuditEvent(
                    null,
                    AuditEvent::ENTITY_PATIENT,
                    $idPatient,
                    self::IDENTITY_COLUMNS[$field],
                    $before[$field],
                    $value,
                    AuditEvent::AUTHOR_UNKNOWN,
                    detail: 'modifié à l\'admission'
                ));
            }
        }

        $this->completePatient($existing, $details);
    }

    /** Copie du patient avec l'IPP attribué (l'entité est immuable). */
    private static function withIpp(Patient $p, string $ipp): Patient
    {
        return new Patient(
            idPatient: $p->getIdPatient(),
            ipp: $ipp,
            lastName: $p->getLastName(),
            firstName: $p->getFirstName(),
            birthDate: $p->getBirthDate(),
            sex: $p->getSex(),
            phone: $p->getPhone(),
            email: $p->getEmail(),
            details: $p->getDetails()
        );
    }

    /** Colonnes SQL des champs d'identité, pour la trace (AuditEvent.field). */
    private const IDENTITY_COLUMNS = [
        'lastName' => 'lastName',
        'firstName' => 'firstName',
        'ipp' => 'ipp',
        'birthDate' => 'birthDate',
        'sex' => 'sex',
    ];

    /** @return array<string, ?string> identité comparable (clés du domaine) */
    private static function identity(Patient $p): array
    {
        return [
            'lastName' => trim($p->getLastName()),
            'firstName' => trim($p->getFirstName()),
            'ipp' => $p->getIpp(),
            'birthDate' => $p->getBirthDate()?->format(self::FORMAT_DATE),
            'sex' => $p->getSex(),
        ];
    }

    /** Champs d'un patient connu que l'admission ne modifie pas une fois renseignés : clé => libellé. */
    private const LOCKED_FOR_KNOWN_PATIENT = ['bloodType' => 'groupe sanguin'];

    /**
     * Champs complémentaires d'un patient existant : chaque valeur différente de la fiche est
     * enregistrée et tracée par RecordEditService ; sans ce service (tests), écriture directe.
     *
     * @param array<string, ?string> $details
     */
    private function completePatient(Patient $existing, array $details): void
    {
        $idPatient = (int) $existing->getIdPatient();
        // Retours du 2026-11-04 : une donnée vitale déjà connue (groupe sanguin) ne change pas à l'admission ;
        // elle se corrige dans le dossier par « Modifier », validée par un médecin et tracée (RecordFields,
        // validatedByDoctor). Vide, elle peut être complétée ici.
        foreach (self::LOCKED_FOR_KNOWN_PATIENT as $field => $label) {
            $known = trim((string) $existing->getDetail($field));
            $entered = trim((string) ($details[$field] ?? ''));
            if ($known !== '' && $entered !== '' && $entered !== $known) {
                throw new InvalidArgumentException(sprintf(
                    'Le %s de ce patient est déjà connu : il se modifie dans son dossier, validé par un médecin, '
                    . 'pas à l\'admission.',
                    $label
                ));
            }
            if ($known !== '') {
                unset($details[$field]);
            }
        }
        if ($this->recordEditService === null) {
            $this->patientDao?->updateDetails($idPatient, $details);
            return;
        }
        foreach ($details as $field => $value) {
            $result = $this->recordEditService->update(
                RecordEditService::ENTITY_PATIENT,
                $idPatient,
                $field,
                $value,
                $existing->getDetail($field),
                AuditEvent::AUTHOR_UNKNOWN,
                // Seuls les champs verrouillés ci-dessus (groupe sanguin) se complètent ainsi ; le décès, jamais.
                completeEmpty: isset(self::LOCKED_FOR_KNOWN_PATIENT[$field])
            );
            $status = $result['status'];
            if ($status !== RecordEditService::STATUS_SAVED && $status !== RecordEditService::STATUS_UNCHANGED) {
                // Rien ne doit passer inaperçu dans la transaction d'admission : elle est annulée.
                throw new InvalidArgumentException(
                    (string) ($result['message'] ?? 'La mise à jour du dossier existant a été refusée.')
                );
            }
        }
    }

    /**
     * Criticité choisie par allergène (idSubstance => « Faible » | « Élevée ») ; vide ou absente =
     * non évaluée (null en base). Valeurs déjà contrôlées par valider().
     *
     * @return array<string, string>
     */
    private function criticalities(mixed $value): array
    {
        $criticalities = [];
        foreach (is_array($value) ? $value : [] as $id => $criticality) {
            if (is_string($criticality) && trim($criticality) !== '') {
                $criticalities[(string) $id] = trim($criticality);
            }
        }

        return $criticalities;
    }

    /**
     * Identifiants d'allergènes choisis (liste à cases), dédoublonnés, sans valeur vide ni
     * non textuelle. Une valeur scalaire unique est acceptée (formulaire sans JavaScript).
     *
     * @return list<string>
     */
    private function allergens(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $items = is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($items as $id) {
            if (is_string($id) && trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Mesure validée (taille ou poids) arrondie au centième, comme la stockera la colonne
     * DECIMAL(5,2) : la valeur renvoyée est celle qui sera relue. Vide = null.
     */
    private function measure(mixed $value): ?float
    {
        $text = $this->text($value);

        return $text !== '' ? round((float) $text, 2) : null;
    }

    /** Précautions nettoyées, fins de ligne normalisées en \n. Vide = null. */
    private function precautions(mixed $value): ?string
    {
        $text = str_replace("\r\n", "\n", $this->text($value));

        return $text !== '' ? $text : null;
    }

    /**
     * Construit l'entité à partir de données validées (champs vides = null).
     *
     * @param array<string, mixed> $donnees
     * @param array<string, ?string> $details champs complémentaires normalisés (RecordFields::PATIENT)
     */
    private function buildPatient(array $data, array $details = []): Patient
    {
        $ipp = $this->text($data['ipp'] ?? null);
        $birthDate = $this->text($data['birthDate'] ?? null);
        $sex = $this->text($data['sex'] ?? null);

        return new Patient(
            idPatient: null,
            ipp: $ipp !== '' ? $ipp : null,
            lastName: $this->text($data['lastName'] ?? null),
            firstName: $this->text($data['firstName'] ?? null),
            birthDate: $birthDate !== ''
                ? DateTimeImmutable::createFromFormat('!' . self::FORMAT_DATE, $birthDate) ?: null
                : null,
            sex: $sex !== '' ? $sex : null,
            details: $details
        );
    }

    /**
     * Valide les données d'identité du patient (RG1 : nom et prénom obligatoires, IPP facultatif).
     *
     * Clés attendues (celles du domaine) : nom, prenom, ipp, dateNaissance, sexe.
     * Une clé absente est traitée comme vide. Les messages ne reprennent jamais la valeur saisie.
     *
     * @param array<string, mixed> $donnees
     * @return array<string, string> erreurs indexées par champ ; [] si les données sont valides
     */
    public function validatePatientIdentity(array $data): array
    {
        $errors = [];

        if ($this->text($data['lastName'] ?? null) === '') {
            $errors['lastName'] = 'Le nom est obligatoire.';
        }
        if ($this->text($data['firstName'] ?? null) === '') {
            $errors['firstName'] = 'Le prénom est obligatoire.';
        }

        $ippError = self::validateIpp($this->text($data['ipp'] ?? null));
        if ($ippError !== null) {
            $errors['ipp'] = $ippError;
        }

        $birthDate = $this->text($data['birthDate'] ?? null);
        if ($birthDate !== '') {
            $dateError = $this->validateBirthDate($birthDate);
            if ($dateError !== null) {
                $errors['birthDate'] = $dateError;
            }
        }

        $sex = $this->text($data['sex'] ?? null);
        if ($sex !== '' && !in_array($sex, self::ALLOWED_SEXES, true)) {
            $errors['sex'] = 'Le sexe doit être M ou F.';
        }

        return $errors;
    }

    /**
     * Valide les données d'admission (RG1 : nom et prénom obligatoires, IPP facultatif).
     *
     * Clés attendues (celles du domaine) : nom, prenom, ipp, dateNaissance, sexe,
     * taille, poids, precautions, idService, idReferringPractitioner (médecin référent, facultatif),
     * allergies (liste d'identifiants d'allergènes),
     * allergyCriticalities (idSubstance => criticité de Allergie::CRITICALITIES ou vide)
     * et tous les champs facultatifs de RecordFields::PATIENT et ::EPISODE. Une clé absente
     * est traitée comme vide. Les messages ne reprennent jamais la valeur saisie (données de santé).
     *
     * @param array<string, mixed> $donnees
     * @param array<string, array<string, string>> $referenceDataSets listes autorisées, lues en base par
     *        l'appelant : 'services' (idHealthcareService => nom), 'substances' (idSubstance => libellé) et
     *        'doctors' (idPractitioner => « Dr Prénom Nom », médecins pouvant être référents).
     *        Une liste absente désactive le contrôle d'appartenance correspondant (tests unitaires).
     * @return array<string, string> erreurs indexées par champ ; [] si les données sont valides
     */
    public function validate(array $data, array $referenceDataSets = []): array
    {
        $errors = $this->validatePatientIdentity($data);
        $errors += $this->fields->validate(RecordFields::admissionPatientFields(), $data);
        $errors += $this->fields->validate(RecordFields::EPISODE, $data);

        $idService = $this->text($data['idService'] ?? null);
        if (
            $idService !== ''
            && isset($referenceDataSets['services'])
            && !isset($referenceDataSets['services'][$idService])
        ) {
            $errors['idService'] = 'Le service hospitalier choisi est inconnu.';
        }

        // Médecin référent : facultatif (« à désigner » en garde), mais toujours un médecin de la liste.
        $idReferringPractitioner = $this->text($data['idReferringPractitioner'] ?? null);
        if (
            $idReferringPractitioner !== ''
            && isset($referenceDataSets['doctors'])
            && !isset($referenceDataSets['doctors'][$idReferringPractitioner])
        ) {
            $errors['idReferringPractitioner'] = 'Le médecin référent choisi ne fait pas partie des médecins '
                . 'proposés.';
        }

        // Patient sélectionné dans la recherche : identifiant interne numérique (existence vérifiée à l'écriture).
        $idPatient = $this->text($data['idPatient'] ?? null);
        if ($idPatient !== '' && preg_match('/^[1-9][0-9]{0,17}$/', $idPatient) !== 1) {
            $errors['idPatient'] = 'Le patient sélectionné est invalide : relancez la recherche.';
        }

        $criticalities = $data['allergyCriticalities'] ?? [];
        if (!is_array($criticalities)) {
            $errors['allergyCriticalities'] = 'La criticité des allergies est invalide.';
        } else {
            foreach ($criticalities as $criticality) {
                $criticality = is_string($criticality) ? trim($criticality) : null;
                $unknownOne = $criticality !== '' && !in_array($criticality, AllergyIntolerance::CRITICALITIES, true);
                if ($criticality === null || $unknownOne) {
                    $errors['allergyCriticalities'] = 'Criticité d\'allergie inconnue : choisissez Faible, Élevée '
                        . 'ou non évaluée.';
                    break;
                }
            }
        }

        $allergies = $data['allergies'] ?? null;
        if ($allergies !== null && $allergies !== '' && !is_array($allergies) && !is_string($allergies)) {
            $errors['allergies'] = 'La liste des allergènes est invalide.';
        } elseif (isset($referenceDataSets['substances'])) {
            foreach ($this->allergens($allergies) as $idSubstance) {
                if (!isset($referenceDataSets['substances'][$idSubstance])) {
                    $errors['allergies'] = 'Un allergène choisi est inconnu du référentiel.';
                    break;
                }
            }
        }

        $height = $this->text($data['height'] ?? null);
        if ($height !== '' && !$this->isStrictlyPositive($height)) {
            $errors['height'] = 'La taille doit être un nombre strictement positif.';
        } elseif ($height !== '' && !$this->tientDansLaColonne($height)) {
            $errors['height'] = 'La taille doit être inférieure à 1000 cm.';
        }

        $weight = $this->text($data['weight'] ?? null);
        if ($weight !== '' && !$this->isStrictlyPositive($weight)) {
            $errors['weight'] = 'Le poids doit être un nombre strictement positif.';
        } elseif ($weight !== '' && !$this->tientDansLaColonne($weight)) {
            $errors['weight'] = 'Le poids doit être inférieur à 1000 kg.';
        }

        // precautions : texte libre facultatif, limité à la taille de la colonne.
        $precautions = $this->precautions($data['precautions'] ?? null);
        if ($precautions !== null && mb_strlen($precautions) > self::PRECAUTIONS_MAX_LENGTH) {
            $errors['precautions'] = sprintf(
                'Les précautions ne doivent pas dépasser %d caractères.',
                self::PRECAUTIONS_MAX_LENGTH
            );
        }

        return $errors;
    }

    /**
     * Message d'erreur si l'IPP saisi ne tient pas dans la colonne INT (6 à 9 chiffres), sinon null.
     * Un IPP vide est accepté : il est facultatif à l'admission (RG1). Partagé avec la recherche
     * par IPP (AURA-15) pour que les deux écrans appliquent la même règle.
     */
    public static function validateIpp(string $ipp): ?string
    {
        $ipp = trim($ipp);
        if ($ipp !== '' && preg_match(self::IPP_PATTERN, $ipp) !== 1) {
            return "L'IPP doit contenir entre 6 et 9 chiffres.";
        }

        return null;
    }

    /** Message d'erreur si la date n'est pas au format AAAA-MM-JJ ou n'est pas strictement passée, sinon null. */
    private function validateBirthDate(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::FORMAT_DATE, $value);
        $anomalies = DateTimeImmutable::getLastErrors();
        $withAnomalies = $anomalies !== false
            && ($anomalies['warning_count'] > 0 || $anomalies['error_count'] > 0);

        // Le contrôle aller-retour rejette les dates inexistantes (ex. 2026-13-45, 1990-02-30)
        // que createFromFormat accepterait en les décalant.
        if ($date === false || $withAnomalies || $date->format(self::FORMAT_DATE) !== $value) {
            return 'La date de naissance est invalide (format attendu : JJ/MM/AAAA).';
        }

        $today = ($this->today ?? new DateTimeImmutable())->setTime(0, 0);
        if ($date >= $today) {
            return 'La date de naissance doit être antérieure à la date du jour.';
        }

        return null;
    }

    private function isStrictlyPositive(string $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }

    /** Une fois arrondie au centième, la valeur tient dans DECIMAL(5,2) (sinon MariaDB la refuse). */
    private function tientDansLaColonne(string $value): bool
    {
        return round((float) $value, 2) <= self::MEASURE_MAX;
    }

    /**
     * Normalise une valeur saisie en chaîne nettoyée.
     * Les valeurs non scalaires (tableau envoyé par un formulaire forgé) sont traitées comme vides.
     */
    private function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
