<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Allergie;
use PDO;

/**
 * Accès aux tables Allergie / Allergene (schéma V1). Colonnes en camelCase anglais,
 * entités en français (cf. README « Convention de nommage »).
 */
class AllergieDao
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Allergies connues d'un patient, avec le libellé de l'allergène, par ordre alphabétique.
     *
     * @return Allergie[]
     */
    public function findByPatient(int $idPatient): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.idAllergie, a.idPatient, a.idAllergene, g.substance, a.criticality
             FROM Allergie a
             JOIN Allergene g ON g.idAllergene = a.idAllergene
             WHERE a.idPatient = :idPatient
             ORDER BY g.substance'
        );
        $stmt->execute(['idPatient' => $idPatient]);

        return array_map(fn(array $r) => $this->mapToEntity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapToEntity(array $r): Allergie
    {
        return new Allergie(
            idAllergie: (int) $r['idAllergie'],
            idPatient: (int) $r['idPatient'],
            idAllergene: (string) $r['idAllergene'],
            substance: (string) $r['substance'],
            criticite: $r['criticality'] !== null && trim((string) $r['criticality']) !== ''
                ? trim((string) $r['criticality'])
                : null
        );
    }
}
