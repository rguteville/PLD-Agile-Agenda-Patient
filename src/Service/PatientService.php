<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Domain\Patient;
use Aura\Domain\EpisodeOfCare;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Admission des patients dans le service, première version (avant AURA-10.4).
 *
 * N'est pas câblé dans public/index.php : l'admission réelle passe par AdmissionService. Seul
 * tests/Service/PatientServiceTest l'utilise encore. Ses règles (patient sous X, e-mail de 100
 * caractères) diffèrent de celles d'AdmissionService.
 *
 * Le système fonctionne en autonome : la base ne contient que les patients ayant
 * déjà fait un séjour dans le service. Un « patient existant » est donc un patient
 * déjà venu, dont on peut consulter les séjours précédents. La mise en cohérence
 * avec le dossier patient (OpenEMR) sera faite plus tard.
 *
 * Parcours prévu côté interface :
 *  1. searchExistingPatient($data) ;
 *  2a. patient trouvé et lien validé par le personnel : admitExistingPatient($idPatient, $bedNumber, $boxNumber) ;
 *  2b. sinon : registerPatient($data).
 * Le séjour (lit, box, sortie…) est ensuite géré par EpisodeOfCareService.
 */
class PatientService
{
    public function __construct(
        private PatientDao $patientDao,
        private EpisodeOfCareService $episodeOfCareService,
        private Transaction $transaction
    ) {
    }

    /** @return Patient[] */
    public function getAllPatients(): array
    {
        return $this->patientDao->findAll();
    }

    /**
     * Patient par son identifiant interne.
     *
     * @throws \RuntimeException si le patient n'existe pas
     */
    public function getPatient(int $id): Patient
    {
        $patient = $this->patientDao->findById($id);

        if ($patient === null) {
            throw new \RuntimeException("Patient $id introuvable");
        }

        return $patient;
    }

    /**
     * Séjours précédents (et en cours) d'un patient, du plus récent au plus ancien.
     *
     * @return EpisodeOfCare[]
     */
    public function getPatientEpisodesOfCare(int $idPatient): array
    {
        return $this->episodeOfCareService->getPatientEpisodesOfCare($idPatient);
    }

    /**
     * Cherche si le patient décrit par le formulaire a déjà fait un séjour dans le service :
     * par IPP s'il est renseigné, sinon par nom + prénom + date de naissance.
     * Renvoie le patient seulement s'il est trouvé de façon unique. Jamais pour un patient sous X.
     *
     * @param array<string, mixed> $data
     */
    public function searchExistingPatient(array $data): ?Patient
    {
        $lastName = $this->text($data['lastName'] ?? null) ?? '';
        $firstName = $this->text($data['firstName'] ?? null) ?? '';
        if ($this->isUnderX($lastName, $firstName)) {
            return null;
        }

        $ipp = $this->text($data['ipp'] ?? null);
        if ($ipp !== null) {
            return $this->patientDao->findByIpp($ipp);
        }

        $birthDate = $this->parseDate($this->text($data['birthDate'] ?? null));
        if ($lastName === '' || $firstName === '' || $birthDate === null) {
            return null;
        }

        $foundItems = $this->patientDao->findByIdentity($lastName, $firstName, $birthDate);

        return count($foundItems) === 1 ? $foundItems[0] : null;
    }

    /**
     * Inscrit un nouveau patient et ouvre son séjour (date de prise en charge = maintenant).
     * Le formulaire peut indiquer le lit (bedNumber) et le box (boxNumber) attribués.
     * Patient et séjour sont créés ensemble : si le séjour est refusé, le patient n'est pas créé.
     *
     * @param array<string, mixed> $data données du formulaire
     * @throws InvalidArgumentException si les données sont invalides
     */
    public function registerPatient(array $data): EpisodeOfCare
    {
        $patient = $this->validateForm($data);

        if ($patient->getIpp() !== null && $this->patientDao->existsByIpp($patient->getIpp())) {
            throw new InvalidArgumentException(
                "Un patient avec l'IPP {$patient->getIpp()} a déjà fait un séjour dans le service : "
                . 'lier la prise en charge à son dossier'
            );
        }

        $bedNumber = $this->text($data['bedNumber'] ?? null);
        $boxNumber = $this->text($data['boxNumber'] ?? null);

        return $this->transaction->run(function () use ($patient, $bedNumber, $boxNumber): EpisodeOfCare {
            $idPatient = $this->patientDao->insert($patient);

            return $this->episodeOfCareService->openEpisodeOfCare($idPatient, $bedNumber, $boxNumber);
        });
    }

    /**
     * Ouvre un nouveau séjour pour un patient déjà venu dans le service,
     * après validation du lien par le personnel.
     *
     * @throws InvalidArgumentException si le patient n'existe pas, est déjà hospitalisé ou si le lit/box est pris
     */
    public function admitExistingPatient(
        int $idPatient,
        ?string $bedNumber = null,
        ?string $boxNumber = null
    ): EpisodeOfCare {
        if ($this->patientDao->findById($idPatient) === null) {
            throw new InvalidArgumentException("Patient $idPatient introuvable");
        }

        return $this->transaction->run(
            fn(): EpisodeOfCare => $this->episodeOfCareService->openEpisodeOfCare($idPatient, $bedNumber, $boxNumber)
        );
    }

    /**
     * Contrôle les données du formulaire et construit le patient. Patient sous X (nom et prénom « X ») :
     * date de naissance facultative, IPP interdit.
     *
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException au premier champ invalide (message affichable)
     */
    private function validateForm(array $data): Patient
    {
        $lastName = $this->text($data['lastName'] ?? null) ?? '';
        $firstName = $this->text($data['firstName'] ?? null) ?? '';
        $sex = strtoupper($this->text($data['sex'] ?? null) ?? '');
        $birthDateInput = $this->text($data['birthDate'] ?? null);
        $ipp = $this->text($data['ipp'] ?? null);
        $phone = $this->text($data['phone'] ?? null);
        $email = $this->text($data['email'] ?? null);
        $underX = $this->isUnderX($lastName, $firstName);

        if ($lastName === '' || $firstName === '') {
            throw new InvalidArgumentException(
                'Le nom et le prénom du patient sont obligatoires (indiquer X si l\'identité est inconnue)'
            );
        }
        if (!preg_match('/^.{1,50}$/u', $lastName) || !preg_match('/^.{1,50}$/u', $firstName)) {
            throw new InvalidArgumentException('Le nom et le prénom ne doivent pas dépasser 50 caractères');
        }
        if (!in_array($sex, ['M', 'F'], true)) {
            throw new InvalidArgumentException('Le sexe doit être M ou F');
        }

        // Patient sous X : identité inconnue, la date de naissance peut manquer.
        $birthDate = null;
        if ($birthDateInput !== null) {
            $birthDate = $this->parseDate($birthDateInput);
            if ($birthDate === null) {
                throw new InvalidArgumentException('La date de naissance est invalide (format AAAA-MM-JJ)');
            }
            if ($birthDate > new DateTimeImmutable()) {
                throw new InvalidArgumentException('La date de naissance ne peut pas être dans le futur');
            }
        } elseif (!$underX) {
            throw new InvalidArgumentException('La date de naissance est obligatoire');
        }

        if ($ipp !== null) {
            if ($underX) {
                throw new InvalidArgumentException('Un patient sous X ne peut pas avoir d\'IPP');
            }
            if (!preg_match('/^\d{6,9}$/', $ipp)) {
                throw new InvalidArgumentException("L'IPP doit contenir entre 6 et 9 chiffres");
            }
        }
        if ($phone !== null && !preg_match('/^\d{10}$/', $phone)) {
            throw new InvalidArgumentException('Le téléphone doit contenir 10 chiffres');
        }
        if ($email !== null && (strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException("L'adresse email est invalide");
        }

        return new Patient(
            idPatient: null,
            ipp: $ipp,
            lastName: $underX ? 'X' : $lastName,
            firstName: $underX ? 'X' : $firstName,
            birthDate: $birthDate,
            sex: $sex,
            phone: $phone,
            email: $email
        );
    }

    /** Patient sous X : nom et prénom valent « X » (sans casse), identité volontairement inconnue. */
    private function isUnderX(string $lastName, string $firstName): bool
    {
        return strtoupper($lastName) === 'X' && strtoupper($firstName) === 'X';
    }

    /** Date au format AAAA-MM-JJ, ou null si absente ou invalide (ex. 1980-02-31). */
    private function parseDate(?string $input): ?DateTimeImmutable
    {
        if ($input === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input);

        return $date !== false && $date->format('Y-m-d') === $input ? $date : null;
    }

    /** Nettoie une valeur saisie : null si absente ou vide. */
    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
