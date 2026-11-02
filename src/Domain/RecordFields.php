<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Catalogue des champs complémentaires du dossier patient (PR « admission-champs-complets »).
 *
 * Chaque entrée décrit un champ facultatif : sa section d'affichage, son type de saisie, ses
 * bornes et, pour les échelles, ses valeurs autorisées (ClinicalScales). Le nom de la clé est
 * à la fois le nom du champ dans le formulaire, la clé du domaine et la colonne SQL (nouveau
 * code en anglais HL7, décision du 2026-10-21) : les DAO n'ont donc aucune table de traduction
 * à maintenir pour ces champs.
 *
 * Ce catalogue est la liste blanche : un champ absent d'ici n'est jamais lu d'un formulaire
 * ni écrit en base (FieldValidationService, PatientDao, SejourDao).
 */
final class RecordFields
{
    /** Identifiants de sections, dans l'ordre d'affichage (formulaire d'admission et dossier). */
    public const SECTIONS = [
        'admission' => 'Admission',
        'history' => 'Antécédents',
        'disease' => 'Histoire de la maladie',
        'scores' => 'Scores et état antérieur',
        'measures' => 'Mesures',
        'admin' => 'Administratif complémentaire',
    ];

    /** Longueur maximale d'une note libre (colonne TEXT) : borne applicative, bien sous les 65 535 octets. */
    public const TEXT_MAX = 10000;

    /**
     * Champs stables au fil des séjours : table Patient.
     *
     * @var array<string, array{label: string, type: string, section: string, max?: int,
     *                          options?: array<string, string>, help?: string}>
     */
    public const PATIENT = [
        'allergyNote' => [
            'label' => 'Note libre sur les allergies', 'type' => 'textarea', 'section' => 'admission',
            'max' => self::TEXT_MAX,
            'help' => 'Allergie absente du référentiel, circonstances, réaction observée.',
        ],
        'medicalHistory' => [
            'label' => 'ATCD médicaux',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'surgicalHistory' => [
            'label' => 'ATCD chirurgicaux',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'obstetricHistory' => [
            'label' => 'ATCD obstétricaux',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'transfusionHistory' => [
            'label' => 'ATCD transfusionnels',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'vaccinationHistory' => [
            'label' => 'ATCD vaccinaux',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'anestheticHistory' => [
            'label' => 'ATCD anesthésiques',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'toxicHistory' => [
            'label' => 'ATCD toxiques',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'familySocialHistory' => [
            'label' => 'ATCD familiaux et sociaux',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'lifestyle' => [
            'label' => 'Mode de vie',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'foreignTravel' => [
            'label' => 'Voyages à l\'étranger',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'comorbidities' => [
            'label' => 'Comorbidités',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'currentTreatment' => [
            'label' => 'Traitement en cours',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'birthLastName' => ['label' => 'Nom de naissance', 'type' => 'text', 'section' => 'admin', 'max' => 50],
        'birthFirstName' => ['label' => 'Prénom de naissance', 'type' => 'text', 'section' => 'admin', 'max' => 50],
        'insNumber' => [
            'label' => 'Matricule INS', 'type' => 'text', 'section' => 'admin', 'max' => 20,
            'help' => 'Identifiant national de santé, tel qu\'il figure sur le document d\'identité.',
        ],
        'generalPractitioner' => ['label' => 'Médecin traitant', 'type' => 'text', 'section' => 'admin', 'max' => 100],
        'trustedPersonName' => [
            'label' => 'Personne de confiance',
            'type' => 'text',
            'section' => 'admin',
            'max' => 100,
        ],
        'trustedPersonPhone' => [
            'label' => 'Téléphone de la personne de confiance',
            'type' => 'tel',
            'section' => 'admin',
            'max' => 20,
        ],
        'trustedPersonEmail' => [
            'label' => 'E-mail de la personne de confiance',
            'type' => 'email',
            'section' => 'admin',
            'max' => 100,
        ],
        'dmpIdentifier' => [
            'label' => 'DMP (Dossier Médical Partagé)',
            'type' => 'text',
            'section' => 'admin',
            'max' => 100,
        ],
        'advanceDirectives' => [
            'label' => 'Directives anticipées',
            'type' => 'textarea',
            'section' => 'admin',
            'max' => self::TEXT_MAX,
        ],
    ];

    /**
     * Champs propres à une hospitalisation : table EpisodeOfCare. Le poids, la taille, les
     * précautions et le service sont des colonnes antérieures (AURA-10.1) gérées à part par
     * AdmissionService ; ils ne figurent pas ici.
     *
     * @var array<string, array{label: string, type: string, section: string, max?: int,
     *                          options?: array<string, string>, help?: string}>
     */
    public const EPISODE = [
        'admissionReason' => [
            'label' => 'Motif d\'admission',
            'type' => 'textarea',
            'section' => 'admission',
            'max' => 1000,
        ],
        'diseaseHistory1' => [
            'label' => 'Histoire de la maladie 1',
            'type' => 'textarea',
            'section' => 'disease',
            'max' => self::TEXT_MAX,
        ],
        'diseaseHistory2' => [
            'label' => 'Histoire de la maladie 2',
            'type' => 'textarea',
            'section' => 'disease',
            'max' => self::TEXT_MAX,
        ],
        'diseaseHistory3' => [
            'label' => 'Histoire de la maladie 3',
            'type' => 'textarea',
            'section' => 'disease',
            'max' => self::TEXT_MAX,
        ],
        'diseaseHistory4' => [
            'label' => 'Histoire de la maladie 4',
            'type' => 'textarea',
            'section' => 'disease',
            'max' => self::TEXT_MAX,
        ],
        'diseaseHistory5' => [
            'label' => 'Histoire de la maladie 5',
            'type' => 'textarea',
            'section' => 'disease',
            'max' => self::TEXT_MAX,
        ],
        'asaScore' => [
            'label' => 'Score ASA',
            'type' => 'select',
            'section' => 'scores',
            'options' => ClinicalScales::ASA,
        ],
        'nyhaClass' => [
            'label' => 'Classe NYHA',
            'type' => 'select',
            'section' => 'scores',
            'options' => ClinicalScales::NYHA,
        ],
        'mcCabeScore' => [
            'label' => 'Échelle de Mac Cabe (état antérieur)', 'type' => 'select', 'section' => 'scores',
            'options' => ClinicalScales::MCCABE,
        ],
        'knausScore' => [
            'label' => 'Knaus (état fonctionnel antérieur)', 'type' => 'select', 'section' => 'scores',
            'options' => ClinicalScales::KNAUS,
        ],
        'girScore' => [
            'label' => 'Score GIR (dépendance)',
            'type' => 'select',
            'section' => 'scores',
            'options' => ClinicalScales::GIR,
        ],
        'intermacsProfile' => [
            'label' => 'INTERMACS de base', 'type' => 'select', 'section' => 'scores',
            'options' => ClinicalScales::INTERMACS,
        ],
        'intubationCriteria' => [
            'label' => 'Critères d\'intubation',
            'type' => 'textarea',
            'section' => 'measures',
            'max' => self::TEXT_MAX,
        ],
        'surgeryDate' => ['label' => 'Chirurgie : date de l\'intervention', 'type' => 'date', 'section' => 'measures'],
        'materialLeftInPlace' => [
            'label' => 'Matériel laissé en place',
            'type' => 'textarea',
            'section' => 'measures',
            'max' => self::TEXT_MAX,
        ],
        'removableProstheses' => [
            'label' => 'Port de prothèses mobiles', 'type' => 'text', 'section' => 'measures', 'max' => 200,
            'help' => 'Ex. : dentier ; lunettes ; prothèses auditives.',
        ],
        'specialStatus' => ['label' => 'Statut spécial', 'type' => 'text', 'section' => 'admin', 'max' => 100],
        'whitePlan' => [
            'label' => 'Plan blanc', 'type' => 'textarea', 'section' => 'admin', 'max' => self::TEXT_MAX,
            'help' => 'À remplir par le médecin en charge uniquement en cas de plan blanc.',
        ],
        'completedBy' => [
            'label' => 'Document initialement complété par',
            'type' => 'text',
            'section' => 'admin',
            'max' => 100,
        ],
    ];

    /** @return list<string> noms de colonnes des champs complémentaires du patient */
    public static function patientColumns(): array
    {
        return array_keys(self::PATIENT);
    }

    /** @return list<string> noms de colonnes des champs complémentaires du séjour */
    public static function episodeColumns(): array
    {
        return array_keys(self::EPISODE);
    }
}
