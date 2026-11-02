<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AllergieDao;
use Aura\Dao\AuditEventDao;
use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\Allergie;
use Aura\Domain\AuditEvent;
use Aura\Domain\Patient;
use Aura\Domain\RecordFields;
use Aura\Domain\Sejour;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

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
 */
class AdmissionService
{
    /** Valeurs de `sexe` acceptées (colonne Patient.sexe VARCHAR(1), cf. database/test.sql). */
    public const SEXES_AUTORISES = ['M', 'F'];

    /** Format attendu pour la date de naissance (AAAA-MM-JJ). */
    public const FORMAT_DATE = 'Y-m-d';

    /** IPP : 6 à 9 chiffres (colonne Patient.ipp INT avec CHECK entre 100000 et 999999999). */
    public const MOTIF_IPP = '/^\d{6,9}$/';

    /** Plus grande taille (cm) ou plus grand poids (kg) enregistrable : colonnes Sejour DECIMAL(5,2). */
    public const MESURE_MAX = 999.99;

    /** Longueur maximale des précautions, en caractères (colonne Sejour.precautions VARCHAR(500)). */
    public const PRECAUTIONS_LONGUEUR_MAX = 500;

    private FieldValidationService $fields;

    /**
     * @param DateTimeImmutable|null $aujourdhui date de référence (injectable pour les tests)
     * @param PatientDao|null $patientDao accès aux patients, requis par admettre() seulement
     * @param SejourService|null $sejourService ouverture du séjour, requis par admettre() seulement
     * @param Transaction|null $transaction patient + séjour validés ensemble, requis par admettre() seulement
     * @param AllergieDao|null $allergieDao enregistrement des allergies choisies à l'admission (facultatif)
     * @param AuditEventDao|null $auditEventDao trace du rattachement d'un séjour à un patient existant
     * @param RecordEditService|null $recordEditService mise à jour tracée, champ par champ, d'un patient existant
     */
    public function __construct(
        private ?DateTimeImmutable $aujourdhui = null,
        private ?PatientDao $patientDao = null,
        private ?SejourService $sejourService = null,
        private ?Transaction $transaction = null,
        private ?AllergieDao $allergieDao = null,
        private ?AuditEventDao $auditEventDao = null,
        private ?RecordEditService $recordEditService = null
    ) {
        $this->fields = new FieldValidationService($aujourdhui);
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
     * @param array<string, array<string, string>> $referentiels voir valider()
     * @return Sejour le séjour ouvert, avec son identifiant et son numéro
     * @throws InvalidArgumentException données invalides ou admission refusée (message affichable)
     * @throws LogicException si le service a été construit sans accès à la base
     */
    public function admettre(array $donnees, array $referentiels = []): Sejour
    {
        if ($this->patientDao === null || $this->sejourService === null || $this->transaction === null) {
            throw new LogicException('AdmissionService::admettre() requiert PatientDao, SejourService et Transaction.');
        }

        $erreurs = $this->valider($donnees, $referentiels);
        if ($erreurs !== []) {
            throw new InvalidArgumentException(implode(' ', $erreurs));
        }

        $patientDetails = $this->fields->normalize(RecordFields::PATIENT, $donnees);
        $patient = $this->construirePatient($donnees, $patientDetails);
        $poids = $this->mesure($donnees['poids'] ?? null);
        $taille = $this->mesure($donnees['taille'] ?? null);
        $precautions = $this->precautions($donnees['precautions'] ?? null);
        $idService = $this->texte($donnees['idService'] ?? null);
        $episodeDetails = $this->fields->normalize(RecordFields::EPISODE, $donnees);
        $allergies = $this->allergenes($donnees['allergies'] ?? null);
        $criticites = $this->criticites($donnees['allergyCriticalities'] ?? null);
        if ($allergies !== [] && $this->allergieDao === null) {
            throw new LogicException(
                'AdmissionService::admettre() requiert AllergieDao pour enregistrer des allergies.'
            );
        }
        $idSelectionne = $this->texte($donnees['idPatient'] ?? null);
        $idSelectionne = $idSelectionne !== '' ? (int) $idSelectionne : null;
        $modeRecherche = $this->texte($donnees['searchMode'] ?? null);
        $patientDao = $this->patientDao;
        $sejourService = $this->sejourService;
        $allergieDao = $this->allergieDao;

        return $this->transaction->run(
            function () use (
                $patient,
                $patientDao,
                $sejourService,
                $allergieDao,
                $poids,
                $taille,
                $precautions,
                $idService,
                $patientDetails,
                $episodeDetails,
                $allergies,
                $criticites,
                $idSelectionne,
                $modeRecherche
            ): Sejour {
                $rattachement = null;
                if ($idSelectionne !== null) {
                    $existant = $patientDao->findById($idSelectionne);
                    if ($existant === null) {
                        throw new InvalidArgumentException(
                            'Le patient sélectionné est introuvable : relancez la recherche.'
                        );
                    }
                    $idPatient = $idSelectionne;
                    $this->mettreAJourPatientSelectionne($existant, $patient, $patientDetails);
                    $rattachement = $modeRecherche === PatientSearchService::MODE_IPP
                        ? 'rattachement après recherche par IPP'
                        : 'rattachement après recherche par nom';
                } else {
                    $existant = $patient->getIpp() !== null ? $patientDao->findByIpp($patient->getIpp()) : null;
                    if ($existant !== null) {
                        $idPatient = (int) $existant->getIdPatient();
                        $this->completerPatient(
                            $existant,
                            array_filter($patientDetails, static fn (?string $v): bool => $v !== null)
                        );
                        $rattachement = 'rattachement par IPP saisi';
                    } else {
                        // Nouveau patient sans IPP saisi : un IPP est attribué automatiquement (retours
                        // du 2026-11-02), dans la même transaction que l'insertion.
                        if ($patient->getIpp() === null) {
                            $patient = self::avecIpp($patient, (string) $patientDao->nextIpp());
                        }
                        $idPatient = $patientDao->insert($patient);
                    }
                }

                $sejour = $sejourService->ouvrirSejour(
                    $idPatient,
                    poids: $poids,
                    taille: $taille,
                    precautions: $precautions,
                    idService: $idService !== '' ? $idService : null,
                    details: $episodeDetails
                );

                if ($rattachement !== null) {
                    $this->auditEventDao?->insert(new AuditEvent(
                        null,
                        AuditEvent::ENTITY_EPISODE,
                        (int) $sejour->getIdSejour(),
                        'idPatient',
                        null,
                        (string) $idPatient,
                        AuditEvent::AUTHOR_UNKNOWN,
                        detail: $rattachement
                    ));
                }

                if ($allergies !== [] && $allergieDao !== null) {
                    $connues = array_map(
                        static fn (Allergie $a): string => $a->getIdAllergene(),
                        $allergieDao->findByPatient($idPatient)
                    );
                    foreach ($allergies as $idSubstance) {
                        if (!in_array($idSubstance, $connues, true)) {
                            $allergieDao->insert(new Allergie(
                                null,
                                $idPatient,
                                $idSubstance,
                                '',
                                $criticites[$idSubstance] ?? null
                            ));
                        }
                    }
                }

                return $sejour;
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
    private function mettreAJourPatientSelectionne(Patient $existant, Patient $saisi, array $details): void
    {
        $idPatient = (int) $existant->getIdPatient();
        $avant = self::identite($existant);
        $apres = self::identite($saisi);
        $changements = array_filter(
            $apres,
            static fn (?string $v, string $k): bool => $v !== $avant[$k],
            ARRAY_FILTER_USE_BOTH
        );

        if ($changements !== []) {
            if (isset($changements['ipp']) && $changements['ipp'] !== null) {
                $porteur = $this->patientDao?->findByIpp($changements['ipp']);
                if ($porteur !== null && $porteur->getIdPatient() !== $idPatient) {
                    throw new InvalidArgumentException('Cet IPP est déjà utilisé par un autre patient.');
                }
            }
            $this->patientDao?->update(new Patient(
                idPatient: $idPatient,
                ipp: $saisi->getIpp(),
                nom: $saisi->getNom(),
                prenom: $saisi->getPrenom(),
                dateNaissance: $saisi->getDateNaissance(),
                sexe: $saisi->getSexe(),
                telephone: $existant->getTelephone(),
                email: $existant->getEmail(),
                details: $existant->getDetails()
            ));
            foreach ($changements as $champ => $valeur) {
                $this->auditEventDao?->insert(new AuditEvent(
                    null,
                    AuditEvent::ENTITY_PATIENT,
                    $idPatient,
                    self::IDENTITY_COLUMNS[$champ],
                    $avant[$champ],
                    $valeur,
                    AuditEvent::AUTHOR_UNKNOWN,
                    detail: 'modifié à l\'admission'
                ));
            }
        }

        $this->completerPatient($existant, $details);
    }

    /** Copie du patient avec l'IPP attribué (l'entité est immuable). */
    private static function avecIpp(Patient $p, string $ipp): Patient
    {
        return new Patient(
            idPatient: $p->getIdPatient(),
            ipp: $ipp,
            nom: $p->getNom(),
            prenom: $p->getPrenom(),
            dateNaissance: $p->getDateNaissance(),
            sexe: $p->getSexe(),
            telephone: $p->getTelephone(),
            email: $p->getEmail(),
            details: $p->getDetails()
        );
    }

    /** Colonnes SQL des champs d'identité, pour la trace (AuditEvent.field). */
    private const IDENTITY_COLUMNS = [
        'nom' => 'lastName',
        'prenom' => 'firstName',
        'ipp' => 'ipp',
        'dateNaissance' => 'birthDate',
        'sexe' => 'sex',
    ];

    /** @return array<string, ?string> identité comparable (clés du domaine) */
    private static function identite(Patient $p): array
    {
        return [
            'nom' => trim($p->getNom()),
            'prenom' => trim($p->getPrenom()),
            'ipp' => $p->getIpp(),
            'dateNaissance' => $p->getDateNaissance()?->format(self::FORMAT_DATE),
            'sexe' => $p->getSexe(),
        ];
    }

    /**
     * Champs complémentaires d'un patient existant : chaque valeur différente de la fiche est
     * enregistrée et tracée par RecordEditService ; sans ce service (tests), écriture directe.
     *
     * @param array<string, ?string> $details
     */
    private function completerPatient(Patient $existant, array $details): void
    {
        $idPatient = (int) $existant->getIdPatient();
        if ($this->recordEditService === null) {
            $this->patientDao?->updateDetails($idPatient, $details);
            return;
        }
        foreach ($details as $champ => $valeur) {
            $resultat = $this->recordEditService->update(
                RecordEditService::ENTITY_PATIENT,
                $idPatient,
                $champ,
                $valeur,
                $existant->getDetail($champ),
                AuditEvent::AUTHOR_UNKNOWN
            );
            $statut = $resultat['status'];
            if ($statut !== RecordEditService::STATUS_SAVED && $statut !== RecordEditService::STATUS_UNCHANGED) {
                // Rien ne doit passer inaperçu dans la transaction d'admission : elle est annulée.
                throw new InvalidArgumentException(
                    (string) ($resultat['message'] ?? 'La mise à jour du dossier existant a été refusée.')
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
    private function criticites(mixed $valeur): array
    {
        $criticites = [];
        foreach (is_array($valeur) ? $valeur : [] as $id => $criticite) {
            if (is_string($criticite) && trim($criticite) !== '') {
                $criticites[(string) $id] = trim($criticite);
            }
        }

        return $criticites;
    }

    /**
     * Identifiants d'allergènes choisis (liste à cases), dédoublonnés, sans valeur vide ni
     * non textuelle. Une valeur scalaire unique est acceptée (formulaire sans JavaScript).
     *
     * @return list<string>
     */
    private function allergenes(mixed $valeur): array
    {
        if ($valeur === null || $valeur === '') {
            return [];
        }
        $liste = is_array($valeur) ? $valeur : [$valeur];
        $ids = [];
        foreach ($liste as $id) {
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
    private function mesure(mixed $valeur): ?float
    {
        $texte = $this->texte($valeur);

        return $texte !== '' ? round((float) $texte, 2) : null;
    }

    /** Précautions nettoyées, fins de ligne normalisées en \n. Vide = null. */
    private function precautions(mixed $valeur): ?string
    {
        $texte = str_replace("\r\n", "\n", $this->texte($valeur));

        return $texte !== '' ? $texte : null;
    }

    /**
     * Construit l'entité à partir de données validées (champs vides = null).
     *
     * @param array<string, mixed> $donnees
     * @param array<string, ?string> $details champs complémentaires normalisés (RecordFields::PATIENT)
     */
    private function construirePatient(array $donnees, array $details = []): Patient
    {
        $ipp = $this->texte($donnees['ipp'] ?? null);
        $dateNaissance = $this->texte($donnees['dateNaissance'] ?? null);
        $sexe = $this->texte($donnees['sexe'] ?? null);

        return new Patient(
            idPatient: null,
            ipp: $ipp !== '' ? $ipp : null,
            nom: $this->texte($donnees['nom'] ?? null),
            prenom: $this->texte($donnees['prenom'] ?? null),
            dateNaissance: $dateNaissance !== ''
                ? DateTimeImmutable::createFromFormat('!' . self::FORMAT_DATE, $dateNaissance) ?: null
                : null,
            sexe: $sexe !== '' ? $sexe : null,
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
    public function validerPatientIdentite(array $donnees): array
    {
        $erreurs = [];

        if ($this->texte($donnees['nom'] ?? null) === '') {
            $erreurs['nom'] = 'Le nom est obligatoire.';
        }
        if ($this->texte($donnees['prenom'] ?? null) === '') {
            $erreurs['prenom'] = 'Le prénom est obligatoire.';
        }

        $erreurIpp = self::validerIpp($this->texte($donnees['ipp'] ?? null));
        if ($erreurIpp !== null) {
            $erreurs['ipp'] = $erreurIpp;
        }

        $dateNaissance = $this->texte($donnees['dateNaissance'] ?? null);
        if ($dateNaissance !== '') {
            $erreurDate = $this->validerDateNaissance($dateNaissance);
            if ($erreurDate !== null) {
                $erreurs['dateNaissance'] = $erreurDate;
            }
        }

        $sexe = $this->texte($donnees['sexe'] ?? null);
        if ($sexe !== '' && !in_array($sexe, self::SEXES_AUTORISES, true)) {
            $erreurs['sexe'] = 'Le sexe doit être M ou F.';
        }

        return $erreurs;
    }

    /**
     * Valide les données d'admission (RG1 : nom et prénom obligatoires, IPP facultatif).
     *
     * Clés attendues (celles du domaine) : nom, prenom, ipp, dateNaissance, sexe,
     * taille, poids, precautions, idService, allergies (liste d'identifiants d'allergènes),
     * allergyCriticalities (idSubstance => criticité de Allergie::CRITICALITIES ou vide)
     * et tous les champs facultatifs de RecordFields::PATIENT et ::EPISODE. Une clé absente
     * est traitée comme vide. Les messages ne reprennent jamais la valeur saisie (données de santé).
     *
     * @param array<string, mixed> $donnees
     * @param array<string, array<string, string>> $referentiels listes autorisées, lues en base par
     *        l'appelant : 'services' (idHealthcareService => nom) et 'substances' (idSubstance => libellé).
     *        Une liste absente désactive le contrôle d'appartenance correspondant (tests unitaires).
     * @return array<string, string> erreurs indexées par champ ; [] si les données sont valides
     */
    public function valider(array $donnees, array $referentiels = []): array
    {
        $erreurs = $this->validerPatientIdentite($donnees);
        $erreurs += $this->fields->validate(RecordFields::PATIENT, $donnees);
        $erreurs += $this->fields->validate(RecordFields::EPISODE, $donnees);

        $idService = $this->texte($donnees['idService'] ?? null);
        if ($idService !== '' && isset($referentiels['services']) && !isset($referentiels['services'][$idService])) {
            $erreurs['idService'] = 'Le service hospitalier choisi est inconnu.';
        }

        // Patient sélectionné dans la recherche : identifiant interne numérique (existence vérifiée à l'écriture).
        $idPatient = $this->texte($donnees['idPatient'] ?? null);
        if ($idPatient !== '' && preg_match('/^[1-9][0-9]{0,17}$/', $idPatient) !== 1) {
            $erreurs['idPatient'] = 'Le patient sélectionné est invalide : relancez la recherche.';
        }

        $criticites = $donnees['allergyCriticalities'] ?? [];
        if (!is_array($criticites)) {
            $erreurs['allergyCriticalities'] = 'La criticité des allergies est invalide.';
        } else {
            foreach ($criticites as $criticite) {
                $criticite = is_string($criticite) ? trim($criticite) : null;
                $inconnue = $criticite !== '' && !in_array($criticite, Allergie::CRITICALITIES, true);
                if ($criticite === null || $inconnue) {
                    $erreurs['allergyCriticalities'] = 'Criticité d\'allergie inconnue : choisissez Faible, Élevée '
                        . 'ou non évaluée.';
                    break;
                }
            }
        }

        $allergies = $donnees['allergies'] ?? null;
        if ($allergies !== null && $allergies !== '' && !is_array($allergies) && !is_string($allergies)) {
            $erreurs['allergies'] = 'La liste des allergènes est invalide.';
        } elseif (isset($referentiels['substances'])) {
            foreach ($this->allergenes($allergies) as $idSubstance) {
                if (!isset($referentiels['substances'][$idSubstance])) {
                    $erreurs['allergies'] = 'Un allergène choisi est inconnu du référentiel.';
                    break;
                }
            }
        }

        $taille = $this->texte($donnees['taille'] ?? null);
        if ($taille !== '' && !$this->estStrictementPositif($taille)) {
            $erreurs['taille'] = 'La taille doit être un nombre strictement positif.';
        } elseif ($taille !== '' && !$this->tientDansLaColonne($taille)) {
            $erreurs['taille'] = 'La taille doit être inférieure à 1000 cm.';
        }

        $poids = $this->texte($donnees['poids'] ?? null);
        if ($poids !== '' && !$this->estStrictementPositif($poids)) {
            $erreurs['poids'] = 'Le poids doit être un nombre strictement positif.';
        } elseif ($poids !== '' && !$this->tientDansLaColonne($poids)) {
            $erreurs['poids'] = 'Le poids doit être inférieur à 1000 kg.';
        }

        // precautions : texte libre facultatif, limité à la taille de la colonne.
        $precautions = $this->precautions($donnees['precautions'] ?? null);
        if ($precautions !== null && mb_strlen($precautions) > self::PRECAUTIONS_LONGUEUR_MAX) {
            $erreurs['precautions'] = sprintf(
                'Les précautions ne doivent pas dépasser %d caractères.',
                self::PRECAUTIONS_LONGUEUR_MAX
            );
        }

        return $erreurs;
    }

    /**
     * Message d'erreur si l'IPP saisi ne tient pas dans la colonne INT (6 à 9 chiffres), sinon null.
     * Un IPP vide est accepté : il est facultatif à l'admission (RG1). Partagé avec la recherche
     * par IPP (AURA-15) pour que les deux écrans appliquent la même règle.
     */
    public static function validerIpp(string $ipp): ?string
    {
        $ipp = trim($ipp);
        if ($ipp !== '' && preg_match(self::MOTIF_IPP, $ipp) !== 1) {
            return "L'IPP doit contenir entre 6 et 9 chiffres.";
        }

        return null;
    }

    /** Message d'erreur si la date n'est pas au format AAAA-MM-JJ ou n'est pas strictement passée, sinon null. */
    private function validerDateNaissance(string $valeur): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::FORMAT_DATE, $valeur);
        $anomalies = DateTimeImmutable::getLastErrors();
        $avecAnomalies = $anomalies !== false
            && ($anomalies['warning_count'] > 0 || $anomalies['error_count'] > 0);

        // Le contrôle aller-retour rejette les dates inexistantes (ex. 2026-13-45, 1990-02-30)
        // que createFromFormat accepterait en les décalant.
        if ($date === false || $avecAnomalies || $date->format(self::FORMAT_DATE) !== $valeur) {
            return 'La date de naissance est invalide (format attendu : AAAA-MM-JJ).';
        }

        $aujourdhui = ($this->aujourdhui ?? new DateTimeImmutable())->setTime(0, 0);
        if ($date >= $aujourdhui) {
            return 'La date de naissance doit être antérieure à la date du jour.';
        }

        return null;
    }

    private function estStrictementPositif(string $valeur): bool
    {
        return is_numeric($valeur) && (float) $valeur > 0;
    }

    /** Une fois arrondie au centième, la valeur tient dans DECIMAL(5,2) (sinon MariaDB la refuse). */
    private function tientDansLaColonne(string $valeur): bool
    {
        return round((float) $valeur, 2) <= self::MESURE_MAX;
    }

    /**
     * Normalise une valeur saisie en chaîne nettoyée.
     * Les valeurs non scalaires (tableau envoyé par un formulaire forgé) sont traitées comme vides.
     */
    private function texte(mixed $valeur): string
    {
        if (is_string($valeur)) {
            return trim($valeur);
        }
        if (is_int($valeur) || is_float($valeur)) {
            return (string) $valeur;
        }

        return '';
    }
}
