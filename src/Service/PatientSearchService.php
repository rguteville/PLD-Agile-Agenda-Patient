<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AllergieDao;
use Aura\Dao\PatientDao;
use Aura\Domain\Allergie;
use Aura\Domain\Patient;
use Aura\Domain\RecordFields;
use DateTimeImmutable;

/**
 * Recherche d'un patient déjà connu du service à l'admission (PR « admission-recherche-patient »),
 * par IPP (prioritaire, résultat unique) ou par nom / prénom (liste à choisir explicitement).
 *
 * Identitovigilance : la recherche ne sélectionne jamais un patient ; elle renvoie de quoi
 * distinguer deux homonymes (nom, prénom, nom de naissance, date de naissance et âge, sexe,
 * IPP, dernier séjour) et rien d'autre (ni antécédents, ni allergies, ni notes). La fiche
 * complète n'est lue (record()) qu'après la confirmation de l'utilisateur.
 */
class PatientSearchService
{
    /** Longueur minimale d'une recherche par nom. */
    public const MIN_LENGTH = 2;

    /** Nombre maximal de résultats renvoyés ; au-delà, « Affinez votre recherche ». */
    public const LIMIT = 20;

    public const MODE_IPP = 'ipp';
    public const MODE_NAME = 'name';

    public function __construct(
        private PatientDao $patientDao,
        private ?AllergieDao $allergieDao = null,
        private ?DateTimeImmutable $today = null
    ) {
    }

    /**
     * Mots d'une saisie : découpés sur les espaces, tirets et apostrophes (« Jean-Pierre » →
     * Jean, Pierre ; « D'Arc » → D, Arc), vides retirés, doublons conservés sans effet.
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $parts = preg_split('/[\s\-\'’]+/u', trim($query)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $w): bool => $w !== ''));
    }

    /** Une saisie composée uniquement de 6 à 9 chiffres est traitée comme un IPP. */
    public static function isIpp(string $query): bool
    {
        return preg_match(AdmissionService::MOTIF_IPP, trim($query)) === 1;
    }

    /**
     * Résultats de la recherche. Saisie trop courte : liste vide, sans erreur.
     *
     * @return array{mode: string, results: list<array<string, mixed>>, truncated: bool, homonyms: bool}
     */
    public function search(string $query): array
    {
        $query = trim($query);
        $today = $this->today ?? new DateTimeImmutable('today');

        if (self::isIpp($query)) {
            $patient = $this->patientDao->findByIpp($query);
            $results = $patient === null ? [] : [self::presentResult($patient, $this->lastAdmission($patient), $today)];

            return ['mode' => self::MODE_IPP, 'results' => $results, 'truncated' => false, 'homonyms' => false];
        }

        $words = self::words($query);
        if (mb_strlen($query) < self::MIN_LENGTH || $words === []) {
            return ['mode' => self::MODE_NAME, 'results' => [], 'truncated' => false, 'homonyms' => false];
        }

        $rows = $this->patientDao->searchByWords($words, self::LIMIT + 1);
        $truncated = count($rows) > self::LIMIT;
        $rows = array_slice($rows, 0, self::LIMIT);
        $results = array_map(
            static fn (array $r): array => self::presentResult($r['patient'], $r['lastAdmission'], $today),
            $rows
        );

        return [
            'mode' => self::MODE_NAME,
            'results' => $results,
            'truncated' => $truncated,
            'homonyms' => self::hasHomonyms($results),
        ];
    }

    /**
     * Un résultat de recherche : seulement les éléments d'identité nécessaires pour distinguer
     * deux homonymes. Méthode pure.
     *
     * @return array{idPatient: int, nom: string, prenom: string, nomNaissance: ?string,
     *               dateNaissance: ?string, age: ?int, sexe: ?string, ipp: ?string, dernierSejour: ?string}
     */
    public static function presentResult(
        Patient $patient,
        ?DateTimeImmutable $lastAdmission,
        DateTimeImmutable $today
    ): array {
        $birth = $patient->getDateNaissance();

        return [
            'idPatient' => (int) $patient->getIdPatient(),
            'nom' => trim($patient->getNom()),
            'prenom' => trim($patient->getPrenom()),
            'nomNaissance' => self::textOrNull($patient->getDetail('birthLastName')),
            'dateNaissance' => $birth?->format('d/m/Y'),
            'age' => $birth === null ? null : DossierPatientService::age($birth, $today),
            'sexe' => self::textOrNull($patient->getSexe()),
            'ipp' => self::textOrNull($patient->getIpp()),
            'dernierSejour' => $lastAdmission?->format('d/m/Y'),
        ];
    }

    /**
     * Plusieurs résultats portent-ils le même nom et le même prénom ? Comparaison sans casse
     * (les variantes accentuées d'un même nom ne sont pas rapprochées : limite connue).
     *
     * @param list<array<string, mixed>> $results
     */
    public static function hasHomonyms(array $results): bool
    {
        $seen = [];
        foreach ($results as $r) {
            $key = mb_strtolower($r['nom'] . '|' . $r['prenom']);
            if (isset($seen[$key])) {
                return true;
            }
            $seen[$key] = true;
        }

        return false;
    }

    /**
     * Fiche complète d'un patient, lue après confirmation de la sélection : identité (clés du
     * domaine), champs complémentaires du catalogue et allergènes connus (ids + libellés).
     * Null si le patient n'existe pas.
     *
     * @return array{identite: array<string, ?string>, details: array<string, ?string>,
     *               allergies: list<array{idSubstance: string, libelle: string}>}|null
     */
    public function record(int $idPatient): ?array
    {
        $patient = $this->patientDao->findById($idPatient);
        if ($patient === null) {
            return null;
        }
        $allergies = $this->allergieDao?->findByPatient($idPatient) ?? [];

        return [
            'identite' => [
                'idPatient' => (string) $patient->getIdPatient(),
                'nom' => trim($patient->getNom()),
                'prenom' => trim($patient->getPrenom()),
                'ipp' => self::textOrNull($patient->getIpp()),
                'dateNaissance' => $patient->getDateNaissance()?->format(AdmissionService::FORMAT_DATE),
                'sexe' => self::textOrNull($patient->getSexe()),
            ],
            'details' => array_map(
                static fn (?string $v): ?string => self::textOrNull($v),
                array_intersect_key($patient->getDetails(), RecordFields::PATIENT)
                + array_fill_keys(array_keys(RecordFields::PATIENT), null)
            ),
            'allergies' => array_values(array_map(
                static fn (Allergie $a): array => [
                    'idSubstance' => $a->getIdAllergene(),
                    'libelle' => trim($a->getSubstance()),
                ],
                $allergies
            )),
        ];
    }

    private function lastAdmission(Patient $patient): ?DateTimeImmutable
    {
        return $this->patientDao->lastAdmissionDate((int) $patient->getIdPatient());
    }

    private static function textOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
