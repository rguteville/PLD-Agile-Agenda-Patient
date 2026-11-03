<?php

declare(strict_types=1);

namespace Aura\Tests\Http;

/** Réponse HTTP reçue par un test de la suite « http » : code, corps et en-têtes (noms en minuscules). */
final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> corps JSON décodé ([] s'il n'est pas du JSON) */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
