<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/RequestSpec.php';
require __DIR__ . '/../src/FetchResult.php';

use OgpnBot\Http;
use OgpnBot\RequestSpec;

/**
 * Client minimal pour l'API CDX de Common Crawl (gratuite, sans clé).
 * Documentation : https://index.commoncrawl.org/
 *
 * On interroge par domaine inversé (ex. "be,exemple)*" pour tout .be), avec
 * pagination via showNumPages/page — l'API CDX découpe ses résultats en
 * "pages" dont la taille dépend du serveur, pas de nous.
 */
final class CommonCrawlIndex
{
    /** Index le plus récent connu au moment de l'écriture — à ajuster si Common Crawl change son cycle. */
    public const DEFAULT_CRAWL_ID = 'CC-MAIN-2026-05';

    private const BASE_URL = 'https://index.commoncrawl.org';

    public function __construct(
        private readonly Http $http,
    ) {
    }

    /**
     * Récupère une page de domaines pour un TLD donné.
     *
     * @return array{domains: string[], nextPage: ?int, totalPages: ?int}
     */
    public function domainsForTld(string $crawlId, string $tld, int $page): array
    {
        // "*.tld/*" en syntaxe CDX inversée : "tld,*)/*"
        $urlPattern = "{$tld},*)/*";
        $endpoint = self::BASE_URL . "/{$crawlId}-index"
            . '?url=' . urlencode($urlPattern)
            . '&matchType=domain'
            . '&output=json'
            . '&fl=url'
            . '&page=' . $page
            . '&showNumPages=true';

        $results = $this->http->fetchBatch([
            'cdx' => ['request' => new RequestSpec($endpoint)],
        ]);
        $result = $results['cdx']['request'];

        if (!$result->ok || !$result->exists()) {
            throw new \RuntimeException(
                "Échec de l'appel CDX pour .{$tld} page {$page} : "
                . ($result->errorMessage ?? "statut {$result->statusCode}")
            );
        }

        $body = (string) $result->body;

        // L'API CDX renvoie soit un objet {"pages": N} seul (quand
        // showNumPages est demandé sans résultat exploitable), soit une
        // suite de lignes NDJSON (un objet JSON par ligne, pas un tableau).
        $totalPages = null;
        $domains = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($body)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, associative: true);
            if (!is_array($decoded)) {
                continue;
            }
            if (isset($decoded['pages']) && count($decoded) === 1) {
                $totalPages = (int) $decoded['pages'];
                continue;
            }
            if (isset($decoded['url']) && is_string($decoded['url'])) {
                $rootDomain = self::reduceToRootDomain($decoded['url']);
                if ($rootDomain !== null) {
                    $domains[$rootDomain] = true; // dédoublonnage via les clés
                }
            }
        }

        $nextPage = ($totalPages !== null && $page + 1 < $totalPages) ? $page + 1 : null;

        return ['domains' => array_keys($domains), 'nextPage' => $nextPage, 'totalPages' => $totalPages];
    }

    /**
     * Réduit une URL à son domaine racine — approche volontairement simple
     * ("2 derniers segments"), qui casse sur les TLD à suffixe composé
     * (type .co.uk). Aucun des TLD actuellement visés n'en a, donc non
     * bloquant maintenant, mais à corriger si un tel TLD est ajouté un jour.
     */
    public static function reduceToRootDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return null;
        }

        return implode('.', array_slice($parts, -2));
    }
}
