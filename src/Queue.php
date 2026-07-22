<?php

declare(strict_types=1);

namespace OgpnBot;

/**
 * Client de l'API OGPN (script maison côté Infomaniak, distinct de FWBCMS).
 * Ne connaît aucun état local — tout l'état (quels domaines sont dus, quand
 * chacun a été scanné) vit côté serveur. Ce client se contente de demander un
 * lot, puis de renvoyer les résultats.
 */
final class Queue
{
    public function __construct(
        private readonly Http $http,
        private readonly string $apiBaseUrl,
        private readonly string $apiToken,
    ) {
    }

    /**
     * Demande le prochain lot de domaines à scanner.
     *
     * @return string[] Liste de domaines (peut être vide si rien n'est dû).
     * @throws \RuntimeException Si l'appel échoue (réseau ou réponse invalide).
     */
    public function fetchNextBatch(int $limit): array
    {
        $url = rtrim($this->apiBaseUrl, '/') . '/scan-queue?limit=' . $limit;
        $spec = (new RequestSpec($url))->withHeader('Authorization', 'Bearer ' . $this->apiToken);

        $results = $this->http->fetchBatch([
            'queue' => ['request' => $spec],
        ]);
        $result = $results['queue']['request'];

        if (!$result->ok) {
            throw new \RuntimeException("Impossible de contacter l'API (scan-queue) : " . ($result->errorMessage ?? 'erreur inconnue'));
        }

        if (!$result->exists()) {
            throw new \RuntimeException("L'API a répondu avec le statut {$result->statusCode} sur scan-queue.");
        }

        $data = json_decode((string) $result->body, associative: true);
        if (!is_array($data) || !isset($data['domains']) || !is_array($data['domains'])) {
            throw new \RuntimeException('Réponse scan-queue mal formée (champ "domains" manquant ou invalide).');
        }

        return array_values(array_filter($data['domains'], is_string(...)));
    }

    /**
     * Envoie les résultats d'un lot scanné. Le serveur est responsable de
     * mettre à jour son propre état (last_scanned_at, historique, etc.).
     *
     * @param array<string, ScanResult> $results Domaine => résultat, tel que renvoyé par Scanner::scanBatch().
     * @throws \RuntimeException Si l'envoi échoue ou si le serveur rejette les données.
     */
    public function submitResults(array $results): void
    {
        if ($results === []) {
            return;
        }

        $payload = ['results' => array_map($this->serializeResult(...), array_values($results))];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            throw new \RuntimeException('Impossible de sérialiser les résultats en JSON.');
        }

        $url = rtrim($this->apiBaseUrl, '/') . '/scan-ingest';
        $spec = (new RequestSpec($url))
            ->withJsonBody($body)
            ->withHeader('Authorization', 'Bearer ' . $this->apiToken);

        $results2 = $this->http->fetchBatch([
            'ingest' => ['request' => $spec],
        ]);
        $result = $results2['ingest']['request'];

        if (!$result->ok) {
            throw new \RuntimeException("Impossible de contacter l'API (scan-ingest) : " . ($result->errorMessage ?? 'erreur inconnue'));
        }

        if (!$result->exists()) {
            throw new \RuntimeException("L'API a rejeté l'envoi (scan-ingest) avec le statut {$result->statusCode} : " . substr((string) $result->body, 0, 500));
        }
    }

    private function serializeResult(ScanResult $r): array
    {
        return [
            'domain' => $r->domain,
            'robots_status' => $r->robotsStatus,
            'robots_blocks_everything' => $r->robotsBlocksEverything,
            'ai_bot_policy' => $r->aiBotPolicy,
            'file_presence' => $r->filePresence,
            'file_misplaced' => $r->fileMisplaced,
            'file_conflict' => $r->fileConflict,
            'country_code' => $r->countryCode,
            'country_source' => $r->countrySource,
            'default_language' => $r->defaultLanguage,
            'alternate_languages' => $r->alternateLanguages,
            'http_status' => $r->httpStatus,
            'error' => $r->error,
            'scanned_at' => gmdate('c'),
        ];
    }
}
