<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\PatientDao;
use Aura\Dao\ReferenceDataDao;
use Aura\Dao\EpisodeOfCareDao;
use Aura\Dao\SubstanceDao;
use Aura\Domain\AllergyIntolerance;
use Aura\Domain\Patient;
use Aura\Domain\Practitioner;
use Aura\Domain\RecordFields;
use Aura\Domain\EpisodeOfCare;
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
final class PatientRecordService
{
    public function __construct(
        private EpisodeOfCareDao $episodeOfCareDao,
        private PatientDao $patientDao,
        private AllergyIntoleranceDao $allergyIntoleranceDao,
        private SubstanceDao $substanceDao,
        private DateTimeImmutable $today,
        private ?ReferenceDataDao $referenceDataDao = null,
    ) {
    }

    /**
     * @return array{bandeau: array<string, mixed>, sejour: array<string, mixed>,
     *               allergies: list<array<string, mixed>>, substances: array<string, string>,
     *               criticalities: list<string>}|null
     *         null si le séjour n'existe pas (ou si son patient a disparu : données incohérentes)
     */
    public function record(int $idEpisodeOfCare): ?array
    {
        $episodeOfCare = $this->episodeOfCareDao->findById($idEpisodeOfCare);
        if ($episodeOfCare === null) {
            return null;
        }

        $patient = $this->patientDao->findById($episodeOfCare->getIdPatient());
        if ($patient === null) {
            return null;
        }

        $allergies = $this->allergyIntoleranceDao->findByPatient(
            $patient->getIdPatient() ?? $episodeOfCare->getIdPatient()
        );
        $services = $this->referenceDataDao?->healthcareServices() ?? [];
        // Médecins proposés comme référent (formulaires « Modifier ») ; le nom affiché du référent actuel
        // vient du séjour lui-même (jointure du DAO), même si ce praticien n'est plus dans la liste.
        $doctors = $this->referenceDataDao?->doctors() ?? [];
        $idReferent = $episodeOfCare->getIdReferringPractitioner();
        if ($idReferent !== null && !isset($doctors[$idReferent])) {
            // Référent actuel absent de la liste (rôle changé depuis) : gardé dans les choix, sinon la liste
            // retomberait sur « À désigner » et une modification d'un autre champ de la partie l'effacerait.
            $doctors[$idReferent] = self::referent($episodeOfCare) ?? $idReferent;
        }

        // Tous les séjours du patient (retours des médecins du 2026-11-03) : historique dans le dossier et,
        // si le séjour affiché est clos alors qu'un autre est en cours, encart qui mène au séjour en cours.
        $episodes = $this->episodeOfCareDao->findByPatient($episodeOfCare->getIdPatient());
        $ongoing = EpisodeHistoryService::ongoing($episodes);

        return [
            'banner' => self::presentBanner($patient, $episodeOfCare, $allergies, $this->today),
            'episodeOfCare' => self::presentEpisodeOfCare($episodeOfCare, $patient, $services),
            'episodeHistory' => EpisodeHistoryService::present($episodes, $services, $idEpisodeOfCare),
            'currentEpisode' => $ongoing !== null && $ongoing->getIdEpisodeOfCare() !== $idEpisodeOfCare
                ? EpisodeHistoryService::presentCurrent($ongoing, $services)
                : null,
            'allergies' => self::presentAllergies($allergies),
            'substances' => self::availableSubstances($this->substanceDao->findAll(), $allergies),
            'criticalities' => AllergyIntolerance::CRITICALITIES,
            // Sections du dossier complet (antécédents, histoire de la maladie, scores, mesures,
            // administratif) : une section vide reste affichée avec « Non renseigné ».
            'sections' => self::presentSections($patient, $episodeOfCare, $services, $doctors),
            // Valeurs actuelles de chaque partie, pour les formulaires « Modifier » (RecordSectionService).
            'partValues' => self::partValues($patient, $episodeOfCare),
            'services' => $services,
            'referringDoctors' => $doctors,
        ];
    }

    /**
     * « Dr Prénom Nom » du médecin référent du séjour (responsable de soin), ou null s'il est à désigner
     * (admission pendant une garde). Pure ; partagée par le bandeau, le dossier et l'agenda du service.
     */
    public static function referent(EpisodeOfCare $episodeOfCare): ?string
    {
        $name = self::textOrNull($episodeOfCare->getReferringPractitionerName());

        return $name === null ? null : Practitioner::doctorTitle($name);
    }

    /** Patient déclaré décédé (Patient.deceased = 1) ; null ou 0 = non. Pure. */
    public static function isDeceased(Patient $patient): bool
    {
        return self::textOrNull($patient->getDetail('deceased')) === '1';
    }

    /** « Décédée », « Décédé » ou « Décédé(e) » selon le sexe renseigné. Pure. */
    public static function deceasedLabel(?string $sex): string
    {
        return match (self::textOrNull($sex)) {
            'F' => 'Décédée',
            'M' => 'Décédé',
            default => 'Décédé(e)',
        };
    }

    /**
     * Vue-modèle du bandeau : identité (nom, prénom, date de naissance, IPP) et allergies
     * connues (RG2). Composant src/View/components/patient_banner.php.
     *
     * @param Allergie[] $allergies
     * @return array<string, mixed>
     */
    public static function presentBanner(
        Patient $patient,
        EpisodeOfCare $episodeOfCare,
        array $allergies,
        DateTimeImmutable $today
    ): array {
        $birth = $patient->getBirthDate();

        $deceased = self::isDeceased($patient);

        return [
            'lastName' => trim($patient->getLastName()),
            'firstName' => trim($patient->getFirstName()),
            'sex' => self::textOrNull($patient->getSex()),
            // Décès (retours des médecins du 2026-11-03) : texte + icône dans le bandeau, jamais la couleur seule.
            'deceased' => $deceased,
            'deceasedLabel' => $deceased ? self::deceasedLabel($patient->getSex()) : null,
            'birthDate' => $birth?->format('d/m/Y'),
            'age' => $birth === null ? null : self::age($birth, $today),
            'ipp' => self::textOrNull($patient->getIpp()),
            'episodeNumber' => trim($episodeOfCare->getEpisodeNumber()) !== ''
                ? trim($episodeOfCare->getEpisodeNumber())
                : (string) $episodeOfCare->getIdEpisodeOfCare(),
            'admissionDate' => $episodeOfCare->getAdmissionDate()->format('d/m/Y H:i'),
            // Mesures à l'admission, affichées dans le bandeau (dosage, matériel) ; null = non renseigné.
            'weight' => self::measure($episodeOfCare->getWeight(), 'kg'),
            'height' => self::measure($episodeOfCare->getHeight(), 'cm'),
            // Groupe sanguin (Patient.bloodType) ; null = non renseigné.
            'bloodType' => self::textOrNull($patient->getDetail('bloodType')),
            // Médecin référent (« Dr Prénom Nom ») ; null = à désigner, mis en évidence par le bandeau.
            'referent' => self::referent($episodeOfCare),
            'allergies' => array_values(array_map(
                static fn (AllergyIntolerance $a): array => [
                    'label' => trim($a->getSubstance()),
                    'criticality' => self::textOrNull($a->getCriticality()),
                    'level' => AllergyIntolerance::criticalityLevel($a->getCriticality()),
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
    public static function presentEpisodeOfCare(
        EpisodeOfCare $episodeOfCare,
        Patient $patient,
        array $services = []
    ): array {
        $sortie = $episodeOfCare->getDischargeDate();
        $idService = self::textOrNull($episodeOfCare->getIdService());

        return [
            'idEpisodeOfCare' => $episodeOfCare->getIdEpisodeOfCare(),
            'idPatient' => $episodeOfCare->getIdPatient(),
            'admissionDate' => $episodeOfCare->getAdmissionDate()->format('d/m/Y H:i'),
            'ongoing' => $sortie === null,
            'dischargeDate' => $sortie?->format('d/m/Y H:i'),
            // Nom du service si le référentiel le connaît, sinon l'identifiant brut (on ne cache rien).
            'service' => $idService === null ? null : ($services[$idService] ?? $idService),
            // Médecin référent (« Dr Prénom Nom ») ; null = à désigner.
            'referent' => self::referent($episodeOfCare),
            'admissionReason' => self::textOrNull($episodeOfCare->getDetail('admissionReason')),
            'bed' => self::textOrNull($episodeOfCare->getBedNumber()),
            'box' => self::textOrNull($episodeOfCare->getBoxNumber()),
            // Mesures relevées à l'admission (AURA-10.1) ; null = non renseigné.
            'weight' => self::measure($episodeOfCare->getWeight(), 'kg'),
            'height' => self::measure($episodeOfCare->getHeight(), 'cm'),
            'precautions' => self::textOrNull($episodeOfCare->getPrecautions()),
            'phone' => self::textOrNull($patient->getPhone()),
            'email' => self::textOrNull($patient->getEmail()),
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
            static fn (AllergyIntolerance $a): array => [
                'idAllergy' => $a->getIdAllergyIntolerance(),
                'label' => trim($a->getSubstance()),
                'criticality' => self::textOrNull($a->getCriticality()),
                'level' => AllergyIntolerance::criticalityLevel($a->getCriticality()),
                'recordedAt' => $a->getRecordedAt()?->format('d/m/Y'),
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
        $known = array_map(static fn (AllergyIntolerance $a): string => $a->getIdSubstance(), $allergies);

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
     * @param array<string, string> $doctors médecins pouvant être référents (id => « Dr Prénom Nom »)
     * @return list<array{id: string, title: string, filled: int, total: int,
     *               rows: list<array<string, mixed>>}>
     */
    public static function presentSections(
        Patient $patient,
        EpisodeOfCare $episodeOfCare,
        array $services = [],
        array $doctors = []
    ): array {
        $sections = [];
        foreach (RecordFields::SECTIONS as $id => $title) {
            $rows = [];
            if ($id === 'admission') {
                $rows[] = self::row(
                    'episode',
                    'admissionReason',
                    RecordFields::EPISODE['admissionReason'],
                    $episodeOfCare->getDetail('admissionReason')
                );
                $idService = self::textOrNull($episodeOfCare->getIdService());
                $rows[] = [
                    'entity' => 'episode', 'field' => 'idHealthcareService', 'label' => 'Service hospitalier',
                    'type' => 'select', 'options' => $services, 'computed' => false, 'help' => null,
                    'raw' => $idService,
                    'value' => $idService === null ? null : ($services[$idService] ?? $idService),
                ];
                $rows[] = [
                    'entity' => 'episode', 'field' => 'idReferringPractitioner', 'label' => 'Médecin référent',
                    'type' => 'select', 'options' => $doctors, 'computed' => false,
                    'help' => null, 'placeholder' => null, 'emptyLabel' => 'À désigner',
                    'raw' => self::textOrNull($episodeOfCare->getIdReferringPractitioner()),
                    'value' => self::referent($episodeOfCare),
                ];
            }
            if ($id === 'measures') {
                $rows[] = [
                    'entity' => 'episode', 'field' => 'weight', 'label' => 'Poids à l\'admission (kg)',
                    'type' => 'number', 'options' => null, 'computed' => false, 'help' => null,
                    'raw' => $episodeOfCare->getWeight() === null ? null : (string) $episodeOfCare->getWeight(),
                    'value' => self::measure($episodeOfCare->getWeight(), 'kg'),
                ];
                $rows[] = [
                    'entity' => 'episode', 'field' => 'height', 'label' => 'Taille (cm)',
                    'type' => 'number', 'options' => null, 'computed' => false, 'help' => null,
                    'raw' => $episodeOfCare->getHeight() === null ? null : (string) $episodeOfCare->getHeight(),
                    'value' => self::measure($episodeOfCare->getHeight(), 'cm'),
                ];
                $derived = DerivedMeasuresService::present(
                    $episodeOfCare->getWeight(),
                    $episodeOfCare->getHeight(),
                    $patient->getSex()
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
                    $rows[] = self::row('episode', $field, $spec, $episodeOfCare->getDetail($field));
                }
            }
            if ($id === 'admin') {
                $rows[] = [
                    'entity' => 'episode', 'field' => 'precautions', 'label' => 'Précautions', 'type' => 'textarea',
                    'options' => null, 'computed' => false, 'help' => null,
                    'raw' => self::textOrNull($episodeOfCare->getPrecautions()),
                    'value' => self::textOrNull($episodeOfCare->getPrecautions()),
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
        $raw = self::textOrNull($raw);
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
            // Champ réservé à la modification d'une partie validée par un médecin (décès) : pas d'édition en ligne.
            'validatedByDoctor' => $spec['validatedByDoctor'] ?? false,
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
    public static function partValues(Patient $patient, EpisodeOfCare $episodeOfCare): array
    {
        $parts = [RecordSectionService::PART_SUMMARY, ...array_keys(RecordFields::SECTIONS)];
        $values = [];
        foreach ($parts as $part) {
            $values[$part] = RecordSectionService::currentValues($part, $patient, $episodeOfCare);
        }

        return $values;
    }

    /** Âge révolu à la date donnée ; null si la date de naissance est dans le futur. */
    public static function age(DateTimeInterface $birth, DateTimeInterface $today): ?int
    {
        if ($birth > $today) {
            return null;
        }

        return $birth->diff($today)->y;
    }

    /** Mesure à la française, sans zéros inutiles : 62.5 → « 62,5 kg », 164.0 → « 164 cm ». */
    public static function measure(?float $value, string $unit): ?string
    {
        if ($value === null) {
            return null;
        }
        $number = rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');

        return $number . ' ' . $unit;
    }

    private static function textOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
