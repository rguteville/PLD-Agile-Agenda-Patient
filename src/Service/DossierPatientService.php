<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\SejourDao;
use Aura\Dao\SubstanceDao;
use Aura\Domain\Allergie;
use Aura\Domain\Patient;
use Aura\Domain\RecordFields;
use Aura\Domain\Sejour;
use Aura\Domain\Substance;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Prépare l'affichage du dossier d'un séjour (AURA-14) : le bandeau identité + allergies
 * imposé par RG2 en tête de tout écran lié au séjour, les informations du séjour et,
 * depuis AURA-13.2, la section de gestion des allergies (liste avec retrait, ajout
 * depuis le référentiel).
 *
 * Les données viennent de la base via les DAO (AURA-11) ; les méthodes presenter*()
 * et availableSubstances() sont pures (pas d'accès aux données) pour être testables sans base.
 */
final class DossierPatientService
{
    public function __construct(
        private SejourDao $sejourDao,
        private PatientDao $patientDao,
        private AllergieDao $allergieDao,
        private SubstanceDao $substanceDao,
        private DateTimeImmutable $aujourdhui,
        private ?ReferenceDataDao $referenceDataDao = null,
    ) {
    }

    /**
     * @return array{bandeau: array<string, mixed>, sejour: array<string, mixed>,
     *               allergies: list<array<string, mixed>>, substances: array<string, string>,
     *               criticalities: list<string>}|null
     *         null si le séjour n'existe pas (ou si son patient a disparu : données incohérentes)
     */
    public function dossier(int $idSejour): ?array
    {
        $sejour = $this->sejourDao->findById($idSejour);
        if ($sejour === null) {
            return null;
        }

        $patient = $this->patientDao->findById($sejour->getIdPatient());
        if ($patient === null) {
            return null;
        }

        $allergies = $this->allergieDao->findByPatient($patient->getIdPatient() ?? $sejour->getIdPatient());
        $services = $this->referenceDataDao?->healthcareServices() ?? [];

        return [
            'bandeau' => self::presenterBandeau($patient, $sejour, $allergies, $this->aujourdhui),
            'sejour' => self::presenterSejour($sejour, $patient, $services),
            'allergies' => self::presentAllergies($allergies),
            'substances' => self::availableSubstances($this->substanceDao->findAll(), $allergies),
            'criticalities' => Allergie::CRITICALITIES,
            // Sections du dossier complet (antécédents, histoire de la maladie, scores, mesures,
            // administratif) : une section vide reste affichée avec « Non renseigné ».
            'sections' => self::presentSections($patient, $sejour, $services),
            // Valeurs actuelles de chaque partie, pour les formulaires « Modifier » (RecordSectionService).
            'partValues' => self::partValues($patient, $sejour),
            'services' => $services,
        ];
    }

    /**
     * Vue-modèle du bandeau : identité (nom, prénom, date de naissance, IPP) et allergies
     * connues (RG2). Composant src/View/components/bandeau_patient.php.
     *
     * @param Allergie[] $allergies
     * @return array<string, mixed>
     */
    public static function presenterBandeau(
        Patient $patient,
        Sejour $sejour,
        array $allergies,
        DateTimeImmutable $aujourdhui
    ): array {
        $naissance = $patient->getDateNaissance();

        return [
            'nom' => trim($patient->getNom()),
            'prenom' => trim($patient->getPrenom()),
            'sexe' => self::texteOuNull($patient->getSexe()),
            'dateNaissance' => $naissance?->format('d/m/Y'),
            'age' => $naissance === null ? null : self::age($naissance, $aujourdhui),
            'ipp' => self::texteOuNull($patient->getIpp()),
            'numeroSejour' => trim($sejour->getNumSejour()) !== ''
                ? trim($sejour->getNumSejour())
                : (string) $sejour->getIdSejour(),
            'dateAdmission' => $sejour->getDateAdmission()->format('d/m/Y H:i'),
            // Mesures à l'admission, affichées dans le bandeau (dosage, matériel) ; null = non renseigné.
            'poids' => self::mesure($sejour->getPoids(), 'kg'),
            'taille' => self::mesure($sejour->getTaille(), 'cm'),
            // Groupe sanguin (Patient.bloodType) ; null = non renseigné.
            'groupeSanguin' => self::texteOuNull($patient->getDetail('bloodType')),
            'allergies' => array_values(array_map(
                static fn (Allergie $a): array => [
                    'libelle' => trim($a->getSubstance()),
                    'criticite' => self::texteOuNull($a->getCriticite()),
                    'niveau' => Allergie::criticalityLevel($a->getCriticite()),
                ],
                $allergies
            )),
            // 'known' (liste non vide) ou 'none'. L'état 'unknown' (« Allergies non renseignées ») existe
            // dans le composant mais aucune donnée ne permet encore de le produire : il faudrait une
            // colonne Patient.allergyStatus renseignée à l'admission (à proposer au responsable BDD).
            'allergiesStatus' => $allergies === [] ? 'none' : 'known',
        ];
    }

    /**
     * @param array<string, string> $services référentiel des services (id => nom) pour afficher le nom
     * @return array<string, mixed>
     */
    public static function presenterSejour(Sejour $sejour, Patient $patient, array $services = []): array
    {
        $sortie = $sejour->getDateSortie();
        $idService = self::texteOuNull($sejour->getIdService());

        return [
            'idSejour' => $sejour->getIdSejour(),
            'idPatient' => $sejour->getIdPatient(),
            'dateAdmission' => $sejour->getDateAdmission()->format('d/m/Y H:i'),
            'enCours' => $sortie === null,
            'dateSortie' => $sortie?->format('d/m/Y H:i'),
            // Nom du service si le référentiel le connaît, sinon l'identifiant brut (on ne cache rien).
            'service' => $idService === null ? null : ($services[$idService] ?? $idService),
            'motifAdmission' => self::texteOuNull($sejour->getDetail('admissionReason')),
            'lit' => self::texteOuNull($sejour->getNumLit()),
            'box' => self::texteOuNull($sejour->getNumBox()),
            // Mesures relevées à l'admission (AURA-10.1) ; null = non renseigné.
            'poids' => self::mesure($sejour->getPoids(), 'kg'),
            'taille' => self::mesure($sejour->getTaille(), 'cm'),
            'precautions' => self::texteOuNull($sejour->getPrecautions()),
            'telephone' => self::texteOuNull($patient->getTelephone()),
            'email' => self::texteOuNull($patient->getEmail()),
        ];
    }

    /**
     * Vue-modèle de la section « Allergies connues » du dossier (AURA-13.2) : l'id sert au
     * bouton « Retirer », la date de saisie distingue une allergie connue d'un séjour
     * antérieur d'une allergie découverte pendant le séjour en cours.
     *
     * @param Allergie[] $allergies
     * @return list<array{idAllergy: ?int, label: string, criticality: ?string, level: string, recordedAt: ?string}>
     */
    public static function presentAllergies(array $allergies): array
    {
        return array_values(array_map(
            static fn (Allergie $a): array => [
                'idAllergy' => $a->getIdAllergie(),
                'label' => trim($a->getSubstance()),
                'criticality' => self::texteOuNull($a->getCriticite()),
                'level' => Allergie::criticalityLevel($a->getCriticite()),
                'recordedAt' => $a->getDateSaisie()?->format('d/m/Y'),
            ],
            $allergies
        ));
    }

    /**
     * Allergènes proposés dans la liste d'ajout : le référentiel moins ceux que le patient
     * a déjà (ergonomie seulement : le service d'ajout refuse de toute façon les doublons).
     * L'ordre du référentiel (alphabétique) est conservé.
     *
     * @param Substance[] $substances
     * @param Allergie[] $allergies
     * @return array<string, string> libellé indexé par id d'allergène
     */
    public static function availableSubstances(array $substances, array $allergies): array
    {
        $known = array_map(static fn (Allergie $a): string => $a->getIdAllergene(), $allergies);

        $available = [];
        foreach ($substances as $substance) {
            if (!in_array($substance->getIdSubstance(), $known, true)) {
                $available[$substance->getIdSubstance()] = trim($substance->getDescription());
            }
        }

        return $available;
    }

    /**
     * Sections du dossier complet, dans l'ordre de RecordFields::SECTIONS. Chaque ligne porte
     * l'entité et le nom du champ (pour une édition ultérieure), le libellé, la valeur affichable
     * (null = non renseigné) et la valeur brute. Les valeurs calculées (IMC, surface corporelle,
     * poids théorique, Vt théorique) sont marquées `computed` : affichées, jamais saisies.
     *
     * Pure : aucune lecture en base, testable avec des entités construites à la main.
     *
     * @param array<string, string> $services référentiel des services (id => nom)
     * @return list<array{id: string, title: string, filled: int, total: int,
     *               rows: list<array<string, mixed>>}>
     */
    public static function presentSections(Patient $patient, Sejour $sejour, array $services = []): array
    {
        $sections = [];
        foreach (RecordFields::SECTIONS as $id => $title) {
            $rows = [];
            if ($id === 'admission') {
                $rows[] = self::row(
                    'episode',
                    'admissionReason',
                    RecordFields::EPISODE['admissionReason'],
                    $sejour->getDetail('admissionReason')
                );
                $idService = self::texteOuNull($sejour->getIdService());
                $rows[] = [
                    'entity' => 'episode', 'field' => 'idHealthcareService', 'label' => 'Service hospitalier',
                    'type' => 'select', 'options' => $services, 'computed' => false, 'help' => null,
                    'raw' => $idService,
                    'value' => $idService === null ? null : ($services[$idService] ?? $idService),
                ];
            }
            if ($id === 'measures') {
                $rows[] = [
                    'entity' => 'episode', 'field' => 'weight', 'label' => 'Poids à l\'admission (kg)',
                    'type' => 'number', 'options' => null, 'computed' => false, 'help' => null,
                    'raw' => $sejour->getPoids() === null ? null : (string) $sejour->getPoids(),
                    'value' => self::mesure($sejour->getPoids(), 'kg'),
                ];
                $rows[] = [
                    'entity' => 'episode', 'field' => 'height', 'label' => 'Taille (cm)',
                    'type' => 'number', 'options' => null, 'computed' => false, 'help' => null,
                    'raw' => $sejour->getTaille() === null ? null : (string) $sejour->getTaille(),
                    'value' => self::mesure($sejour->getTaille(), 'cm'),
                ];
                $derived = DerivedMeasuresService::present(
                    $sejour->getPoids(),
                    $sejour->getTaille(),
                    $patient->getSexe()
                );
                // Valeurs indicatives, formules adultes : une aide, pas une consigne de réglage.
                $labels = [
                    'bmi' => 'IMC (calculé, indicatif)',
                    'bodySurfaceArea' => 'Surface corporelle (calculée, Mosteller, indicative)',
                    'predictedBodyWeight' => 'Poids théorique (calculé, ARDSNet adulte, indicatif)',
                    'tidalVolume' => 'Vt théorique (6 à 8 ml/kg de poids théorique, indicatif)',
                ];
                foreach ($labels as $key => $label) {
                    $rows[] = [
                        'entity' => 'episode', 'field' => $key, 'label' => $label, 'type' => 'computed',
                        'options' => null, 'computed' => true, 'raw' => null,
                        'value' => $derived[$key]['value'],
                        'help' => $derived[$key]['reason'] === null
                            ? null
                            : 'Non calculable : ' . $derived[$key]['reason'] . '.',
                    ];
                }
            }
            foreach (RecordFields::PATIENT as $field => $spec) {
                if ($spec['section'] === $id) {
                    $rows[] = self::row('patient', $field, $spec, $patient->getDetail($field));
                }
            }
            foreach (RecordFields::EPISODE as $field => $spec) {
                if ($spec['section'] === $id && $field !== 'admissionReason') {
                    $rows[] = self::row('episode', $field, $spec, $sejour->getDetail($field));
                }
            }
            if ($id === 'admin') {
                $rows[] = [
                    'entity' => 'episode', 'field' => 'precautions', 'label' => 'Précautions', 'type' => 'textarea',
                    'options' => null, 'computed' => false, 'help' => null,
                    'raw' => self::texteOuNull($sejour->getPrecautions()),
                    'value' => self::texteOuNull($sejour->getPrecautions()),
                ];
            }

            $editable = array_filter($rows, static fn (array $r): bool => !$r['computed']);
            $sections[] = [
                'id' => $id,
                'title' => $title,
                'filled' => count(array_filter($editable, static fn (array $r): bool => $r['value'] !== null)),
                'total' => count($editable),
                'rows' => $rows,
            ];
        }

        return $sections;
    }

    /**
     * Ligne d'une section pour un champ du catalogue : la valeur affichée traduit la clé d'une
     * échelle en libellé et une date en jj/mm/aaaa.
     *
     * @param array<string, mixed> $spec entrée de RecordFields::PATIENT ou ::EPISODE
     * @return array<string, mixed>
     */
    private static function row(string $entity, string $field, array $spec, ?string $raw): array
    {
        $raw = self::texteOuNull($raw);
        $value = $raw;
        if ($raw !== null && $spec['type'] === 'select') {
            $value = $spec['options'][$raw] ?? $raw;
        } elseif ($raw !== null && $spec['type'] === 'date') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            $value = $date !== false ? $date->format('d/m/Y') : $raw;
        }

        return [
            'entity' => $entity,
            'field' => $field,
            'label' => $spec['label'],
            'type' => $spec['type'],
            'options' => $spec['options'] ?? null,
            'computed' => false,
            'help' => $spec['help'] ?? null,
            'placeholder' => $spec['placeholder'] ?? null,
            'raw' => $raw,
            'value' => $value,
        ];
    }

    /**
     * Valeurs actuelles de chaque partie modifiable du dossier (résumé et sections), au format des
     * formulaires (dates en JJ/MM/AAAA). Pure.
     *
     * @return array<string, array<string, string>>
     */
    public static function partValues(Patient $patient, Sejour $sejour): array
    {
        $parts = [RecordSectionService::PART_SUMMARY, ...array_keys(RecordFields::SECTIONS)];
        $values = [];
        foreach ($parts as $part) {
            $values[$part] = RecordSectionService::currentValues($part, $patient, $sejour);
        }

        return $values;
    }

    /** Âge révolu à la date donnée ; null si la date de naissance est dans le futur. */
    public static function age(DateTimeInterface $naissance, DateTimeInterface $aujourdhui): ?int
    {
        if ($naissance > $aujourdhui) {
            return null;
        }

        return $naissance->diff($aujourdhui)->y;
    }

    /** Mesure à la française, sans zéros inutiles : 62.5 → « 62,5 kg », 164.0 → « 164 cm ». */
    public static function mesure(?float $valeur, string $unite): ?string
    {
        if ($valeur === null) {
            return null;
        }
        $nombre = rtrim(rtrim(number_format($valeur, 2, ',', ''), '0'), ',');

        return $nombre . ' ' . $unite;
    }

    private static function texteOuNull(?string $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }
        $texte = trim($valeur);

        return $texte === '' ? null : $texte;
    }
}
