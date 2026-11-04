<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\AllergyIntoleranceDao;
use Aura\Dao\PatientDao;
use Aura\Domain\AllergyIntolerance;
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

    /** Saisie et nombre de mots pris en compte au plus. */
    public const MAX_QUERY_LENGTH = 100;
    public const MAX_WORDS = 6;

    public const MODE_IPP = 'ipp';
    public const MODE_NAME = 'name';

    public function __construct(
        private PatientDao $patientDao,
        private ?AllergyIntoleranceDao $allergyIntoleranceDao = null,
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
        // Relecture adversariale du 2026-11-04 : saisie et nombre de mots bornés (des milliers de mots dépassaient
        // le nombre de paramètres d'une requête préparée). Un nom et un prénom tiennent dans MAX_WORDS mots.
        $parts = preg_split('/[\s\-\'’]+/u', trim(mb_substr($query, 0, self::MAX_QUERY_LENGTH))) ?: [];
        $words = array_values(array_filter(array_map('trim', $parts), static fn (string $w): bool => $w !== ''));

        return array_slice($words, 0, self::MAX_WORDS);
    }

    /** Une saisie composée uniquement de 6 à 9 chiffres est traitée comme un IPP. */
    public static function isIpp(string $query): bool
    {
        return preg_match(AdmissionService::IPP_PATTERN, trim($query)) === 1;
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
     * @return array{idPatient: int, lastName: string, firstName: string, birthLastName: ?string,
     *               birthDate: ?string, age: ?int, sex: ?string, ipp: ?string, lastEpisodeOfCare: ?string}
     *         dates en JJ/MM/AAAA ; lastEpisodeOfCare = date d'admission du dernier séjour
     */
    public static function presentResult(
        Patient $patient,
        ?DateTimeImmutable $lastAdmission,
        DateTimeImmutable $today
    ): array {
        $birth = $patient->getBirthDate();

        return [
            'idPatient' => (int) $patient->getIdPatient(),
            'lastName' => trim($patient->getLastName()),
            'firstName' => trim($patient->getFirstName()),
            'birthLastName' => self::textOrNull($patient->getDetail('birthLastName')),
            'birthDate' => $birth?->format('d/m/Y'),
            'age' => $birth === null ? null : PatientRecordService::age($birth, $today),
            'sex' => self::textOrNull($patient->getSex()),
            'ipp' => self::textOrNull($patient->getIpp()),
            'lastEpisodeOfCare' => $lastAdmission?->format('d/m/Y'),
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
            $key = mb_strtolower($r['lastName'] . '|' . $r['firstName']);
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
     * @return array{identity: array<string, ?string>, details: array<string, ?string>,
     *               allergies: list<array{idSubstance: string, label: string, criticality: ?string}>}|null
     *         identity : idPatient, lastName, firstName, ipp, birthDate (AAAA-MM-JJ), sex
     */
    public function record(int $idPatient): ?array
    {
        $patient = $this->patientDao->findById($idPatient);
        if ($patient === null) {
            return null;
        }
        $allergies = $this->allergyIntoleranceDao?->findByPatient($idPatient) ?? [];

        return [
            'identity' => [
                'idPatient' => (string) $patient->getIdPatient(),
                'lastName' => trim($patient->getLastName()),
                'firstName' => trim($patient->getFirstName()),
                'ipp' => self::textOrNull($patient->getIpp()),
                'birthDate' => $patient->getBirthDate()?->format(AdmissionService::FORMAT_DATE),
                'sex' => self::textOrNull($patient->getSex()),
            ],
            'details' => array_map(
                static fn (?string $v): ?string => self::textOrNull($v),
                array_intersect_key($patient->getDetails(), RecordFields::PATIENT)
                + array_fill_keys(array_keys(RecordFields::PATIENT), null)
            ),
            'allergies' => array_values(array_map(
                static fn (AllergyIntolerance $a): array => [
                    'idSubstance' => $a->getIdSubstance(),
                    'label' => trim($a->getSubstance()),
                    'criticality' => self::textOrNull($a->getCriticality()),
                ],
                $allergies
            )),
        ];
    }

    /** Date d'admission du dernier séjour du patient, null s'il n'en a aucun. */
    private function lastAdmission(Patient $patient): ?DateTimeImmutable
    {
        return $this->patientDao->lastAdmissionDate((int) $patient->getIdPatient());
    }

    /** Texte sans espaces de bord ; vide ou null = null. */
    private static function textOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
