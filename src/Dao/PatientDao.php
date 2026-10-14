<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Patient;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;

class PatientDao
{
    private const COLUMNS = 'id_patient, ipp, nom, prenom, date_de_naissance, sexe, telephone, email';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return Patient[] */
    public function findAll(): array
    {
        $rows = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM Patient ORDER BY nom, prenom')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->mapToEntity($r), $rows);
    }

    public function findById(int $id): ?Patient
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM Patient WHERE id_patient = :id');
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    public function findByIpp(string $ipp): ?Patient
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM Patient WHERE ipp = :ipp');
        $stmt->execute(['ipp' => $ipp]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    public function existsByIpp(string $ipp): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM Patient WHERE ipp = :ipp');
        $stmt->execute(['ipp' => $ipp]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Patients portant exactement ce nom, ce prénom et cette date de naissance
     * (reconnaissance d'un patient déjà venu dans le service, sans IPP).
     *
     * @return Patient[]
     */
    public function findByIdentite(string $nom, string $prenom, DateTimeInterface $dateNaissance): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM Patient
             WHERE nom = :nom AND prenom = :prenom AND date_de_naissance = :date_de_naissance'
        );
        $stmt->execute([
            'nom' => $nom,
            'prenom' => $prenom,
            'date_de_naissance' => $dateNaissance->format('Y-m-d'),
        ]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Insère le patient et renvoie son identifiant.
     *
     * Le schéma actuel n'a pas d'AUTO_INCREMENT sur id_patient : l'id est donc
     * calculé ici (plus grand id + 1), dans une transaction qui verrouille la
     * table pour éviter que deux inscriptions simultanées obtiennent le même id.
     * Si une transaction est déjà ouverte (inscription + séjour), on s'y rattache.
     */
    public function insert(Patient $p): int
    {
        return (new Transaction($this->pdo))->run(function () use ($p): int {
            $id = (int) $this->pdo
                ->query('SELECT COALESCE(MAX(CAST(id_patient AS UNSIGNED)), 0) + 1 FROM Patient FOR UPDATE')
                ->fetchColumn();

            $stmt = $this->pdo->prepare(
                'INSERT INTO Patient (' . self::COLUMNS . ')
                 VALUES (:id_patient, :ipp, :nom, :prenom, :date_de_naissance, :sexe, :telephone, :email)'
            );
            $stmt->execute([
                'id_patient' => (string) $id,
                'ipp' => $p->getIpp(),
                'nom' => $p->getNom(),
                'prenom' => $p->getPrenom(),
                'date_de_naissance' => $p->getDateNaissance()?->format('Y-m-d'),
                'sexe' => $p->getSexe(),
                'telephone' => $p->getTelephone(),
                'email' => $p->getEmail(),
            ]);

            return $id;
        });
    }

    private function mapToEntity(array $r): Patient
    {
        return new Patient(
            idPatient: (int) $r['id_patient'],
            ipp: $r['ipp'] !== null ? (string) $r['ipp'] : null,
            nom: $r['nom'],
            prenom: $r['prenom'],
            dateNaissance: $r['date_de_naissance'] !== null ? new DateTimeImmutable($r['date_de_naissance']) : null,
            sexe: $r['sexe'],
            telephone: $r['telephone'],
            email: $r['email']
        );
    }
}
