<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Allergie;
use DateTimeImmutable;
use PDO;

/**
 * Accès aux allergies : tables AllergyIntolerance / Substance (noms HL7 depuis AURA-16,
 * cf. database/schema.sql) ; entités en français (cf. README « Convention de nommage »).
 */
class AllergieDao
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Allergies d'un patient, avec le libellé de l'allergène, par ordre alphabétique.
     * La colonne removedAt (retrait logique) est commentée dans le schéma actuel : toutes
     * les allergies enregistrées sont donc renvoyées.
     *
     * @return Allergie[]
     */
    public function findByPatient(int $idPatient): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.idAllergy AS idAllergie, a.idPatient, a.idSubstance AS idAllergene,
                    g.description AS substance, a.criticality, a.recordedAt
             FROM AllergyIntolerance a
             JOIN Substance g ON g.idSubstance = a.idSubstance
             WHERE a.idPatient = :idPatient
             ORDER BY g.description'
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
                : null,
            dateSaisie: new DateTimeImmutable($r['recordedAt'])
        );
    }
}
