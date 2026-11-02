<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Core\DateInput;
use Aura\Dao\AuditEventDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\SejourDao;
use Aura\Dao\Transaction;
use Aura\Domain\AuditEvent;
use Aura\Domain\Patient;
use Aura\Domain\RecordFields;
use Aura\Domain\Sejour;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Modification d'une partie entière du dossier patient et fin de séjour (retours du 2026-11-02).
 *
 * Chaque partie du dossier (le résumé « Dossier » avec l'identité, le séjour et les coordonnées, puis
 * Admission, Antécédents, Histoire de la maladie, Scores, Mesures, Administratif) se modifie d'un bloc,
 * en plus de l'édition champ par champ. Règles :
 *  - liste blanche des champs par partie (partFields()) ; jamais une clé envoyée par le client ;
 *  - validation existante : identité (AdmissionService), catalogue et mesures (RecordEditService),
 *    lit et box (SejourService, contrôle d'occupation) ;
 *  - concurrence optimiste : la valeur d'origine envoyée doit être celle en base ;
 *  - tout ou rien : la partie est écrite dans une transaction, annulée à la première erreur ;
 *  - traçabilité : chaque champ modifié écrit une ligne AuditEvent au nom du médecin qui valide.
 *
 * AUTHENTIFICATION FACTICE : le médecin est choisi dans la liste des praticiens habilités (RG5) et le
 * mot de passe est exigé mais NON vérifié. Elle prépare l'authentification réelle (AURA-18) ; la
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
        'nom' => 'lastName',
        'prenom' => 'firstName',
        'dateNaissance' => 'birthDate',
        'sexe' => 'sex',
        'ipp' => 'ipp',
        'telephone' => 'phoneNumber',
        'email' => 'email',
    ];

    /** Téléphone : 10 chiffres (colonne Patient.phoneNumber VARCHAR(10)), espaces tolérés à la saisie. */
    private const PHONE_PATTERN = '/^\d{10}$/';
    private const EMAIL_MAX = 50;

    public function __construct(
        private PatientDao $patientDao,
        private SejourDao $sejourDao,
        private SejourService $sejourService,
        private RecordEditService $recordEditService,
        private AuditEventDao $auditEventDao,
        private Transaction $transaction,
        private ReferenceDataDao $referenceDataDao,
        private AdmissionService $admissionService = new AdmissionService()
    ) {
    }

    /**
     * Champs modifiables d'une partie : clé => ['entity' => patient|episode|identity|location, 'label',
     * 'type', 'options'?]. [] si la partie est inconnue.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function partFields(string $part, array $services = []): array
    {
        if ($part === self::PART_SUMMARY) {
            return [
                'nom' => [
                    'entity' => 'identity', 'label' => 'Nom', 'type' => 'text', 'max' => 50, 'required' => true,
                    'placeholder' => 'DUPONT',
                ],
                'prenom' => [
                    'entity' => 'identity', 'label' => 'Prénom', 'type' => 'text', 'max' => 50, 'required' => true,
                    'placeholder' => 'Marie',
                ],
                'dateNaissance' => ['entity' => 'identity', 'label' => 'Date de naissance', 'type' => 'date'],
                'sexe' => [
                    'entity' => 'identity', 'label' => 'Sexe', 'type' => 'radio', 'options' => ['M' => 'M', 'F' => 'F'],
                ],
                'ipp' => ['entity' => 'identity',
                'label' => 'IPP',
                'type' => 'text',
                'placeholder' => '100000123'],
                'telephone' => [
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
                    'max' => AdmissionService::PRECAUTIONS_LONGUEUR_MAX, 'placeholder' => 'Isolement, risque de chute…',
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
                'max' => AdmissionService::PRECAUTIONS_LONGUEUR_MAX, 'placeholder' => 'Isolement, risque de chute…',
            ];
        }

        return $fields;
    }

    /**
     * Valeurs actuelles d'une partie, au format des formulaires (dates en JJ/MM/AAAA).
     *
     * @return array<string, string>
     */
    public static function currentValues(string $part, Patient $patient, Sejour $sejour): array
    {
        $values = [];
        foreach (array_keys(self::partFields($part)) as $field) {
            $values[$field] = DateInput::fromIso(self::read($field, $patient, $sejour));
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
     * Enregistre une partie : valeurs nouvelles et d'origine indexées par clé de partFields().
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $oldValues
     * @return array{status: string, errors: array<string, string>, message: ?string}
     */
    public function updatePart(
        int $idSejour,
        string $part,
        array $values,
        array $oldValues,
        string $idValidator,
        string $password
    ): array {
        $author = $this->authenticate($idValidator, $password);
        if ($author === null) {
            return self::result(self::STATUS_AUTH, ['auth' => self::AUTH_ERROR]);
        }
        $sejour = $this->sejourDao->findById($idSejour);
        $patient = $sejour === null ? null : $this->patientDao->findById($sejour->getIdPatient());
        if ($sejour === null || $patient === null) {
            return self::result(self::STATUS_NOT_FOUND, [], 'Dossier introuvable.');
        }
        $fields = self::partFields($part, $this->referenceDataDao->healthcareServices());
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
            $this->transaction->run(function () use ($changes, $patient, $sejour, $author): void {
                $errors = [];
                $conflict = false;
                $this->applyIdentity($changes, $patient, $author, $errors, $conflict);
                $this->applyLocation($changes, $sejour, $author, $errors, $conflict);
                foreach ($changes as $field => $change) {
                    $entity = $change['spec']['entity'];
                    if ($entity !== 'patient' && $entity !== 'episode') {
                        continue;
                    }
                    $result = $this->recordEditService->update(
                        $entity === 'patient' ? RecordEditService::ENTITY_PATIENT : RecordEditService::ENTITY_EPISODE,
                        $entity === 'patient' ? (int) $patient->getIdPatient() : (int) $sejour->getIdSejour(),
                        $field,
                        $change['new'],
                        $change['old'],
                        $author,
                        (int) $sejour->getIdSejour()
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
    public function endStay(int $idSejour, string $date, string $time, string $idValidator, string $password): array
    {
        $author = $this->authenticate($idValidator, $password);
        if ($author === null) {
            return self::result(self::STATUS_AUTH, ['auth' => self::AUTH_ERROR]);
        }
        if ($this->sejourDao->findById($idSejour) === null) {
            return self::result(self::STATUS_NOT_FOUND, [], 'Séjour introuvable.');
        }

        $date = trim($date);
        $time = trim($time);
        $dischargeDate = null;
        if ($date !== '' || $time !== '') {
            $parsed = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i',
                DateInput::toIso($date) . ' ' . ($time !== '' ? $time : '00:00')
            );
            if ($parsed === false || $parsed->format('d/m/Y') !== $date) {
                return self::result(self::STATUS_INVALID, [
                    'dischargeDate' => 'Date ou heure de sortie invalide (JJ/MM/AAAA et HH:MM).',
                ]);
            }
            $dischargeDate = $parsed;
        }

        try {
            $this->transaction->run(function () use ($idSejour, $dischargeDate, $author): void {
                $closed = $this->sejourService->cloturerSejour($idSejour, $dischargeDate);
                $this->auditEventDao->insert(new AuditEvent(
                    null,
                    AuditEvent::ENTITY_EPISODE,
                    $idSejour,
                    'dischargeDate',
                    null,
                    $closed->getDateSortie()?->format('d/m/Y H:i'),
                    $author,
                    detail: 'fin du séjour'
                ));
            });
        } catch (InvalidArgumentException $e) {
            return self::result(self::STATUS_INVALID, ['dischargeDate' => $e->getMessage()]);
        }

        return self::result(self::STATUS_SAVED);
    }

    private const AUTH_ERROR = 'Choisissez le médecin qui valide et saisissez son mot de passe.';

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
        $identityErrors = $this->admissionService->validerPatientIdentite([
            'nom' => $next['nom'] ?? '',
            'prenom' => $next['prenom'] ?? '',
            'ipp' => $next['ipp'] ?? '',
            'dateNaissance' => $next['dateNaissance'] ?? '',
            'sexe' => $next['sexe'] ?? '',
        ]);
        foreach ($identityErrors as $field => $message) {
            $errors[$field] = $message;
        }
        $phone = $next['telephone'] === null ? null : str_replace(' ', '', $next['telephone']);
        if ($phone !== null && preg_match(self::PHONE_PATTERN, $phone) !== 1) {
            $errors['telephone'] = 'Le téléphone doit contenir 10 chiffres.';
        }
        $email = $next['email'];
        $emailInvalid = $email !== null
            && (mb_strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false);
        if ($emailInvalid) {
            $errors['email'] = 'L\'adresse e-mail est invalide.';
        }
        if ($next['ipp'] !== null && $next['ipp'] !== $current['ipp']) {
            $owner = $this->patientDao->findByIpp($next['ipp']);
            if ($owner !== null && $owner->getIdPatient() !== $patient->getIdPatient()) {
                $errors['ipp'] = 'Cet IPP est déjà utilisé par un autre patient.';
            }
        }
        if ($errors !== []) {
            return;
        }

        $this->patientDao->update(new Patient(
            idPatient: $patient->getIdPatient(),
            ipp: $next['ipp'],
            nom: (string) $next['nom'],
            prenom: (string) $next['prenom'],
            dateNaissance: $next['dateNaissance'] === null
                ? null
                : (DateTimeImmutable::createFromFormat('!Y-m-d', $next['dateNaissance']) ?: null),
            sexe: $next['sexe'],
            telephone: $phone,
            email: $email,
            details: $patient->getDetails()
        ));
        // La trace enregistre la valeur réellement écrite (téléphone sans espaces).
        if (isset($identity['telephone'])) {
            $identity['telephone']['new'] = $phone;
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
     * Lit et box : SejourService contrôle que l'emplacement est libre et le séjour en cours.
     *
     * @param array<string, array{new: ?string, old: ?string, spec: array<string, mixed>}> $changes
     * @param array<string, string> $errors
     */
    private function applyLocation(
        array $changes,
        Sejour $sejour,
        string $author,
        array &$errors,
        bool &$conflict
    ): void {
        $location = array_intersect_key($changes, ['bedNumber' => true, 'boxNumber' => true]);
        if ($location === []) {
            return;
        }
        $current = ['bedNumber' => $sejour->getNumLit(), 'boxNumber' => $sejour->getNumBox()];
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
            $this->sejourService->changerEmplacement((int) $sejour->getIdSejour(), $bed, $box);
        } catch (InvalidArgumentException $e) {
            $errors[isset($location['bedNumber']) ? 'bedNumber' : 'boxNumber'] = $e->getMessage();
            return;
        }
        foreach ($location as $field => $change) {
            $this->trace(AuditEvent::ENTITY_EPISODE, (int) $sejour->getIdSejour(), $field, $change, $author);
        }
    }

    /** @param array{new: ?string, old: ?string} $change */
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
            'nom' => self::blankToNull($patient->getNom()),
            'prenom' => self::blankToNull($patient->getPrenom()),
            'dateNaissance' => $patient->getDateNaissance()?->format('Y-m-d'),
            'sexe' => self::blankToNull($patient->getSexe()),
            'ipp' => self::blankToNull($patient->getIpp()),
            'telephone' => self::blankToNull($patient->getTelephone()),
            'email' => self::blankToNull($patient->getEmail()),
        ];
    }

    /** Valeur actuelle d'un champ d'une partie, au format des services (dates ISO). */
    private static function read(string $field, Patient $patient, Sejour $sejour): ?string
    {
        if (isset(self::IDENTITY_FIELDS[$field])) {
            return self::identityValues($patient)[$field];
        }

        return match ($field) {
            'bedNumber' => self::blankToNull($sejour->getNumLit()),
            'boxNumber' => self::blankToNull($sejour->getNumBox()),
            'idHealthcareService' => self::blankToNull($sejour->getIdService()),
            'precautions' => self::blankToNull($sejour->getPrecautions()),
            'weight' => $sejour->getPoids() === null ? null : self::number($sejour->getPoids()),
            'height' => $sejour->getTaille() === null ? null : self::number($sejour->getTaille()),
            default => isset(RecordFields::PATIENT[$field])
                ? self::blankToNull($patient->getDetail($field))
                : self::blankToNull($sejour->getDetail($field)),
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

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

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
