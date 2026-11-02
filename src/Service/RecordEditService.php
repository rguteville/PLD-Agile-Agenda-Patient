<?php

declare(strict_types=1);

namespace Aura\Service;

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

/**
 * Édition champ par champ du dossier patient (PR « dossier-patient-edition-inline »).
 *
 * Règles :
 *  - liste blanche explicite des champs modifiables par entité (jamais une colonne envoyée par le client) ;
 *  - même validation que l'admission (FieldValidationService, bornes d'AdmissionService) ;
 *  - contrôle de concurrence optimiste : l'ancienne valeur envoyée doit être celle en base, sinon refus
 *    avec la valeur actuelle ;
 *  - traçabilité : chaque modification écrit une ligne AuditEvent dans la même transaction.
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
        private SejourDao $sejourDao,
        private AuditEventDao $auditEventDao,
        private Transaction $transaction,
        private ?ReferenceDataDao $referenceDataDao = null,
        ?DateTimeImmutable $today = null
    ) {
        $this->fields = new FieldValidationService($today);
    }

    /**
     * Champs modifiables d'une entité : spécification (label, type, bornes, options) par nom de
     * colonne. Pour le séjour, les mesures d'admission et le service s'ajoutent au catalogue.
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
                    'min' => 0.01, 'maxValue' => AdmissionService::MESURE_MAX,
                ],
                'height' => [
                    'label' => 'Taille (cm)', 'type' => 'number',
                    'min' => 0.01, 'maxValue' => AdmissionService::MESURE_MAX,
                ],
                'precautions' => [
                    'label' => 'Précautions', 'type' => 'textarea',
                    'max' => AdmissionService::PRECAUTIONS_LONGUEUR_MAX,
                ],
                'idHealthcareService' => [
                    'label' => 'Service hospitalier', 'type' => 'select',
                    'options' => $this->referenceDataDao?->healthcareServices() ?? [],
                ],
            ];
        }

        return [];
    }

    /**
     * Modifie un champ. Ne lève pas d'exception pour un refus métier : le résultat porte un statut
     * (STATUS_*), un message français et, en cas de conflit, la valeur actuelle en base.
     *
     * @return array{status: string, message: ?string, value: ?string, display: ?string}
     */
    public function update(
        string $entity,
        int $id,
        string $field,
        mixed $newValue,
        mixed $oldValue,
        string $author = AuditEvent::AUTHOR_UNKNOWN
    ): array {
        $specs = $this->editableFields($entity);
        if (!isset($specs[$field])) {
            return self::result(self::STATUS_FORBIDDEN_FIELD, 'Ce champ ne peut pas être modifié depuis le dossier.');
        }
        $spec = $specs[$field];

        $record = $entity === self::ENTITY_PATIENT ? $this->patientDao->findById($id) : $this->sejourDao->findById($id);
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

        $entityName = $entity === self::ENTITY_PATIENT ? AuditEvent::ENTITY_PATIENT : AuditEvent::ENTITY_EPISODE;
        $this->transaction->run(function () use ($entity, $id, $field, $new, $current, $author, $entityName): void {
            if ($entity === self::ENTITY_PATIENT) {
                $this->patientDao->updateDetails($id, [$field => $new]);
            } else {
                $this->sejourDao->updateDetails($id, [$field => $new]);
            }
            $this->auditEventDao->insert(new AuditEvent(null, $entityName, $id, $field, $current, $new, $author));
        });

        return self::result(self::STATUS_SAVED, 'Enregistré', $new, self::display($spec, $new));
    }

    /**
     * Historique d'un dossier prêt à afficher : date, auteur, libellé du champ, valeurs affichables.
     *
     * @return list<array{recordedAt: string, author: string, entity: string, field: string,
     *               label: string, oldValue: ?string, newValue: ?string}>
     */
    public function history(int $idPatient, int $idEpisode, int $limit = 20): array
    {
        $labels = [
            AuditEvent::ENTITY_PATIENT => $this->editableFields(self::ENTITY_PATIENT),
            AuditEvent::ENTITY_EPISODE => $this->editableFields(self::ENTITY_EPISODE),
        ];
        $rows = [];
        foreach ($this->auditEventDao->findForRecord($idPatient, $idEpisode, $limit) as $event) {
            $spec = $labels[$event->getEntity()][$event->getField()]
                ?? ['label' => $event->getField(), 'type' => 'text'];
            $rows[] = [
                'recordedAt' => $event->getRecordedAt()?->format('d/m/Y H:i') ?? '',
                'author' => $event->getAuthor(),
                'entity' => $event->getEntity(),
                'field' => $event->getField(),
                'label' => (string) $spec['label'],
                'oldValue' => self::display($spec, $event->getOldValue()),
                'newValue' => self::display($spec, $event->getNewValue()),
            ];
        }

        return $rows;
    }

    /** Valeur affichable d'un champ : libellé d'une échelle, date à la française, nombre sans zéros inutiles. */
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
    private function currentValue(Patient|Sejour $record, string $field): ?string
    {
        if ($record instanceof Sejour) {
            switch ($field) {
                case 'weight':
                    return $record->getPoids() === null ? null : self::formatNumber($record->getPoids());
                case 'height':
                    return $record->getTaille() === null ? null : self::formatNumber($record->getTaille());
                case 'precautions':
                    return self::blankToNull($record->getPrecautions());
                case 'idHealthcareService':
                    return self::blankToNull($record->getIdService());
                default:
                    return self::blankToNull($record->getDetail($field));
            }
        }

        return self::blankToNull($record->getDetail($field));
    }

    /** Deux valeurs diffèrent-elles ? Les nombres sont comparés numériquement (« 62.50 » = « 62.5 »). */
    private function differs(array $spec, ?string $a, ?string $b): bool
    {
        if (($spec['type'] ?? 'text') === 'number' && $a !== null && $b !== null && is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) >= 0.005;
        }

        return $a !== $b;
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

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
