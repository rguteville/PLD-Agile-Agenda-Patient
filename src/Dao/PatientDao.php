<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Patient;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;

/**
 * Accès à la table Patient. Les colonnes suivent database/schema.sql (camelCase anglais,
 * cf. README « Convention de nommage ») ; les entités du domaine restent en français.
 */
class PatientDao
{
    private const COLUMNS = 'idPatient, ipp, lastName, firstName, birthDate, sex, phoneNumber, email';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return Patient[] */
    public function findAll(): array
    {
        $rows = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM Patient ORDER BY lastName, firstName')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->mapToEntity($r), $rows);
    }

    /**
     * Patients dont le nom, le prénom ou l'IPP contient le terme saisi (AURA-12.1),
     * triés par nom puis prénom. Le terme est passé en paramètre préparé et ses
     * caractères joker (% et _) sont échappés : il est impossible d'injecter du SQL
     * ni d'élargir la recherche avec un joker.
     *
     * @return Patient[]
     */
    public function search(string $terme): array
    {
        $motif = '%' . addcslashes($terme, '\\%_') . '%';

        // Sans émulation des requêtes préparées, un paramètre nommé ne peut servir qu'une fois.
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM Patient
             WHERE lastName LIKE :nom ESCAPE \'\\\\\'
                OR firstName LIKE :prenom ESCAPE \'\\\\\'
                OR CAST(ipp AS CHAR) LIKE :ipp ESCAPE \'\\\\\'
             ORDER BY lastName, firstName'
        );
        $stmt->execute(['nom' => $motif, 'prenom' => $motif, 'ipp' => $motif]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?Patient
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM Patient WHERE idPatient = :id');
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
             WHERE lastName = :lastName AND firstName = :firstName AND birthDate = :birthDate'
        );
        $stmt->execute([
            'lastName' => $nom,
            'firstName' => $prenom,
            'birthDate' => $dateNaissance->format('Y-m-d'),
        ]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Insère le patient et renvoie son identifiant.
     *
     * Le schéma actuel n'a pas d'AUTO_INCREMENT sur idPatient : l'id est donc
     * calculé ici (plus grand id + 1), dans une transaction qui verrouille la
     * table pour éviter que deux inscriptions simultanées obtiennent le même id.
     * Si une transaction est déjà ouverte (inscription + séjour), on s'y rattache.
     */
    public function insert(Patient $p): int
    {
        return (new Transaction($this->pdo))->run(function () use ($p): int {
            $id = (int) $this->pdo
                ->query('SELECT COALESCE(MAX(CAST(idPatient AS UNSIGNED)), 0) + 1 FROM Patient FOR UPDATE')
                ->fetchColumn();

            $stmt = $this->pdo->prepare(
                'INSERT INTO Patient (' . self::COLUMNS . ')
                 VALUES (:idPatient, :ipp, :lastName, :firstName, :birthDate, :sex, :phoneNumber, :email)'
            );
            $stmt->execute([
                'idPatient' => (string) $id,
                'ipp' => $p->getIpp(),
                'lastName' => $p->getNom(),
                'firstName' => $p->getPrenom(),
                'birthDate' => $p->getDateNaissance()?->format('Y-m-d'),
                'sex' => $p->getSexe(),
                'phoneNumber' => $p->getTelephone(),
                'email' => $p->getEmail(),
            ]);

            return $id;
        });
    }

    private function mapToEntity(array $r): Patient
    {
        return new Patient(
            idPatient: (int) $r['idPatient'],
            ipp: $r['ipp'] !== null ? (string) $r['ipp'] : null,
            nom: $r['lastName'],
            prenom: $r['firstName'],
            dateNaissance: $r['birthDate'] !== null ? new DateTimeImmutable($r['birthDate']) : null,
            sexe: $r['sex'],
            telephone: $r['phoneNumber'],
            email: $r['email']
        );
    }
}
