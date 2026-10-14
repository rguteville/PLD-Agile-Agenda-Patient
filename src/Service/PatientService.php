<?php

declare(strict_types=1);

namespace Aura\Service;

use Aura\Dao\PatientDao;
use Aura\Dao\Transaction;
use Aura\Model\Patient;
use Aura\Model\Sejour;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Admission des patients dans le service.
 *
 * Le système fonctionne en autonome : la base ne contient que les patients ayant
 * déjà fait un séjour dans le service. Un « patient existant » est donc un patient
 * déjà venu, dont on peut consulter les séjours précédents. La mise en cohérence
 * avec le dossier patient (OpenEMR) sera faite plus tard.
 *
 * Parcours prévu côté interface :
 *  1. rechercherPatientExistant($formulaire) ;
 *  2a. patient trouvé et lien validé par le personnel : admettrePatientExistant($idPatient, $lit, $box) ;
 *  2b. sinon : inscrirePatient($formulaire).
 * Le séjour (lit, box, sortie…) est ensuite géré par SejourService.
 */
class PatientService
{
    public function __construct(
        private PatientDao $patientDao,
        private SejourService $sejourService,
        private Transaction $transaction
    ) {
    }

    /** @return Patient[] */
    public function getAllPatients(): array
    {
        return $this->patientDao->findAll();
    }

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
     * @return Sejour[]
     */
    public function getSejoursPatient(int $idPatient): array
    {
        return $this->sejourService->getSejoursPatient($idPatient);
    }

    /**
     * Cherche si le patient décrit par le formulaire a déjà fait un séjour dans le service :
     * par IPP s'il est renseigné, sinon par nom + prénom + date de naissance.
     * Renvoie le patient seulement s'il est trouvé de façon unique. Jamais pour un patient sous X.
     *
     * @param array<string, mixed> $data
     */
    public function rechercherPatientExistant(array $data): ?Patient
    {
        $nom = $this->texte($data['nom'] ?? null) ?? '';
        $prenom = $this->texte($data['prenom'] ?? null) ?? '';
        if ($this->estSousX($nom, $prenom)) {
            return null;
        }

        $ipp = $this->texte($data['ipp'] ?? null);
        if ($ipp !== null) {
            return $this->patientDao->findByIpp($ipp);
        }

        $dateNaissance = $this->parserDate($this->texte($data['date_naissance'] ?? null));
        if ($nom === '' || $prenom === '' || $dateNaissance === null) {
            return null;
        }

        $trouves = $this->patientDao->findByIdentite($nom, $prenom, $dateNaissance);

        return count($trouves) === 1 ? $trouves[0] : null;
    }

    /**
     * Inscrit un nouveau patient et ouvre son séjour (date de prise en charge = maintenant).
     * Le formulaire peut indiquer le lit (num_lit) et le box (num_box) attribués.
     * Patient et séjour sont créés ensemble : si le séjour est refusé, le patient n'est pas créé.
     *
     * @param array<string, mixed> $data données du formulaire
     * @throws InvalidArgumentException si les données sont invalides
     */
    public function inscrirePatient(array $data): Sejour
    {
        $patient = $this->validerFormulaire($data);

        if ($patient->getIpp() !== null && $this->patientDao->existsByIpp($patient->getIpp())) {
            throw new InvalidArgumentException(
                "Un patient avec l'IPP {$patient->getIpp()} a déjà fait un séjour dans le service : "
                . 'lier la prise en charge à son dossier'
            );
        }

        $numLit = $this->texte($data['num_lit'] ?? null);
        $numBox = $this->texte($data['num_box'] ?? null);

        return $this->transaction->run(function () use ($patient, $numLit, $numBox): Sejour {
            $idPatient = $this->patientDao->insert($patient);

            return $this->sejourService->ouvrirSejour($idPatient, $numLit, $numBox);
        });
    }

    /**
     * Ouvre un nouveau séjour pour un patient déjà venu dans le service,
     * après validation du lien par le personnel.
     *
     * @throws InvalidArgumentException si le patient n'existe pas, est déjà hospitalisé ou si le lit/box est pris
     */
    public function admettrePatientExistant(int $idPatient, ?string $numLit = null, ?string $numBox = null): Sejour
    {
        if ($this->patientDao->findById($idPatient) === null) {
            throw new InvalidArgumentException("Patient $idPatient introuvable");
        }

        return $this->transaction->run(
            fn(): Sejour => $this->sejourService->ouvrirSejour($idPatient, $numLit, $numBox)
        );
    }

    /**
     * Contrôle les données du formulaire et construit le patient.
     *
     * @param array<string, mixed> $data
     */
    private function validerFormulaire(array $data): Patient
    {
        $nom = $this->texte($data['nom'] ?? null) ?? '';
        $prenom = $this->texte($data['prenom'] ?? null) ?? '';
        $sexe = strtoupper($this->texte($data['sexe'] ?? null) ?? '');
        $dateSaisie = $this->texte($data['date_naissance'] ?? null);
        $ipp = $this->texte($data['ipp'] ?? null);
        $telephone = $this->texte($data['telephone'] ?? null);
        $email = $this->texte($data['email'] ?? null);
        $sousX = $this->estSousX($nom, $prenom);

        if ($nom === '' || $prenom === '') {
            throw new InvalidArgumentException(
                'Le nom et le prénom du patient sont obligatoires (indiquer X si l\'identité est inconnue)'
            );
        }
        if (!preg_match('/^.{1,50}$/u', $nom) || !preg_match('/^.{1,50}$/u', $prenom)) {
            throw new InvalidArgumentException('Le nom et le prénom ne doivent pas dépasser 50 caractères');
        }
        if (!in_array($sexe, ['M', 'F'], true)) {
            throw new InvalidArgumentException('Le sexe doit être M ou F');
        }

        // Patient sous X : identité inconnue, la date de naissance peut manquer.
        $dateNaissance = null;
        if ($dateSaisie !== null) {
            $dateNaissance = $this->parserDate($dateSaisie);
            if ($dateNaissance === null) {
                throw new InvalidArgumentException('La date de naissance est invalide (format AAAA-MM-JJ)');
            }
            if ($dateNaissance > new DateTimeImmutable()) {
                throw new InvalidArgumentException('La date de naissance ne peut pas être dans le futur');
            }
        } elseif (!$sousX) {
            throw new InvalidArgumentException('La date de naissance est obligatoire');
        }

        if ($ipp !== null) {
            if ($sousX) {
                throw new InvalidArgumentException('Un patient sous X ne peut pas avoir d\'IPP');
            }
            if (!preg_match('/^\d{6,9}$/', $ipp)) {
                throw new InvalidArgumentException("L'IPP doit contenir entre 6 et 9 chiffres");
            }
        }
        if ($telephone !== null && !preg_match('/^\d{10}$/', $telephone)) {
            throw new InvalidArgumentException('Le téléphone doit contenir 10 chiffres');
        }
        if ($email !== null && (strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException("L'adresse email est invalide");
        }

        return new Patient(
            idPatient: null,
            ipp: $ipp,
            nom: $sousX ? 'X' : $nom,
            prenom: $sousX ? 'X' : $prenom,
            dateNaissance: $dateNaissance,
            sexe: $sexe,
            telephone: $telephone,
            email: $email
        );
    }

    private function estSousX(string $nom, string $prenom): bool
    {
        return strtoupper($nom) === 'X' && strtoupper($prenom) === 'X';
    }

    /** Date au format AAAA-MM-JJ, ou null si absente ou invalide (ex. 1980-02-31). */
    private function parserDate(?string $saisie): ?DateTimeImmutable
    {
        if ($saisie === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $saisie);

        return $date !== false && $date->format('Y-m-d') === $saisie ? $date : null;
    }

    /** Nettoie une valeur saisie : null si absente ou vide. */
    private function texte(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return $valeur === '' ? null : $valeur;
    }
}
