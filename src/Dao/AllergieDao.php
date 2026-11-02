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

    /**
     * Enregistre une allergie et renvoie son id (AURA-13.2). idAllergy est un VARCHAR sans
     * AUTO_INCREMENT : l'id est calculé dans une transaction, en verrouillant la table (FOR
     * UPDATE) comme PatientDao::insert(). recordedAt est rempli par la base.
     *
     * La contrainte UNIQUE (idPatient, idSubstance) lève une PDOException (erreur 1062)
     * si le couple existe déjà : c'est la garantie finale contre les doublons, que le
     * service traduit en message métier.
     */
    public function insert(Allergie $a): int
    {
        return (new Transaction($this->pdo))->run(function () use ($a): int {
            $id = (int) $this->pdo
                ->query('SELECT COALESCE(MAX(CAST(idAllergy AS UNSIGNED)), 0) + 1 FROM AllergyIntolerance FOR UPDATE')
                ->fetchColumn();

            $stmt = $this->pdo->prepare(
                'INSERT INTO AllergyIntolerance (idAllergy, idPatient, idSubstance, criticality)
                 VALUES (:idAllergy, :idPatient, :idSubstance, :criticality)'
            );
            $stmt->execute([
                'idAllergy' => (string) $id,
                'idPatient' => (string) $a->getIdPatient(),
                'idSubstance' => $a->getIdAllergene(),
                'criticality' => $a->getCriticite(),
            ]);

            return $id;
        });
    }

    /**
     * Retire une allergie du dossier (AURA-13.2). Suppression PHYSIQUE : décision d'équipe
     * du 21/10/2026, la suppression logique (removedAt) étant suspendue dans le schéma.
     * Dette technique : la trace « allergie connue au moment d'un soin » disparaît ; seule
     * cette méthode (et le filtre de findByPatient) changera si l'équipe la réactive.
     *
     * Le patient est exigé en plus de l'id : un id forgé ne peut pas toucher le dossier
     * d'un autre patient.
     *
     * @return bool true si une allergie a été retirée, false si elle n'existait pas (ou pas chez ce patient)
     */
    public function delete(int $idAllergy, int $idPatient): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM AllergyIntolerance WHERE idAllergy = :idAllergy AND idPatient = :idPatient'
        );
        $stmt->execute(['idAllergy' => (string) $idAllergy, 'idPatient' => (string) $idPatient]);

        return $stmt->rowCount() === 1;
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
