<?php

declare(strict_types=1);

namespace OgpnBot;

/**
 * Décrit une requête GET à effectuer. Immuable — construite une fois, passée
 * telle quelle à Http::fetchBatch().
 */
final class RequestSpec
{
    public function __construct(
        public readonly string $url,
        /** Si non-null, limite la lecture aux N premiers octets via l'entête Range. */
        public readonly ?int $rangeBytes = null,
    ) {
    }
}
