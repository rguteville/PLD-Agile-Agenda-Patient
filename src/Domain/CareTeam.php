<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Équipe d'une rotation (ressource HL7 CareTeam, table CareTeam et ses tables de liaison
 * CareTeamMember, CareTeamPatient) : soignants et patients attribués, pour un service, une date et une
 * rotation (MATIN, APRES_MIDI ou NUIT). Immuable : on recompose en retirant l'équipe.
 *
 * SHIFTS est LA référence des horaires de travail d'AURA (trois rotations de 8 h) : équipes et « Mon planning »
 * (CareTeamService), rotations affichées dans l'agenda et fin de la rotation en cours pour la validation anticipée
 * d'un soin (AgendaService::shiftEnd()).
 * Bornes 6 h, 14 h, 22 h : hypothèse à confirmer avec le service.
 *
 * Accesseurs get*() : un par colonne, sans logique ni validation.
 */
final class CareTeam
{
    public const SHIFT_MORNING = 'MATIN';
    public const SHIFT_AFTERNOON = 'APRES_MIDI';
    public const SHIFT_NIGHT = 'NUIT';

    /**
     * Rotations dans l'ordre de la journée : code (colonne CareTeam.shift) => libellé, heures de début et de fin
     * (la nuit se termine le lendemain à 6 h). Une rotation porte la date de son début : à 3 h le 15, on est dans
     * la nuit du 14.
     */
    public const SHIFTS = [
        self::SHIFT_MORNING => ['label' => 'Matin', 'from' => 6, 'to' => 14],
        self::SHIFT_AFTERNOON => ['label' => 'Après-midi', 'from' => 14, 'to' => 22],
        self::SHIFT_NIGHT => ['label' => 'Nuit', 'from' => 22, 'to' => 6],
    ];

    /** Durée d'une rotation, en heures. */
    public const SHIFT_HOURS = 8;

    /**
     * @param array<string, string> $members idPractitioner => « Prénom Nom »
     * @param list<int> $episodeIds séjours attribués
     */
    public function __construct(
        private ?int $idCareTeam,
        private string $idHealthcareService,
        private DateTimeImmutable $shiftDate,
        private string $shift,
        private string $name,
        private array $members = [],
        private array $episodeIds = [],
    ) {
    }

    public function getIdCareTeam(): ?int
    {
        return $this->idCareTeam;
    }

    public function getIdHealthcareService(): string
    {
        return $this->idHealthcareService;
    }

    /** Date du début de la rotation (la nuit du 14 commence le 14 à 22 h). */
    public function getShiftDate(): DateTimeImmutable
    {
        return $this->shiftDate;
    }

    /** Une des clés de SHIFTS (MATIN, APRES_MIDI, NUIT). */
    public function getShift(): string
    {
        return $this->shift;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function getMembers(): array
    {
        return $this->members;
    }

    /** @return list<int> */
    public function getEpisodeIds(): array
    {
        return $this->episodeIds;
    }
}
