<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Allergie;
use DateTimeImmutable;
use PDO;

/**
 * Accès aux tables AllergyIntolerance / Substance. Tables et colonnes en anglais (HL7),
 * entités en français (cf. README « Convention de nommage »).
 */
class AllergieDao
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Allergies d'un patient, avec le libellé de l'allergène, par ordre alphabétique.
     * Le schéma n'a pas (encore) de colonne removedAt : toutes les allergies sont actives.
     *
     * @return Allergie[]
     */
    public function findByPatient(int $idPatient): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.idAllergy, a.idPatient, a.idSubstance, s.description, a.criticality, a.recordedAt
             FROM AllergyIntolerance a
             JOIN Substance s ON s.idSubstance = a.idSubstance
             WHERE a.idPatient = :idPatient
             ORDER BY s.description'
        );
        $stmt->execute(['idPatient' => $idPatient]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapToEntity(array $r): Allergie
    {
        return new Allergie(
            idAllergie: (int) $r['idAllergy'],
            idPatient: (int) $r['idPatient'],
            idAllergene: (string) $r['idSubstance'],
            substance: (string) $r['description'],
            criticite: $r['criticality'] !== null && trim((string) $r['criticality']) !== ''
                ? trim((string) $r['criticality'])
                : null,
            dateSaisie: new DateTimeImmutable($r['recordedAt'])
        );
    }
}
