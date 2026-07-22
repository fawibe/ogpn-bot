<?php

declare(strict_types=1);

namespace OgpnBot;

/**
 * Couche réseau du bot OGPN.
 *
 * Principes retenus (voir notes de conception du projet) :
 *  - User-Agent transparent, avec URL de contact — jamais de mimétisme navigateur.
 *  - Timeouts stricts et courts : un domaine lent ne doit jamais plomber tout le batch.
 *  - GET uniquement (HEAD ne renvoie jamais le corps dont on a besoin) — mais un GET
 *    "intelligent" via Range pour la page HTML, afin de ne récupérer que le <head>.
 *  - Parallélisme interne à un domaine (curl_multi), mais nombre de domaines traités
 *    en parallèle volontairement plafonné, pour rester raisonnable sur un hébergement
 *    mutualisé (ou un runner CI partagé) plutôt que de viser la vitesse brute.
 */
class Http
{
    /** User-Agent transparent, avec contact — jamais de mimétisme navigateur. */
    public const USER_AGENT = 'OgpnBot/1.0 (+https://ogpn.eu/bot; contact@ogpn.eu)';

    /** Simple nom du bot, tel qu'il doit apparaître dans les lignes "User-agent:" de robots.txt. */
    public const USER_AGENT_TOKEN = 'OgpnBot';

    /** Nombre d'octets suffisant pour couvrir le <head> de quasi toutes les pages. */
    public const HTML_HEAD_RANGE_BYTES = 16384;

    /** Timeouts stricts — un domaine mort ne doit jamais plomber le batch. */
    private const CONNECT_TIMEOUT_S = 2;
    private const TOTAL_TIMEOUT_S = 4;

    /** Nombre de domaines traités en parallèle simultanément (throttle volontaire). */
    private const MAX_CONCURRENT_DOMAINS = 4;

    /**
     * Récupère un lot de requêtes en parallèle, plafonné à MAX_CONCURRENT_DOMAINS
     * lots de requêtes "actifs" en même temps.
     *
     * @param array<string, RequestSpec[]> $requestsByDomain Domaine => liste de requêtes à exécuter pour lui.
     * @return array<string, array<string, FetchResult>> Domaine => (clé de requête => résultat).
     */
    public function fetchBatch(array $requestsByDomain): array
    {
        $results = [];
        $domainChunks = array_chunk(
            array_keys($requestsByDomain),
            self::MAX_CONCURRENT_DOMAINS,
            preserve_keys: true,
        );

        foreach ($domainChunks as $domainsInWave) {
            $wave = [];
            foreach ($domainsInWave as $domain) {
                $wave[$domain] = $requestsByDomain[$domain];
            }
            $results += $this->fetchWave($wave);
        }

        return $results;
    }

    /**
     * Exécute réellement en parallèle (curl_multi) toutes les requêtes de tous les
     * domaines d'une vague. Appelé uniquement avec un nombre de domaines déjà
     * plafonné par fetchBatch().
     *
     * @param array<string, RequestSpec[]> $requestsByDomain
     * @return array<string, array<string, FetchResult>>
     */
    private function fetchWave(array $requestsByDomain): array
    {
        $multiHandle = curl_multi_init();
        /** @var array<int, array{0: string, 1: string, 2: \CurlHandle, 3: RequestSpec}> $handleMap */
        $handleMap = [];

        foreach ($requestsByDomain as $domain => $requests) {
            foreach ($requests as $key => $spec) {
                $curlHandle = $this->buildCurlHandle($spec);
                curl_multi_add_handle($multiHandle, $curlHandle);
                $handleMap[spl_object_id($curlHandle)] = [$domain, $key, $curlHandle, $spec];
            }
        }

        // curl_multi_exec peut renvoyer CURLM_CALL_MULTI_PERFORM (obsolète mais
        // encore possible) sans avoir réellement démarré les transferts — il faut
        // l'appeler au moins une fois avant de passer à la boucle select/exec.
        do {
            $status = curl_multi_exec($multiHandle, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        $running = null;
        while (true) {
            do {
                $status = curl_multi_exec($multiHandle, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($running === 0 || $status !== CURLM_OK) {
                break;
            }
            curl_multi_select($multiHandle, 0.2);
        }

        // curl_errno()/curl_error() sur un handle individuel ne sont fiables
        // qu'après avoir drainé les messages de complétion de la multi-queue —
        // sans ça, un échec réel (connexion refusée, timeout) rapporte errno=0.
        while (curl_multi_info_read($multiHandle) !== false) {
            // on ne fait rien du message lui-même : curl_errno($handle) suffira
            // juste après, on veut seulement forcer la mise à jour interne.
        }

        $results = [];
        foreach ($handleMap as [$domain, $key, $curlHandle, $spec]) {
            $results[$domain][$key] = $this->collectResult($curlHandle, $spec);
            curl_multi_remove_handle($multiHandle, $curlHandle);
            curl_close($curlHandle);
        }
        curl_multi_close($multiHandle);

        return $results;
    }

    private function buildCurlHandle(RequestSpec $spec): \CurlHandle
    {
        $curlHandle = curl_init($spec->url);

        $headerLines = ['Accept: text/html,application/json,text/plain,*/*'];
        foreach ($spec->headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_S,
            CURLOPT_TIMEOUT => $spec->timeoutSeconds ?? self::TOTAL_TIMEOUT_S,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '', // accepte gzip/deflate automatiquement
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];

        if ($spec->method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $spec->body ?? '';
        }

        if ($spec->rangeBytes !== null) {
            $options[CURLOPT_RANGE] = '0-' . ($spec->rangeBytes - 1);
        }

        curl_setopt_array($curlHandle, $options);

        return $curlHandle;
    }

    private function collectResult(\CurlHandle $curlHandle, RequestSpec $spec): FetchResult
    {
        $raw = curl_multi_getcontent($curlHandle);
        $errorNumber = curl_errno($curlHandle);

        if ($errorNumber !== 0 || $raw === null) {
            return FetchResult::error(curl_error($curlHandle) ?: 'unknown_curl_error');
        }

        $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($curlHandle, CURLINFO_HEADER_SIZE);
        $headerRaw = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        // Un serveur qui honore Range répond 206 (Partial Content). S'il ignore
        // l'entête et renvoie 200 avec le corps entier, on l'a quand même reçu
        // en totalité (le "gain bande passante" est perdu pour cette requête),
        // mais on le signale : c'est une donnée en soi (maturité technique du
        // serveur), et on tronque nous-mêmes pour rester cohérent en aval.
        $rangeWasRequested = $spec->rangeBytes !== null;
        $rangeIgnored = $rangeWasRequested && $statusCode === 200;

        if ($rangeIgnored) {
            $body = substr($body, 0, $spec->rangeBytes);
        }

        return FetchResult::success(
            statusCode: $statusCode,
            body: $body,
            headersRaw: $headerRaw,
            rangeIgnored: $rangeIgnored,
        );
    }
}
