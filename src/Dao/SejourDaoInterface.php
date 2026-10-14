<?php

declare(strict_types=1);

namespace Aura\Dao;

/**
 * Accès aux données d'un séjour pour le dossier patient (AURA-14).
 *
 * Les tableaux renvoyés reprennent les colonnes de database/schema.sql (V1) :
 * Sejour (id_sejour, id_patient, num_sejour, date_admission, date_sortie, num_lit, num_box, id_service)
 * et Patient (id_patient, ipp, nom, prenom, date_de_naissance, sexe, telephone, email).
 *
 * Implémentation provisoire : SejourDaoMemoire. Implémentation cible : PDO en requêtes
 * préparées, à écrire quand la couche d'accès AURA-11 sera livrée.
 */
interface SejourDaoInterface
{
    /**
     * @return array{sejour: array<string, mixed>, patient: array<string, mixed>}|null
     *         null si aucun séjour ne porte cet identifiant
     */
    public function findAvecPatient(string $idSejour): ?array;

    /**
     * Allergies connues du patient, choisies dans la liste de référence (AURA-13).
     *
     * @return list<array{libelle: string, criticite: string|null}>
     */
    public function findAllergies(string $idPatient): array;
}
