<?php

declare(strict_types=1);

namespace Aura\Domain;

/**
 * Échelles cliniques proposées à l'admission (scores et état antérieur), avec leurs valeurs
 * officielles. La valeur stockée est la clé (colonne VARCHAR(5) de EpisodeOfCare), le libellé
 * est celui affiché dans les listes déroulantes et le dossier.
 *
 * Chaque échelle cite sa source (règle du brief : aucune échelle inventée). Les libellés sont
 * des traductions abrégées ; en cas de doute, la source fait foi.
 */
final class ClinicalScales
{
    /**
     * ASA Physical Status Classification System (American Society of Anesthesiologists,
     * statement approuvé le 15/10/2014, amendé le 13/12/2020).
     * Source : https://www.asahq.org/standards-and-practice-parameters/
     *          statement-on-asa-physical-status-classification-system
     * Reproduction consultée : https://www.scientificspine.com/scores/asa-classification
     */
    public const ASA = [
        'I' => 'ASA I – patient en bonne santé',
        'II' => 'ASA II – maladie systémique légère',
        'III' => 'ASA III – maladie systémique sévère',
        'IV' => 'ASA IV – maladie systémique sévère, menace vitale constante',
        'V' => 'ASA V – patient moribond, survie improbable sans l\'intervention',
        'VI' => 'ASA VI – mort cérébrale déclarée, prélèvement d\'organes',
    ];

    /**
     * Classification fonctionnelle NYHA (New York Heart Association), classes I à IV.
     * Source : https://www.heart.org/en/health-topics/heart-failure/what-is-heart-failure/classes-of-heart-failure
     * Reproduction consultée : https://www.healio.com/cardiology/learn-the-heart/cardiology-review/topic-reviews/new-york-heart-association-nyha-functional-class
     */
    public const NYHA = [
        'I' => 'NYHA I – aucune limitation de l\'activité physique',
        'II' => 'NYHA II – symptômes à l\'effort modéré',
        'III' => 'NYHA III – symptômes à l\'effort minime',
        'IV' => 'NYHA IV – symptômes au repos',
    ];

    /**
     * Échelle de Mac Cabe (état de santé antérieur, pronostic défini dans les trois mois
     * précédant l'entrée en réanimation). Items 0, 1, 2 selon le guide IGS II / Mac Cabe /
     * Knaus de la SRLF (2011).
     * Source : https://www.srlf.org/wp-content/uploads/2015/11/2011_IGSII_Mac_Cabe_Knaus.doc
     * Copie PDF consultée : https://sofia.medicalistes.fr/spip/IMG/pdf/IGSII-_Mac_Cabe-Knaus_2011_.pdf
     */
    public const MCCABE = [
        '0' => '0 – pas de maladie mortelle',
        '1' => '1 – maladie mortelle à 5 ans',
        '2' => '2 – maladie mortelle à 1 an',
    ];

    /**
     * État fonctionnel antérieur de Knaus (activité et suivi médical dans les six mois précédant
     * l'admission), classes A à D. Même source SRLF que l'échelle de Mac Cabe.
     * Source : https://www.srlf.org/wp-content/uploads/2015/11/2011_IGSII_Mac_Cabe_Knaus.doc
     */
    public const KNAUS = [
        'A' => 'A – santé normale',
        'B' => 'B – limitation modérée (traitement chronique)',
        'C' => 'C – limitation importante (handicap à l\'effort, traitement anticancéreux, hémodialyse)',
        'D' => 'D – patient grabataire, restriction majeure',
    ];

    /**
     * Groupes iso-ressources de la grille AGGIR, GIR 1 (dépendance la plus forte) à GIR 6
     * (autonome). L'échelle officielle n'a pas de GIR 0 : le brief mentionnait « 0 à 6 »,
     * la valeur 0 n'est donc pas proposée.
     * Source : https://www.service-public.gouv.fr/particuliers/vosdroits/F1229
     */
    public const GIR = [
        '1' => 'GIR 1 – confiné au lit ou au fauteuil, fonctions mentales gravement altérées',
        '2' => 'GIR 2 – confiné au lit ou au fauteuil (fonctions mentales partiellement altérées) '
            . 'ou fonctions mentales altérées mais capable de se déplacer',
        '3' => 'GIR 3 – autonomie mentale conservée, aide corporelle plusieurs fois par jour',
        '4' => 'GIR 4 – transferts non assurés seul ou aide pour les activités corporelles et les repas',
        '5' => 'GIR 5 – aide ponctuelle (toilette, repas, ménage)',
        '6' => 'GIR 6 – autonome pour les actes essentiels de la vie courante',
    ];

    /**
     * Profils INTERMACS 1 à 7 (insuffisance cardiaque avancée).
     * Source : Stevenson LW et al. INTERMACS profiles of advanced heart failure: the current
     * picture. J Heart Lung Transplant. 2009;28(6):535-41. https://pubmed.ncbi.nlm.nih.gov/19481012/
     * Tableau des profils consulté : https://cdn.clinicaltrials.gov/large-docs/55/NCT02224755/Prot_001.pdf
     */
    public const INTERMACS = [
        '1' => 'Profil 1 – choc cardiogénique critique',
        '2' => 'Profil 2 – dégradation progressive sous inotropes',
        '3' => 'Profil 3 – stable mais dépendant des inotropes',
        '4' => 'Profil 4 – symptômes au repos',
        '5' => 'Profil 5 – intolérance à l\'effort',
        '6' => 'Profil 6 – effort limité',
        '7' => 'Profil 7 – NYHA III avancé',
    ];
}
