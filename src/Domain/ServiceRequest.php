<?php

declare(strict_types=1);

namespace Aura\Domain;


class ServiceRequest extends RequestLine
{
      public function getIdServiceRequest(): ?int
    {
        return $this->getId();
    }
}
