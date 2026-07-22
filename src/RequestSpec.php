<?php

declare(strict_types=1);

namespace OgpnBot;

/**
 * Décrit une requête HTTP à effectuer. Immuable — les méthodes with*()
 * renvoient une nouvelle instance plutôt que de modifier l'objet courant.
 */
final class RequestSpec
{
    /** @param array<string, string> $headers Headers additionnels, fusionnés à ceux posés par défaut par Http. */
    public function __construct(
        public readonly string $url,
        public readonly ?int $rangeBytes = null,
        public readonly string $method = 'GET',
        public readonly array $headers = [],
        public readonly ?string $body = null,
    ) {
    }

    public function withHeader(string $name, string $value): self
    {
        return new self(
            url: $this->url,
            rangeBytes: $this->rangeBytes,
            method: $this->method,
            headers: [...$this->headers, $name => $value],
            body: $this->body,
        );
    }

    /** Bascule la requête en POST avec un corps JSON et l'entête Content-Type adéquat. */
    public function withJsonBody(string $json): self
    {
        return new self(
            url: $this->url,
            rangeBytes: null, // Range n'a pas de sens sur une requête POST applicative
            method: 'POST',
            headers: [...$this->headers, 'Content-Type' => 'application/json'],
            body: $json,
        );
    }
}
