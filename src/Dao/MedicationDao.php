<?php

declare(strict_types=1);

namespace Aura\Dao;

use Aura\Domain\Medication;
use PDO;

/**
 * Référentiel des médicaments : table Medication, ressource HL7 du même nom (inn = DCI, form =
 * forme galénique). Lecture seule : le référentiel est chargé par database/test.sql. Sert à
 * AgendaService pour afficher la DCI d'une ligne de médicament. Les listes des formulaires et
 * l'allergène du principe actif (Medication.idSubstance, non lu ici) sont dans ReferenceDataDao.
 */
class MedicationDao
{
    private const COLUMNS = 'idMedication, inn, form';

    public function __construct(private PDO $pdo)
    {
    }

    /** Médicament par identifiant (VARCHAR(20)) ; null s'il n'existe pas. */
    public function findById(string $id): ?Medication
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM Medication WHERE idMedication = :id'
        );
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? $this->mapToEntity($r) : null;
    }

    /**
     * Ligne SQL (clés = noms de colonnes) vers l'entité. Ne renvoie jamais null malgré le type
     * déclaré ; inn et form, eux, peuvent l'être (colonnes facultatives).
     *
     * @param array<string, ?string> $r
     */
    public function mapToEntity(array $r): ?Medication
    {
        return new Medication(
            idMedication: $r['idMedication'],
            inn: $r['inn'],
            form: $r['form']
        );
    }
}
