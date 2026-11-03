<?php

declare(strict_types=1);

namespace Aura\Domain;

use DateTimeImmutable;

/**
 * Équipe d'une vacation (AURA-30, table CareTeam) : soignants et patients attribués, pour un
 * service, une date et une vacation (JOUR ou NUIT). Immuable : on recompose en retirant l'équipe.
 */
final class CareTeam
{
    public const SHIFT_DAY = 'JOUR';
    public const SHIFT_NIGHT = 'NUIT';

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

    public function getShiftDate(): DateTimeImmutable
    {
        return $this->shiftDate;
    }

    /** SHIFT_DAY ou SHIFT_NIGHT. */
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
