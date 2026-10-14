<?php

declare(strict_types=1);

namespace Aura\Dao;

/**
 * Source de données PROVISOIRE en mémoire, le temps que la couche d'accès BD (AURA-11)
 * existe. Données entièrement fictives, même structure que database/schema.sql pour que
 * le remplacement par une implémentation PDO ne change rien au reste du code.
 *
 * À supprimer à l'étape 2 d'AURA-14.
 */
final class SejourDaoMemoire implements SejourDaoInterface
{
    private const PATIENTS = [
        'P-1' => [
            'id_patient' => 'P-1',
            'ipp' => null,
            'nom' => 'Lefèvre',
            'prenom' => 'Camille',
            'date_de_naissance' => '1958-03-21',
            'sexe' => 'F',
            'telephone' => '0600000001',
            'email' => 'c.lefevre@exemple.fr',
        ],
        'P-2' => [
            'id_patient' => 'P-2',
            'ipp' => 100200300,
            'nom' => 'Moreau',
            'prenom' => 'Julien',
            'date_de_naissance' => '1979-11-02',
            'sexe' => 'M',
            'telephone' => null,
            'email' => null,
        ],
    ];

    private const SEJOURS = [
        '1' => [
            'id_sejour' => '1',
            'id_patient' => 'P-1',
            'num_sejour' => '2026-000001',
            'date_admission' => '2026-10-12 08:15:00',
            'date_sortie' => null,
            'num_lit' => '3',
            'num_box' => 'B2',
            'id_service' => 'REA',
        ],
        '2' => [
            'id_sejour' => '2',
            'id_patient' => 'P-2',
            'num_sejour' => '2026-000002',
            'date_admission' => '2026-10-13 22:40:00',
            'date_sortie' => null,
            'num_lit' => '7',
            'num_box' => 'B5',
            'id_service' => 'REA',
        ],
    ];

    private const ALLERGIES = [
        'P-2' => [
            ['libelle' => 'Pénicilline', 'criticite' => 'sévère'],
            ['libelle' => 'Latex', 'criticite' => 'modérée'],
        ],
    ];

    public function findAvecPatient(string $idSejour): ?array
    {
        $sejour = self::SEJOURS[$idSejour] ?? null;
        if ($sejour === null) {
            return null;
        }

        return [
            'sejour' => $sejour,
            'patient' => self::PATIENTS[$sejour['id_patient']],
        ];
    }

    public function findAllergies(string $idPatient): array
    {
        return self::ALLERGIES[$idPatient] ?? [];
    }
}
