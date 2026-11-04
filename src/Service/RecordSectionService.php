<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Core\DateInput;
use Aura\Dao\AppointmentDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\Transaction;
use Aura\Domain\AuditEvent;
use Aura\Domain\Patient;
use Aura\Domain\RecordFields;
use Aura\Domain\EpisodeOfCare;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;

/**
 * Modification d'une partie entière du dossier patient et fin de séjour (routes POST /sejour/partie/modifier
 * et /sejour/cloturer), déclaration du décès (POST /sejour/deces) : le décès termine le séjour en cours et
 * annule ses soins prévus après l'heure du décès.
 *
 * Chaque partie du dossier (le résumé « Dossier » avec l'identité, le séjour et les coordonnées, puis
 * Admission, Antécédents, Histoire de la maladie, Scores, Mesures, Administratif) se modifie d'un bloc,
 * en plus de l'édition champ par champ. Règles :
 *  - liste blanche des champs par partie (partFields()) ; jamais une clé envoyée par le client ;
 *  - validation existante : identité (AdmissionService), catalogue et mesures (RecordEditService),
 *    lit et box (EpisodeOfCareService, contrôle d'occupation) ;
 *  - concurrence optimiste : la valeur d'origine envoyée doit être celle en base ;
 *  - tout ou rien : la partie est écrite dans une transaction, annulée à la première erreur ;
 *  - traçabilité : chaque champ modifié écrit une ligne AuditEvent au nom du médecin qui valide.
 *
 * Les champs réservés au médecin (RecordFields, 'validatedByDoctor' : groupe sanguin, décès) ne se
 * modifient que par ce service : RecordEditService les refuse sans médecin authentifié.
 *
 * Médecin référent du séjour : modifiable dans la partie « Dossier »
 * et dans « Admission », comme le service ; contrôlé contre la liste des médecins (RecordEditService).
 *
 * AUTHENTIFICATION FACTICE : le médecin est choisi dans la liste des praticiens habilités (RG5) et le
 * mot de passe est exigé mais NON vérifié. Elle prépare l'authentification réelle (AURA-50) ; la
 * trace le dit explicitement (« authentification factice »).
 */
class RecordSectionService
{
    public const PART_SUMMARY = 'summary';

    public const STATUS_SAVED = 'saved';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_AUTH = 'auth';

    /** Identité et coordonnées du patient (partie « Dossier »), clé de formulaire => colonne tracée. */
    private const IDENTITY_FIELDS = [
        'lastName' => 'lastName',
        'firstName' => 'firstName',
        'birthDate' => 'birthDate',
        'sex' => 'sex',
        'ipp' => 'ipp',
        'phone' => 'phoneNumber',
        'email' => 'email',
    ];

    /** Téléphone : 10 chiffres (colonne Patient.phoneNumber VARCHAR(10)), espaces tolérés à la saisie. */
    private const PHONE_PATTERN = '/^\d{10}$/';
    private const EMAIL_MAX = 50;

    public function __construct(
        private PatientDao $patientDao,
        private EpisodeOfCareDao $episodeOfCareDao,
        private EpisodeOfCareService $episodeOfCareService,
        private RecordEditService $recordEditService,
        private AuditEventDao $auditEventDao,
        private Transaction $transaction,
        private ReferenceDataDao $referenceDataDao,
        private AdmissionService $admissionService = new AdmissionService(),
        private ?AppointmentDao $appointmentDao = null
    ) {
    }

    /**
     * Champs modifiables d'une partie : clé => ['entity' => patient|episode|identity|location, 'label',
     * 'type', 'options'?]. [] si la partie est inconnue.
     *
     * @param array<string, string> $services référentiel des services (id => nom)
     * @param array<string, string> $doctors médecins pouvant être référents (id => « Dr Prénom Nom »)
     * @return array<string, array<string, mixed>>
     */
    public static function partFields(string $part, array $services = [], array $doctors = []): array
    {
        if ($part === self::PART_SUMMARY) {
            return [
                'lastName' => [
                    'entity' => 'identity', 'label' => 'Nom', 'type' => 'text', 'max' => 50, 'required' => true,
                    'placeholder' => 'DUPONT',
                ],
                'firstName' => [
                    'entity' => 'identity', 'label' => 'Prénom', 'type' => 'text', 'max' => 50, 'required' => true,
                    'placeholder' => 'Marie',
                ],
                'birthDate' => ['entity' => 'identity', 'label' => 'Date de naissance', 'type' => 'date'],
                'sex' => [
                    'entity' => 'identity', 'label' => 'Sexe', 'type' => 'radio', 'options' => ['M' => 'M', 'F' => 'F'],
                ],
                'ipp' => ['entity' => 'identity',
                'label' => 'IPP',
                'type' => 'text',
                'placeholder' => '100000123'],
                'phone' => [
                    'entity' => 'identity',
                    'label' => 'Téléphone',
                    'type' => 'tel',
                    'placeholder' => '0612345678',
                ],
                'email' => [
                    'entity' => 'identity', 'label' => 'E-mail', 'type' => 'email', 'max' => 50,
                    'placeholder' => 'prenom.nom@exemple.fr',
                ],
                'idHealthcareService' => [
                    'entity' => 'episode', 'label' => 'Service', 'type' => 'select', 'options' => $services,
                ],
                'idReferringPractitioner' => self::referentField($doctors),
                'admissionReason' => ['entity' => 'episode'] + RecordFields::EPISODE['admissionReason'],
                'bedNumber' => [
                    'entity' => 'location',
                    'label' => 'Lit',
                    'type' => 'text',
                    'max' => 20,
                    'placeholder' => 'L01',
                ],
                'boxNumber' => [
                    'entity' => 'location',
                    'label' => 'Box',
                    'type' => 'text',
                    'max' => 20,
                    'placeholder' => 'B01',
                ],
                'precautions' => [
                    'entity' => 'episode', 'label' => 'Précautions', 'type' => 'textarea',
                    'max' => AdmissionService::PRECAUTIONS_MAX_LENGTH, 'placeholder' => 'Isolement, risque de chute…',
                ],
            ];
        }
        if (!isset(RecordFields::SECTIONS[$part])) {
            return [];
        }

        $fields = [];
        if ($part === 'admission') {
            $fields['admissionReason'] = ['entity' => 'episode'] + RecordFields::EPISODE['admissionReason'];
            $fields['idHealthcareService'] = [
                'entity' => 'episode', 'label' => 'Service hospitalier', 'type' => 'select', 'options' => $services,
            ];
            $fields['idReferringPractitioner'] = self::referentField($doctors);
        }
        if ($part === 'measures') {
            $fields['weight'] = [
                'entity' => 'episode', 'label' => 'Poids à l\'admission (kg)', 'type' => 'number',
                'placeholder' => '68,5',
            ];
            $fields['height'] = [
                'entity' => 'episode', 'label' => 'Taille (cm)', 'type' => 'number', 'placeholder' => '172',
            ];
        }
        foreach (RecordFields::PATIENT as $field => $spec) {
            if ($spec['section'] === $part) {
                $fields[$field] = ['entity' => 'patient'] + $spec;
            }
        }
        foreach (RecordFields::EPISODE as $field => $spec) {
            if ($spec['section'] === $part && !isset($fields[$field])) {
                $fields[$field] = ['entity' => 'episode'] + $spec;
            }
        }
        if ($part === 'admin') {
            $fields['precautions'] = [
                'entity' => 'episode', 'label' => 'Précautions', 'type' => 'textarea',
                'max' => AdmissionService::PRECAUTIONS_MAX_LENGTH, 'placeholder' => 'Isolement, risque de chute…',
            ];
        }

        return $fields;
    }

    /**
     * Médecin référent (responsable de soin) : liste des médecins, « À désigner » si vide.
     *
     * @param array<string, string> $doctors
     * @return array<string, mixed>
     */
    private static function referentField(array $doctors): array
    {
        return [
            'entity' => 'episode', 'label' => 'Médecin référent', 'type' => 'select', 'options' => $doctors,
            'empty' => 'À désigner',
            'help' => 'Le médecin de jour qui reprend le patient (après une admission de garde, notamment).',
        ];
    }

    /**
     * Valeurs actuelles d'une partie, au format des formulaires (dates en JJ/MM/AAAA).
     *
     * @return array<string, string>
     */
    public static function currentValues(string $part, Patient $patient, EpisodeOfCare $episodeOfCare): array
    {
        $values = [];
        foreach (array_keys(self::partFields($part)) as $field) {
            $values[$field] = DateInput::fromIso(self::read($field, $patient, $episodeOfCare));
        }

        return $values;
    }

    /**
     * Médecins habilités à valider (RG5), proposés dans la fenêtre d'authentification factice.
     *
     * @return array<string, string> idPractitioner => « Prénom Nom »
     */
    public function validators(): array
    {
        $list = [];
        foreach ($this->referenceDataDao->practitioners() as $id => $practitioner) {
            if ($practitioner->canValidate()) {
                $list[(string) $id] = trim($practitioner->getFirstName() . ' ' . $practitioner->getLastName());
            }
        }

        return $list;
    }

    /**
     * Enregistre une partie : valeurs nouvelles et d'origine indexées par clé de partFields(). Seuls les
     * champs changés sont traités ; la moindre erreur annule toute la partie. Si le décès passe à « Oui »
     * et que le séjour est en cours, le séjour se termine dans la même transaction (endStayAfterDeath()).
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $oldValues valeurs affichées au départ (concurrence optimiste)
     * @param DateTimeImmutable|null $deathTime heure du décès pour la fin de séjour (null : maintenant)
     * @return array{status: string, errors: array<string, string>, message: ?string} status = STATUS_*
     */
    public function updatePart(
        int $idEpisodeOfCare,
        string $part,
        array $values,
        array $oldValues,
        string $idValidator,
        string $password,
        ?DateTimeImmutable $deathTime = null
    ): array {
        $author = $this->authenticate($idValidator, $password);
        if ($author === null) {
            return self::result(self::STATUS_AUTH, ['auth' => self::AUTH_ERROR]);
        }
        $episodeOfCare = $this->episodeOfCareDao->findById($idEpisodeOfCare);
        $patient = $episodeOfCare === null ? null : $this->patientDao->findById($episodeOfCare->getIdPatient());
        if ($episodeOfCare === null || $patient === null) {
            return self::result(self::STATUS_NOT_FOUND, [], 'Dossier introuvable.');
        }
        $fields = self::partFields(
            $part,
            $this->referenceDataDao->healthcareServices(),
            $this->referenceDataDao->doctors()
        );
        if ($fields === []) {
            return self::result(self::STATUS_NOT_FOUND, [], 'Partie du dossier inconnue.');
        }

        // Seuls les champs de la partie, dont la valeur a changé, sont traités (dates JJ/MM/AAAA converties).
        $changes = [];
        foreach ($fields as $field => $spec) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $new = self::clean($values[$field] ?? null, $spec['type']);
            $old = self::clean($oldValues[$field] ?? null, $spec['type']);
            if ($new !== $old) {
                $changes[$field] = ['new' => $new, 'old' => $old, 'spec' => $spec];
            }
        }
        if ($changes === []) {
            return self::result(self::STATUS_SAVED);
        }

        try {
            $this->transaction->run(function () use ($changes, $patient, $episodeOfCare, $author, $deathTime): void {
                $errors = [];
                $conflict = false;
                $this->applyIdentity($changes, $patient, $author, $errors, $conflict);
                $this->applyLocation($changes, $episodeOfCare, $author, $errors, $conflict);
                foreach ($changes as $field => $change) {
                    $entity = $change['spec']['entity'];
                    if ($entity !== 'patient' && $entity !== 'episode') {
                        continue;
                    }
                    $result = $this->recordEditService->update(
                        $entity === 'patient' ? RecordEditService::ENTITY_PATIENT : RecordEditService::ENTITY_EPISODE,
                        $entity === 'patient'
                            ? (int) $patient->getIdPatient()
                            : (int) $episodeOfCare->getIdEpisodeOfCare(),
                        $field,
                        $change['new'],
                        $change['old'],
                        $author,
                        (int) $episodeOfCare->getIdEpisodeOfCare()
                    );
                    if ($result['status'] === RecordEditService::STATUS_CONFLICT) {
                        $conflict = true;
                        $errors[$field] = 'Modifié entre-temps par quelqu\'un d\'autre.';
                        continue;
                    }
                    $accepted = [RecordEditService::STATUS_SAVED, RecordEditService::STATUS_UNCHANGED];
                    if (!in_array($result['status'], $accepted, true)) {
                        $errors[$field] = (string) $result['message'];
                    }
                }
                if ($errors !== []) {
                    // Annule toute la partie (rien n'est écrit), puis renvoie les erreurs par champ.
                    throw new PartRejected($errors, $conflict);
                }
                // Déclarer le décès met fin au séjour en cours DU PATIENT, dans la même transaction, même si le
                // décès est déclaré depuis le dossier d'un séjour clos.
                if (($changes['deceased']['new'] ?? null) === '1') {
                    foreach ($this->episodeOfCareDao->findByPatient((int) $patient->getIdPatient()) as $stay) {
                        if ($stay->isOngoing()) {
                            $this->endStayAfterDeath((int) $stay->getIdEpisodeOfCare(), $deathTime, $author);
                        }
                    }
                }
            });
        } catch (PartRejected $e) {
            return $e->conflict
                ? self::result(
                    self::STATUS_CONFLICT,
                    $e->errors,
                    'Cette partie a été modifiée entre-temps : rechargez la page avant de recommencer.'
                )
                : self::result(self::STATUS_INVALID, $e->errors);
        }

        return self::result(self::STATUS_SAVED);
    }

    /**
     * Fin du séjour à la date et l'heure indiquées (JJ/MM/AAAA, HH:MM ; maintenant si vides), validée
     * par un médecin (authentification factice) et tracée.
     *
     * @return array{status: string, errors: array<string, string>, message: ?string}
     */
    public function endStay(
        int $idEpisodeOfCare,
        string $date,
        string $time,
        string $idValidator,
        string $password
    ): array {
        $author = $this->authenticate($idValidator, $password);
        if ($author === null) {
            return self::result(self::STATUS_AUTH, ['auth' => self::AUTH_ERROR]);
        }
        if ($this->episodeOfCareDao->findById($idEpisodeOfCare) === null) {
            return self::result(self::STATUS_NOT_FOUND, [], 'Séjour introuvable.');
        }

        $dischargeDate = self::parseDischarge($date, $time);
        if ($dischargeDate === false) {
            return self::result(self::STATUS_INVALID, [
                'dischargeDate' => 'Date ou heure de sortie invalide (JJ/MM/AAAA et HH:MM).',
            ]);
        }

        try {
            $this->transaction->run(function () use ($idEpisodeOfCare, $dischargeDate, $author): void {
                $closed = $this->episodeOfCareService->closeEpisodeOfCare($idEpisodeOfCare, $dischargeDate);
                $this->auditEventDao->insert(new AuditEvent(
                    null,
                    AuditEvent::ENTITY_EPISODE,
                    $idEpisodeOfCare,
                    'dischargeDate',
                    null,
                    $closed->getDischargeDate()?->format('d/m/Y H:i'),
                    $author,
                    detail: 'fin du séjour'
                ));
            });
        } catch (InvalidArgumentException $e) {
            return self::result(self::STATUS_INVALID, ['dischargeDate' => $e->getMessage()]);
        } catch (PDOException $e) {
            // 1020 (MariaDB 11.8) : un autre onglet vient de modifier ce séjour ; on ne plante pas.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1020) {
                throw $e;
            }

            return self::result(self::STATUS_CONFLICT, [], 'Ce séjour vient d\'être modifié : rechargez la page.');
        }

        return self::result(self::STATUS_SAVED);
    }

    /**
     * Déclaration du décès, validée par un médecin (authentification factice) et
     * tracée : le patient est déclaré décédé et, si le séjour est en cours, il se termine à l'heure du décès
     * (JJ/MM/AAAA, HH:MM ; maintenant si vides), ses soins prévus après cette heure sont annulés. Tout ou rien.
     * Heure du décès dans le futur ou avant l'admission : refusée avant toute écriture. Passe par
     * updatePart() sur le champ deceased de la partie « admin ».
     *
     * @param string $oldDeceased valeur du décès affichée au départ (concurrence optimiste)
     * @return array{status: string, errors: array<string, string>, message: ?string}
     */
    public function declareDeath(
        int $idEpisodeOfCare,
        string $date,
        string $time,
        string $oldDeceased,
        string $idValidator,
        string $password
    ): array {
        $deathTime = self::parseDischarge($date, $time);
        if ($deathTime === false) {
            return self::result(self::STATUS_INVALID, [
                'dischargeDate' => 'Date ou heure du décès invalide (JJ/MM/AAAA et HH:MM).',
            ]);
        }
        // Contrôlé avant toute écriture (la fin de séjour le refuserait aussi, après coup).
        $episodeOfCare = $this->episodeOfCareDao->findById($idEpisodeOfCare);
        if ($deathTime !== null && $deathTime > new DateTimeImmutable()) {
            return self::result(self::STATUS_INVALID, [
                'dischargeDate' => 'L\'heure du décès ne peut pas être dans le futur.',
            ]);
        }
        if (
            $deathTime !== null && $episodeOfCare !== null && $episodeOfCare->isOngoing()
            && $deathTime < $episodeOfCare->getAdmissionDate()
        ) {
            return self::result(self::STATUS_INVALID, [
                'dischargeDate' => 'L\'heure du décès ne peut pas précéder l\'admission.',
            ]);
        }

        return $this->updatePart(
            $idEpisodeOfCare,
            'admin',
            ['deceased' => '1'],
            ['deceased' => $oldDeceased],
            $idValidator,
            $password,
            $deathTime ?? new DateTimeImmutable()
        );
    }

    /**
     * Fin du séjour consécutive au décès, dans la transaction de la déclaration : sortie à l'heure du décès
     * (maintenant à défaut), tracée, puis annulation des soins encore prévus après cette heure.
     *
     * @throws PartRejected fin de séjour refusée par EpisodeOfCareService (toute la transaction est annulée)
     */
    private function endStayAfterDeath(int $idEpisodeOfCare, ?DateTimeImmutable $deathTime, string $author): void
    {
        try {
            $closed = $this->episodeOfCareService->closeEpisodeOfCare($idEpisodeOfCare, $deathTime);
        } catch (InvalidArgumentException $e) {
            throw new PartRejected(['dischargeDate' => $e->getMessage()], false);
        }
        $discharge = $closed->getDischargeDate() ?? new DateTimeImmutable();
        $cancelled = $this->appointmentDao?->cancelPlannedForEpisode(
            $idEpisodeOfCare,
            $discharge,
            'Séjour terminé : décès'
        ) ?? 0;
        $plural = $cancelled > 1 ? 's' : '';
        $this->auditEventDao->insert(new AuditEvent(
            null,
            AuditEvent::ENTITY_EPISODE,
            $idEpisodeOfCare,
            'dischargeDate',
            null,
            $discharge->format('d/m/Y H:i'),
            $author,
            detail: 'fin du séjour : décès déclaré' . ($cancelled > 0
                ? sprintf(', %d soin%s prévu%3$s annulé%3$s', $cancelled, $plural, $plural)
                : '')
        ));
    }

    /**
     * Date et heure saisies (JJ/MM/AAAA, HH:MM) : null si les deux sont vides (maintenant), false si invalides.
     */
    private static function parseDischarge(string $date, string $time): DateTimeImmutable|false|null
    {
        $date = trim($date);
        $time = trim($time);
        if ($date === '' && $time === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            DateInput::toIso($date) . ' ' . ($time !== '' ? $time : '00:00')
        );

        // Aller-retour sur la date ET l'heure : « 12:75 » ou « 31/02 » ne sont pas corrigés en silence.
        $expectedTime = $time !== '' ? $time : '00:00';

        return $parsed === false || $parsed->format('d/m/Y') !== $date || $parsed->format('H:i') !== $expectedTime
            ? false : $parsed;
    }

    private const AUTH_ERROR = 'Choisissez le médecin qui valide et saisissez son mot de passe.';

    /**
     * Auteur d'une modification en ligne du dossier : même authentification FACTICE que « Modifier » (un
     * médecin de la liste, mot de passe non vide, non vérifié). null si refusée.
     */
    public function inlineAuthor(string $idValidator, string $password): ?string
    {
        return $this->authenticate($idValidator, $password);
    }

    /**
     * Authentification FACTICE : un médecin habilité et un mot de passe non vide (non vérifié).
     * Renvoie l'auteur à tracer, ou null si refusée.
     */
    private function authenticate(string $idValidator, string $password): ?string
    {
        $validators = $this->validators();
        if (!isset($validators[$idValidator]) || trim($password) === '') {
            return null;
        }

        return mb_substr($validators[$idValidator] . ' (médecin, authentification factice)', 0, 100);
    }

    /**
     * Identité et coordonnées : validées comme à l'admission, contrôle de concurrence, IPP unique,
     * une seule mise à jour du patient, une trace par champ modifié.
     *
     * @param array<string, array{new: ?string, old: ?string, spec: array<string, mixed>}> $changes
     * @param array<string, string> $errors
     */
    private function applyIdentity(
        array $changes,
        Patient $patient,
        string $author,
        array &$errors,
        bool &$conflict
    ): void {
        $identity = array_intersect_key($changes, self::IDENTITY_FIELDS);
        if ($identity === []) {
            return;
        }
        $current = self::identityValues($patient);
        foreach ($identity as $field => $change) {
            if ($change['old'] !== $current[$field]) {
                $conflict = true;
                $errors[$field] = 'Modifié entre-temps par quelqu\'un d\'autre.';
            }
        }
        if ($conflict) {
            return;
        }

        $next = $current;
        foreach ($identity as $field => $change) {
            $next[$field] = $change['new'];
        }
        $identityErrors = $this->admissionService->validatePatientIdentity([
            'lastName' => $next['lastName'] ?? '',
            'firstName' => $next['firstName'] ?? '',
            'ipp' => $next['ipp'] ?? '',
            'birthDate' => $next['birthDate'] ?? '',
            'sex' => $next['sex'] ?? '',
        ]);
        foreach ($identityErrors as $field => $message) {
            $errors[$field] = $message;
        }
        $phone = $next['phone'] === null ? null : str_replace(' ', '', $next['phone']);
        if ($phone !== null && preg_match(self::PHONE_PATTERN, $phone) !== 1) {
            $errors['phone'] = 'Le téléphone doit contenir 10 chiffres.';
        }
        $email = $next['email'];
        $emailInvalid = $email !== null
            && (mb_strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false);
        if ($emailInvalid) {
            $errors['email'] = 'L\'adresse e-mail est invalide.';
        }
        if ($next['ipp'] !== null && $next['ipp'] !== $current['ipp']) {
            // Patients supprimés compris (AURA-12.3) : leur IPP reste réservé.
            $owner = $this->patientDao->ippOwnerId($next['ipp']);
            if ($owner !== null && $owner !== $patient->getIdPatient()) {
                $errors['ipp'] = 'Cet IPP est déjà utilisé par un autre patient.';
            }
        }
        if ($errors !== []) {
            return;
        }

        $this->patientDao->update(new Patient(
            idPatient: $patient->getIdPatient(),
            ipp: $next['ipp'],
            lastName: (string) $next['lastName'],
            firstName: (string) $next['firstName'],
            birthDate: $next['birthDate'] === null
                ? null
                : (DateTimeImmutable::createFromFormat('!Y-m-d', $next['birthDate']) ?: null),
            sex: $next['sex'],
            phone: $phone,
            email: $email,
            details: $patient->getDetails()
        ));
        // La trace enregistre la valeur réellement écrite (téléphone sans espaces).
        if (isset($identity['phone'])) {
            $identity['phone']['new'] = $phone;
        }
        foreach ($identity as $field => $change) {
            $this->trace(
                AuditEvent::ENTITY_PATIENT,
                (int) $patient->getIdPatient(),
                self::IDENTITY_FIELDS[$field],
                $change,
                $author
            );
        }
    }

    /**
     * Lit et box : EpisodeOfCareService contrôle que l'emplacement est libre et le séjour en cours.
     *
     * @param array<string, array{new: ?string, old: ?string, spec: array<string, mixed>}> $changes
     * @param array<string, string> $errors
     */
    private function applyLocation(
        array $changes,
        EpisodeOfCare $episodeOfCare,
        string $author,
        array &$errors,
        bool &$conflict
    ): void {
        $location = array_intersect_key($changes, ['bedNumber' => true, 'boxNumber' => true]);
        if ($location === []) {
            return;
        }
        $current = ['bedNumber' => $episodeOfCare->getBedNumber(), 'boxNumber' => $episodeOfCare->getBoxNumber()];
        foreach ($location as $field => $change) {
            if ($change['old'] !== self::blankToNull($current[$field])) {
                $conflict = true;
                $errors[$field] = 'Modifié entre-temps par quelqu\'un d\'autre.';
            }
        }
        if ($conflict) {
            return;
        }
        $bed = array_key_exists('bedNumber', $location) ? $location['bedNumber']['new'] : $current['bedNumber'];
        $box = array_key_exists('boxNumber', $location) ? $location['boxNumber']['new'] : $current['boxNumber'];
        try {
            $this->episodeOfCareService->changeLocation((int) $episodeOfCare->getIdEpisodeOfCare(), $bed, $box);
        } catch (InvalidArgumentException $e) {
            $errors[isset($location['bedNumber']) ? 'bedNumber' : 'boxNumber'] = $e->getMessage();
            return;
        }
        foreach ($location as $field => $change) {
            $this->trace(
                AuditEvent::ENTITY_EPISODE,
                (int) $episodeOfCare->getIdEpisodeOfCare(),
                $field,
                $change,
                $author
            );
        }
    }

    /**
     * Trace d'un champ modifié par une partie (AuditEvent, au nom du médecin qui valide).
     *
     * @param array{new: ?string, old: ?string} $change
     */
    private function trace(string $entity, int $id, string $field, array $change, string $author): void
    {
        $this->auditEventDao->insert(new AuditEvent(
            null,
            $entity,
            $id,
            $field,
            $change['old'],
            $change['new'],
            $author,
            detail: 'modification de la partie, validée par un médecin'
        ));
    }

    /** @return array<string, ?string> identité actuelle, au format des formulaires nettoyés (dates ISO) */
    private static function identityValues(Patient $patient): array
    {
        return [
            'lastName' => self::blankToNull($patient->getLastName()),
            'firstName' => self::blankToNull($patient->getFirstName()),
            'birthDate' => $patient->getBirthDate()?->format('Y-m-d'),
            'sex' => self::blankToNull($patient->getSex()),
            'ipp' => self::blankToNull($patient->getIpp()),
            'phone' => self::blankToNull($patient->getPhone()),
            'email' => self::blankToNull($patient->getEmail()),
        ];
    }

    /** Valeur actuelle d'un champ d'une partie, au format des services (dates ISO). */
    private static function read(string $field, Patient $patient, EpisodeOfCare $episodeOfCare): ?string
    {
        if (isset(self::IDENTITY_FIELDS[$field])) {
            return self::identityValues($patient)[$field];
        }

        return match ($field) {
            'bedNumber' => self::blankToNull($episodeOfCare->getBedNumber()),
            'boxNumber' => self::blankToNull($episodeOfCare->getBoxNumber()),
            'idHealthcareService' => self::blankToNull($episodeOfCare->getIdService()),
            'idReferringPractitioner' => self::blankToNull($episodeOfCare->getIdReferringPractitioner()),
            'precautions' => self::blankToNull($episodeOfCare->getPrecautions()),
            'weight' => $episodeOfCare->getWeight() === null ? null : self::number($episodeOfCare->getWeight()),
            'height' => $episodeOfCare->getHeight() === null ? null : self::number($episodeOfCare->getHeight()),
            default => isset(RecordFields::PATIENT[$field])
                ? self::blankToNull($patient->getDetail($field))
                : self::blankToNull($episodeOfCare->getDetail($field)),
        };
    }

    /** Valeur envoyée, nettoyée : texte sans espaces de bord, fins de ligne \n, date ISO ; vide = null. */
    private static function clean(mixed $value, string $type): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim(str_replace("\r\n", "\n", $value));
        if ($type === 'date') {
            $value = DateInput::toIso($value);
        }
        if ($type === 'number' && is_numeric(str_replace(',', '.', $value))) {
            $value = self::number((float) str_replace(',', '.', $value));
        }

        return $value === '' ? null : $value;
    }

    /** Nombre au format des formulaires : au plus deux décimales, sans zéros inutiles (68.50 → « 68.5 »). */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** Texte sans espaces de bord ; vide ou null = null. */
    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' || $value === null ? null : $value;
    }

    /** @return array{status: string, errors: array<string, string>, message: ?string} */
    private static function result(string $status, array $errors = [], ?string $message = null): array
    {
        return ['status' => $status, 'errors' => $errors, 'message' => $message];
    }
}
