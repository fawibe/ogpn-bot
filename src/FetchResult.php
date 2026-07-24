<?php

declare(strict_types=1);

namespace OgpnBot;

/**
 * Résultat d'une requête HTTP unique. Volontairement permissif : un 404 ou un
 * 403 est un succès réseau (on a une réponse du serveur), seul un vrai souci
 * réseau (timeout, DNS, TLS...) produit un résultat d'erreur.
 */
final class FetchResult
{
    private function __construct(
        public readonly string $url,
        public readonly bool $ok,
        public readonly ?int $statusCode,
        public readonly ?string $body,
        public readonly ?string $headersRaw,
        public readonly bool $rangeIgnored,
        public readonly ?string $errorMessage,
        /** IP réellement contactée pour cette requête — donnée brute, aucune interprétation ici (hébergeur/ASN résolus séparément, voir notes de conception). */
        public readonly ?string $primaryIp = null,
        /** Émetteur du certificat SSL, si HTTPS — donnée brute, capturée sur la même connexion, sans requête supplémentaire. */
        public readonly ?string $sslIssuer = null,
    ) {
    }

    public static function success(
        string $url,
        int $statusCode,
        string $body,
        string $headersRaw,
        bool $rangeIgnored = false,
        ?string $primaryIp = null,
        ?string $sslIssuer = null,
    ): self {
        return new self(
            url: $url,
            ok: true,
            statusCode: $statusCode,
            body: $body,
            headersRaw: $headersRaw,
            rangeIgnored: $rangeIgnored,
            errorMessage: null,
            primaryIp: $primaryIp,
            sslIssuer: $sslIssuer,
        );
    }

    public static function error(string $url, string $message): self
    {
        return new self(
            url: $url,
            ok: false,
            statusCode: null,
            body: null,
            headersRaw: null,
            rangeIgnored: false,
            errorMessage: $message,
        );
    }

    /** Vrai si le fichier existe (statut 2xx). Un 404/403 renvoie false, pas une erreur. */
    public function exists(): bool
    {
        return $this->ok && $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
