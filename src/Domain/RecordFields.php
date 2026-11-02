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
    /** Groupes sanguins ABO et rhésus D (colonne existante Patient.bloodType VARCHAR(3)). */
    public const BLOOD_TYPES = [
        'A+' => 'A+', 'A-' => 'A−', 'B+' => 'B+', 'B-' => 'B−',
        'AB+' => 'AB+', 'AB-' => 'AB−', 'O+' => 'O+', 'O-' => 'O−',
    ];

    public const PATIENT = [
        'bloodType' => [
            'label' => 'Groupe sanguin',
            'type' => 'select',
            'section' => 'admission',
            'options' => self::BLOOD_TYPES,
        ],
        'allergyNote' => [
            'placeholder' => 'Ex. : réaction cutanée au sparadrap',
            'label' => 'Note libre sur les allergies', 'type' => 'textarea', 'section' => 'admission',
            'max' => self::TEXT_MAX,
            'help' => 'Allergie absente du référentiel, circonstances, réaction observée.',
        ],
        'medicalHistory' => [
            'placeholder' => 'Ex. : HTA, diabète de type 2',
            'label' => 'ATCD médicaux',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'surgicalHistory' => [
            'placeholder' => 'Ex. : appendicectomie (2010)',
            'label' => 'ATCD chirurgicaux',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'obstetricHistory' => [
            'placeholder' => 'Ex. : deux grossesses, césarienne (2015)',
            'label' => 'ATCD obstétricaux',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'transfusionHistory' => [
            'placeholder' => 'Ex. : transfusion en 2019, sans incident',
            'label' => 'ATCD transfusionnels',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'vaccinationHistory' => [
            'placeholder' => 'Ex. : vaccinations à jour',
            'label' => 'ATCD vaccinaux',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'anestheticHistory' => [
            'placeholder' => 'Ex. : anesthésie générale sans incident (2010)',
            'label' => 'ATCD anesthésiques',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'toxicHistory' => [
            'placeholder' => 'Ex. : tabac sevré depuis 2019, alcool occasionnel',
            'label' => 'ATCD toxiques',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'familySocialHistory' => [
            'placeholder' => 'Ex. : vit en couple, deux enfants',
            'label' => 'ATCD familiaux et sociaux',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'lifestyle' => [
            'placeholder' => 'Ex. : autonome, marche quotidienne',
            'label' => 'Mode de vie',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'foreignTravel' => [
            'placeholder' => 'Ex. : séjour au Sénégal en septembre 2026',
            'label' => 'Voyages à l\'étranger',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'comorbidities' => [
            'placeholder' => 'Ex. : BPCO, insuffisance rénale chronique',
            'label' => 'Comorbidités',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'currentTreatment' => [
            'placeholder' => 'Ex. : amlodipine 5 mg le matin',
            'label' => 'Traitement en cours',
            'type' => 'textarea',
            'section' => 'history',
            'max' => self::TEXT_MAX,
        ],
        'birthLastName' => [
            'placeholder' => 'Ex. : MARTIN',
            'label' => 'Nom de naissance',
            'type' => 'text',
            'section' => 'admin',
            'max' => 50,
        ],
        'birthFirstName' => [
            'placeholder' => 'Ex. : Marie',
            'label' => 'Prénom de naissance',
            'type' => 'text',
            'section' => 'admin',
            'max' => 50,
        ],
        'insNumber' => [
            'placeholder' => '15 chiffres, ex. : 1 23 45 67 890 123 45',
            'label' => 'Matricule INS', 'type' => 'text', 'section' => 'admin', 'max' => 20,
            'help' => 'Identifiant national de santé, tel qu\'il figure sur le document d\'identité.',
        ],
        'generalPractitioner' => [
            'placeholder' => 'Ex. : Dr Lambert, Lyon 3e',
            'label' => 'Médecin traitant',
            'type' => 'text',
            'section' => 'admin',
            'max' => 100,
        ],
        'trustedPersonName' => [
            'placeholder' => 'Ex. : Paul Dupont (époux)',
            'label' => 'Personne de confiance',
            'type' => 'text',
            'section' => 'admin',
            'max' => 100,
        ],
        'trustedPersonPhone' => [
            'placeholder' => 'Ex. : 06 12 34 56 78',
            'label' => 'Téléphone de la personne de confiance',
            'type' => 'tel',
            'section' => 'admin',
            'max' => 20,
        ],
        'trustedPersonEmail' => [
            'placeholder' => 'Ex. : prenom.nom@exemple.fr',
            'label' => 'E-mail de la personne de confiance',
            'type' => 'email',
            'section' => 'admin',
            'max' => 100,
        ],
        'dmpIdentifier' => [
            'placeholder' => 'Ex. : identifiant DMP du patient',
            'label' => 'DMP (Dossier Médical Partagé)',
            'type' => 'text',
            'section' => 'admin',
            'max' => 100,
        ],
        'advanceDirectives' => [
            'placeholder' => 'Ex. : directives rédigées, confiées à la personne de confiance',
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
            'placeholder' => 'Ex. : détresse respiratoire aiguë',
            'label' => 'Motif d\'admission',
            'type' => 'textarea',
            'section' => 'admission',
            'max' => 1000,
        ],
        'diseaseHistory1' => [
            'placeholder' => 'Ex. : dyspnée croissante depuis trois jours, fièvre',
            'label' => 'Histoire de la maladie 1',
            'type' => 'textarea',
            'section' => 'disease',
            'max' => self::TEXT_MAX,
        ],
        'diseaseHistory2' => [
            'placeholder' => 'Suite de l\'histoire de la maladie',
            'label' => 'Histoire de la maladie 2',
            'type' => 'textarea',
            'section' => 'disease',
            'max' => self::TEXT_MAX,
        ],
        'diseaseHistory3' => [
            'placeholder' => 'Suite de l\'histoire de la maladie',
            'label' => 'Histoire de la maladie 3',
            'type' => 'textarea',
            'section' => 'disease',
            'max' => self::TEXT_MAX,
        ],
        'diseaseHistory4' => [
            'placeholder' => 'Suite de l\'histoire de la maladie',
            'label' => 'Histoire de la maladie 4',
            'type' => 'textarea',
            'section' => 'disease',
            'max' => self::TEXT_MAX,
        ],
        'diseaseHistory5' => [
            'placeholder' => 'Suite de l\'histoire de la maladie',
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
            'placeholder' => 'Ex. : épuisement respiratoire, troubles de la conscience',
            'label' => 'Critères d\'intubation',
            'type' => 'textarea',
            'section' => 'measures',
            'max' => self::TEXT_MAX,
        ],
        'surgeryDate' => ['label' => 'Chirurgie : date de l\'intervention', 'type' => 'date', 'section' => 'measures'],
        'materialLeftInPlace' => [
            'placeholder' => 'Ex. : cathéter veineux central jugulaire droit',
            'label' => 'Matériel laissé en place',
            'type' => 'textarea',
            'section' => 'measures',
            'max' => self::TEXT_MAX,
        ],
        'removableProstheses' => [
            'placeholder' => 'Ex. : dentier ; lunettes ; prothèses auditives',
            'label' => 'Port de prothèses mobiles', 'type' => 'text', 'section' => 'measures', 'max' => 200,
            'help' => 'Ex. : dentier ; lunettes ; prothèses auditives.',
        ],
        'specialStatus' => [
            'placeholder' => 'Ex. : patient sous tutelle',
            'label' => 'Statut spécial',
            'type' => 'text',
            'section' => 'admin',
            'max' => 100,
        ],
        'whitePlan' => [
            'placeholder' => 'Ex. : victime n° 12 du plan blanc du 02/11/2026',
            'label' => 'Plan blanc', 'type' => 'textarea', 'section' => 'admin', 'max' => self::TEXT_MAX,
            'help' => 'À remplir par le médecin en charge uniquement en cas de plan blanc.',
        ],
        'completedBy' => [
            'placeholder' => 'Ex. : Dr Morel',
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
