<?php

declare(strict_types=1);

namespace Aura\Model;

class Service
{
    public function __construct(
        private ?int $idService,
        private string $nom
    ) {
    }

    public function getIdService(): ?int { return $this->idService; }
    public function getNom(): string { return $this->nom; }
}