<?php

declare(strict_types=1);

namespace Aura\Service;

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

/**
 * Édition champ par champ du dossier patient (PR « dossier-patient-edition-inline »).
 *
 * Règles :
 *  - liste blanche explicite des champs modifiables par entité (jamais une colonne envoyée par le client) ;
 *  - même validation que l'admission (FieldValidationService, bornes d'AdmissionService) ;
 *  - contrôle de concurrence optimiste : l'ancienne valeur envoyée doit être celle en base, sinon refus
 *    avec la valeur actuelle ;
 *  - traçabilité : chaque modification écrit une ligne AuditEvent dans la même transaction ;
 *  - champs réservés au médecin (RecordFields, 'validatedByDoctor' : groupe sanguin, décès) : refusés sans
 *    auteur identifié, donc jamais modifiés par l'édition en ligne ; ils passent par RecordSectionService.
 *    Seule exception : l'admission peut compléter un groupe sanguin encore vide ($completeEmpty).
 */
class RecordEditService
{
    public const ENTITY_PATIENT = 'patient';
    public const ENTITY_EPISODE = 'episode';

    /** Résultats possibles de update(). */
    public const STATUS_SAVED = 'saved';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_FORBIDDEN_FIELD = 'forbidden_field';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_CONFLICT = 'conflict';

    private FieldValidationService $fields;

    public function __construct(
        private PatientDao $patientDao,
        private EpisodeOfCareDao $episodeOfCareDao,
        private AuditEventDao $auditEventDao,
        private Transaction $transaction,
        private ?ReferenceDataDao $referenceDataDao = null
    ) {
        $this->fields = new FieldValidationService();
    }

    /**
     * Champs modifiables d'une entité : spécification (label, type, bornes, options) par nom de
     * colonne. Pour le séjour, les mesures d'admission, le service et le médecin référent s'ajoutent au
     * catalogue.
     *
     * @return array<string, array<string, mixed>>
     */
    public function editableFields(string $entity): array
    {
        if ($entity === self::ENTITY_PATIENT) {
            return RecordFields::PATIENT;
        }
        if ($entity === self::ENTITY_EPISODE) {
            return RecordFields::EPISODE + [
                'weight' => [
                    'label' => 'Poids à l\'admission (kg)', 'type' => 'number',
                    'min' => 0.01, 'maxValue' => AdmissionService::MEASURE_MAX,
                ],
                'height' => [
                    'label' => 'Taille (cm)', 'type' => 'number',
                    'min' => 0.01, 'maxValue' => AdmissionService::MEASURE_MAX,
                ],
                'precautions' => [
                    'label' => 'Précautions', 'type' => 'textarea',
                    'max' => AdmissionService::PRECAUTIONS_MAX_LENGTH,
                ],
                'idHealthcareService' => [
                    'label' => 'Service hospitalier', 'type' => 'select',
                    'options' => $this->referenceDataDao?->healthcareServices() ?? [],
                ],
                // Médecin référent (retours du 2026-11-03) : un médecin de la liste, vide = à désigner.
                'idReferringPractitioner' => [
                    'label' => 'Médecin référent', 'type' => 'select',
                    'options' => $this->referenceDataDao?->doctors() ?? [],
                ],
            ];
        }

        return [];
    }

    /**
     * Modifie un champ. Ne lève pas d'exception pour un refus métier : le résultat porte un statut
     * (STATUS_*), un message français et, en cas de conflit, la valeur actuelle en base.
     *
     * $completeEmpty (admission seulement) : un champ réservé au médecin peut être renseigné sans auteur
     * identifié s'il est encore vide ; jamais remplacé ni effacé.
     *
     * @param string $entity ENTITY_PATIENT ou ENTITY_EPISODE
     * @param string $author auteur tracé ; AuditEvent::AUTHOR_UNKNOWN = non identifié (édition en ligne, admission)
     * @param int|null $idEpisodeOfCare séjour du dossier : un patient n'est modifiable que s'il est celui de ce séjour
     * @return array{status: string, message: ?string, value: ?string, display: ?string}
     */
    public function update(
        string $entity,
        int $id,
        string $field,
        mixed $newValue,
        mixed $oldValue,
        string $author = AuditEvent::AUTHOR_UNKNOWN,
        ?int $idEpisodeOfCare = null,
        bool $completeEmpty = false
    ): array {
        $specs = $this->editableFields($entity);
        if (!isset($specs[$field])) {
            return self::result(self::STATUS_FORBIDDEN_FIELD, 'Ce champ ne peut pas être modifié depuis le dossier.');
        }
        $spec = $specs[$field];
        // Champ réservé à un médecin identifié (groupe sanguin, décès) : jamais modifié par l'édition en ligne
        // ni par une admission (auteur non identifié), seulement par la modification d'une partie validée.
        // Exception : l'admission complète un champ encore vide ($completeEmpty).
        $completing = $completeEmpty && trim((string) $oldValue) === '' && trim((string) $newValue) !== '';
        if (($spec['validatedByDoctor'] ?? false) && $author === AuditEvent::AUTHOR_UNKNOWN && !$completing) {
            return self::result(
                self::STATUS_FORBIDDEN_FIELD,
                'Ce champ se modifie avec « Modifier » sur sa partie, validé par un médecin.'
            );
        }

        // Depuis le dossier d'un séjour, seuls ce séjour et son patient sont modifiables : l'identifiant envoyé
        // par le client ne suffit pas (relecture adversariale du 2026-11-04 : le séjour d'un autre patient était
        // modifiable depuis un dossier). Le patient doit aussi être actif (pas supprimé de la liste).
        if ($idEpisodeOfCare !== null) {
            $episodeOfCare = $this->episodeOfCareDao->findById($idEpisodeOfCare);
            $owner = $episodeOfCare === null ? null : $this->patientDao->findById($episodeOfCare->getIdPatient());
            $sameRecord = $entity === self::ENTITY_PATIENT
                ? $episodeOfCare?->getIdPatient() === $id
                : $idEpisodeOfCare === $id;
            if ($owner === null || !$sameRecord) {
                return self::result(self::STATUS_NOT_FOUND, 'Dossier introuvable.');
            }
        }

        $record = $entity === self::ENTITY_PATIENT
            ? $this->patientDao->findById($id)
            : $this->episodeOfCareDao->findById($id);
        if ($record === null) {
            return self::result(self::STATUS_NOT_FOUND, 'Dossier introuvable.');
        }

        $current = $this->currentValue($record, $field);
        $old = $this->fields->normalize([$field => $spec], [$field => $oldValue])[$field] ?? null;
        if ($this->differs($spec, $current, $old)) {
            return self::result(
                self::STATUS_CONFLICT,
                'Ce champ a été modifié entre-temps par quelqu\'un d\'autre. Valeur actuelle rechargée.',
                $current,
                self::display($spec, $current)
            );
        }

        $errors = $this->fields->validate([$field => $spec], [$field => $newValue]);
        if ($errors !== []) {
            return self::result(self::STATUS_INVALID, $errors[$field], $current, self::display($spec, $current));
        }
        $new = $this->fields->normalize([$field => $spec], [$field => $newValue])[$field] ?? null;
        if ($spec['type'] === 'number' && $new !== null) {
            // Même arrondi que l'admission : la valeur renvoyée est celle que la colonne DECIMAL(5,2) relira.
            $new = self::formatNumber(round((float) $new, 2));
        }

        if (!$this->differs($spec, $current, $new)) {
            return self::result(self::STATUS_UNCHANGED, null, $current, self::display($spec, $current));
        }

        // Écriture conditionnelle (« si la valeur en base est encore $current ») + trace, dans une
        // transaction : une modification concurrente entre la lecture et l'écriture est détectée par
        // l'UPDATE lui-même, pas seulement par la comparaison faite plus haut.
        $entityName = $entity === self::ENTITY_PATIENT ? AuditEvent::ENTITY_PATIENT : AuditEvent::ENTITY_EPISODE;
        $expected = $current;
        $written = $this->transaction->run(
            function () use ($entity, $id, $field, $new, $current, $expected, $author, $entityName): bool {
                $ok = $entity === self::ENTITY_PATIENT
                    ? $this->patientDao->updateDetailIfUnchanged($id, $field, $new, $expected)
                    : $this->episodeOfCareDao->updateDetailIfUnchanged($id, $field, $new, $expected);
                if ($ok) {
                    $this->auditEventDao->insert(
                        new AuditEvent(null, $entityName, $id, $field, $current, $new, $author)
                    );
                }

                return $ok;
            }
        );
        if (!$written) {
            $record = $entity === self::ENTITY_PATIENT
                ? $this->patientDao->findById($id)
                : $this->episodeOfCareDao->findById($id);
            $latest = $record === null ? null : $this->currentValue($record, $field);

            return self::result(
                self::STATUS_CONFLICT,
                'Ce champ a été modifié entre-temps par quelqu\'un d\'autre. Valeur actuelle rechargée.',
                $latest,
                self::display($spec, $latest)
            );
        }

        return self::result(self::STATUS_SAVED, 'Enregistré', $new, self::display($spec, $new));
    }

    /**
     * Historique d'un dossier prêt à afficher : date, auteur, libellé du champ, valeurs affichables.
     *
     * @return list<array{recordedAt: string, author: string, entity: string, field: string,
     *               label: string, detail: ?string, oldValue: ?string, newValue: ?string}>
     */
    public function history(int $idPatient, int $idEpisode, int $limit = 20): array
    {
        $labels = [
            AuditEvent::ENTITY_PATIENT => $this->editableFields(self::ENTITY_PATIENT),
            AuditEvent::ENTITY_EPISODE => $this->editableFields(self::ENTITY_EPISODE),
        ];
        // Événements écrits hors édition en ligne (AdmissionService) : libellé français dédié.
        $special = [
            'idPatient' => ['label' => 'Rattachement du séjour au patient', 'type' => 'text'],
            'lastName' => ['label' => 'Nom', 'type' => 'text'],
            'firstName' => ['label' => 'Prénom', 'type' => 'text'],
            'birthDate' => ['label' => 'Date de naissance', 'type' => 'date'],
            'sex' => ['label' => 'Sexe', 'type' => 'text'],
            'ipp' => ['label' => 'IPP', 'type' => 'text'],
            'allergyCriticality' => ['label' => 'Criticité d\'une allergie', 'type' => 'text'],
        ];
        $rows = [];
        foreach ($this->auditEventDao->findForRecord($idPatient, $idEpisode, $limit) as $event) {
            $spec = $labels[$event->getEntity()][$event->getField()]
                ?? $special[$event->getField()]
                ?? ['label' => $event->getField(), 'type' => 'text'];
            $newValue = self::display($spec, $event->getNewValue());
            if ($event->getField() === 'idPatient' && $newValue !== null) {
                $newValue = 'patient n° ' . $newValue;
            }
            $rows[] = [
                'recordedAt' => $event->getRecordedAt()?->format('d/m/Y H:i') ?? '',
                'author' => $event->getAuthor(),
                'entity' => $event->getEntity(),
                'field' => $event->getField(),
                'label' => (string) $spec['label'],
                'detail' => $event->getDetail(),
                'oldValue' => self::display($spec, $event->getOldValue()),
                'newValue' => $newValue,
            ];
        }

        return $rows;
    }

    /**
     * Valeur affichable d'un champ : libellé d'une échelle, date à la française, nombre sans zéros inutiles.
     *
     * @param array<string, mixed> $spec spécification du champ (type, options)
     */
    public static function display(array $spec, ?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        switch ($spec['type'] ?? 'text') {
            case 'select':
                return (string) ($spec['options'][$raw] ?? $raw);
            case 'date':
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                return $date !== false ? $date->format('d/m/Y') : $raw;
            case 'number':
                return is_numeric($raw) ? self::formatNumber((float) $raw) : $raw;
            default:
                return $raw;
        }
    }

    /** Valeur actuelle du champ dans l'entité relue, sous forme normalisée (null = non renseigné). */
    private function currentValue(Patient|EpisodeOfCare $record, string $field): ?string
    {
        if ($record instanceof EpisodeOfCare) {
            switch ($field) {
                case 'weight':
                    return $record->getWeight() === null ? null : self::formatNumber($record->getWeight());
                case 'height':
                    return $record->getHeight() === null ? null : self::formatNumber($record->getHeight());
                case 'precautions':
                    return self::blankToNull($record->getPrecautions());
                case 'idHealthcareService':
                    return self::blankToNull($record->getIdService());
                case 'idReferringPractitioner':
                    return self::blankToNull($record->getIdReferringPractitioner());
                default:
                    return self::blankToNull($record->getDetail($field));
            }
        }

        return self::blankToNull($record->getDetail($field));
    }

    /**
     * Deux valeurs diffèrent-elles ? Les nombres sont comparés numériquement (« 62.50 » = « 62.5 »).
     *
     * @param array<string, mixed> $spec
     */
    private function differs(array $spec, ?string $a, ?string $b): bool
    {
        if (($spec['type'] ?? 'text') === 'number' && $a !== null && $b !== null && is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) >= 0.005;
        }

        return $a !== $b;
    }

    /** Nombre au format des formulaires : au plus deux décimales, sans zéros inutiles (62.50 → « 62.5 »). */
    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** Texte sans espaces de bord ; vide ou null = null. */
    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return array{status: string, message: ?string, value: ?string, display: ?string} */
    private static function result(
        string $status,
        ?string $message,
        ?string $value = null,
        ?string $display = null
    ): array {
        return ['status' => $status, 'message' => $message, 'value' => $value, 'display' => $display];
    }
}
