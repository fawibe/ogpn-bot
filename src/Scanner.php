<?php

declare(strict_types=1);

namespace OgpnBot;

final class Scanner
{
    public function __construct(
        private readonly Http $http,
    ) {
    }

    /**
     * Scanne un lot de domaines. Respecte la contrainte éthique posée pour le
     * projet : robots.txt est toujours lu et interprété avant tout autre
     * fichier, et bloque la vague suivante s'il interdit l'accès.
     *
     * @param string[] $domains
     * @return array<string, ScanResult>
     */
    public function scanBatch(array $domains): array
    {
        // --- Vague 1 : robots.txt uniquement, pour tous les domaines du lot ---
        $wave1Requests = [];
        foreach ($domains as $domain) {
            $wave1Requests[$domain] = [
                'robots' => new RequestSpec("https://{$domain}/robots.txt"),
            ];
        }
        $wave1Results = $this->http->fetchBatch($wave1Requests);

        // --- Décision par domaine + préparation de la vague 2 ---
        $wave2Requests = [];
        $robotsByDomain = [];
        foreach ($domains as $domain) {
            $robotsFetch = $wave1Results[$domain]['robots'];

            if (!$robotsFetch->ok) {
                // Domaine injoignable dès robots.txt : inutile de continuer.
                $robotsByDomain[$domain] = ['status' => 'unreachable', 'policy' => null];
                continue;
            }

            if (!$robotsFetch->exists()) {
                // Pas de robots.txt (404 etc.) : absence de règle, on continue.
                $robotsByDomain[$domain] = ['status' => 'absent', 'policy' => null];
                $wave2Requests[$domain] = $this->buildWave2Requests($domain);
                continue;
            }

            $policy = new RobotsTxt($robotsFetch->body ?? '');
            $decision = $policy->check(Http::USER_AGENT_TOKEN, '/');
            $robotsByDomain[$domain] = ['status' => 'found', 'policy' => $policy, 'decision' => $decision];

            if ($decision === 'disallowed') {
                // Respect strict : on s'arrête ici pour ce domaine, on ne lit
                // même pas les autres fichiers RMF.
                continue;
            }

            $wave2Requests[$domain] = $this->buildWave2Requests($domain);
        }

        $wave2Results = $wave2Requests !== [] ? $this->http->fetchBatch($wave2Requests) : [];

        // --- Assemblage des résultats ---
        $results = [];
        foreach ($domains as $domain) {
            $robotsInfo = $robotsByDomain[$domain];

            if ($robotsInfo['status'] === 'unreachable') {
                $results[$domain] = new ScanResult(
                    domain: $domain,
                    robotsBlocksEverything: false,
                    robotsStatus: 'unreachable',
                    aiBotPolicy: [],
                    filePresence: [],
                    fileMisplaced: [],
                    fileConflict: [],
                    countryCode: $this->countryFromTld($domain),
                    defaultLanguage: null,
                    alternateLanguages: [],
                    httpStatus: null,
                    error: $wave1Results[$domain]['robots']->errorMessage,
                );
                continue;
            }

            $blocked = ($robotsInfo['policy'] ?? null) instanceof RobotsTxt
                && $robotsInfo['decision'] === 'disallowed';

            if ($blocked) {
                $results[$domain] = new ScanResult(
                    domain: $domain,
                    robotsBlocksEverything: true,
                    robotsStatus: 'disallowed',
                    aiBotPolicy: $this->aiBotPolicyFromRobots($robotsInfo['policy']),
                    filePresence: [],
                    fileMisplaced: [],
                    fileConflict: [],
                    countryCode: $this->countryFromTld($domain),
                    defaultLanguage: null,
                    alternateLanguages: [],
                    httpStatus: null,
                );
                continue;
            }

            $domainWave2 = $wave2Results[$domain] ?? [];
            $results[$domain] = $this->assembleResult($domain, $robotsInfo, $domainWave2);
        }

        return $results;
    }

    /** @return array<string, RequestSpec> */
    private function buildWave2Requests(string $domain): array
    {
        $requests = [
            'html' => new RequestSpec("https://{$domain}/", Http::HTML_HEAD_RANGE_BYTES),
        ];

        foreach (Config::GROUP_A_ROOT_ONLY as $key => $filename) {
            $requests["root_{$key}"] = new RequestSpec("https://{$domain}/{$filename}");
        }

        foreach (Config::GROUP_B_DUAL_LOOKUP as $key => $filename) {
            $requests["root_{$key}"] = new RequestSpec("https://{$domain}/{$filename}");
            $requests["wellknown_{$key}"] = new RequestSpec(
                "https://{$domain}" . Config::WELL_KNOWN_PREFIX . $filename,
            );
        }

        return $requests;
    }

    /** @param array<string, FetchResult> $wave2 */
    private function assembleResult(string $domain, array $robotsInfo, array $wave2): ScanResult
    {
        $filePresence = [];
        $fileMisplaced = [];
        $fileConflict = [];

        foreach (Config::GROUP_A_ROOT_ONLY as $key => $filename) {
            $filePresence[$key] = ($wave2["root_{$key}"] ?? null)?->exists() ?? false;
        }

        foreach (Config::GROUP_B_DUAL_LOOKUP as $key => $filename) {
            $rootResult = $wave2["root_{$key}"] ?? null;
            $wellknownResult = $wave2["wellknown_{$key}"] ?? null;

            $atRoot = $rootResult?->exists() ?? false;
            $atWellknown = $wellknownResult?->exists() ?? false;

            $filePresence[$key] = $atRoot || $atWellknown;
            $fileMisplaced[$key] = $atRoot && !$atWellknown;

            if ($atRoot && $atWellknown) {
                $fileConflict[$key] = trim((string) $rootResult?->body) !== trim((string) $wellknownResult?->body);
            }
        }

        $htmlResult = $wave2['html'] ?? null;
        $html = $htmlResult?->body ?? '';

        $robotsPolicy = $robotsInfo['policy'] ?? null;
        $aiBotPolicy = $robotsPolicy instanceof RobotsTxt ? $this->aiBotPolicyFromRobots($robotsPolicy) : [];

        [$defaultLanguage, $alternateLanguages] = $this->extractLanguages($html);
        $countryCode = $this->resolveCountry($domain, $html);

        // tdmrep.json est le seul fichier RMF au format assez stable pour
        // qu'on en parse le contenu (voir notes de conception du projet) —
        // on privilégie le contenu bien placé (well-known) s'il existe, sinon
        // celui de la racine.
        $tdmrepBody = ($wave2['wellknown_tdmrep'] ?? null)?->exists() === true
            ? $wave2['wellknown_tdmrep']->body
            : (($wave2['root_tdmrep'] ?? null)?->exists() === true ? $wave2['root_tdmrep']->body : null);
        [$tdmReservation, $tdmPolicyUrl] = $tdmrepBody !== null
            ? $this->parseTdmRep($tdmrepBody)
            : [null, null];

        [$hasJsonLd, $hasMicrodata] = $this->detectStructuredData($html);

        return new ScanResult(
            domain: $domain,
            robotsBlocksEverything: false,
            robotsStatus: $robotsInfo['status'],
            aiBotPolicy: $aiBotPolicy,
            filePresence: $filePresence,
            fileMisplaced: $fileMisplaced,
            fileConflict: $fileConflict,
            countryCode: $countryCode,
            defaultLanguage: $defaultLanguage,
            alternateLanguages: $alternateLanguages,
            httpStatus: $htmlResult?->statusCode,
            tdmReservation: $tdmReservation,
            tdmPolicyUrl: $tdmPolicyUrl,
            hasJsonLd: $hasJsonLd,
            hasMicrodata: $hasMicrodata,
        );
    }

    /**
     * Parse le contenu de tdmrep.json (TDM Reservation Protocol, W3C).
     * Format attendu : {"tdm-reservation": 1, "tdm-policy": "https://..."}
     * — 1/true/"1" = réservé (TDM interdit sauf accord), 0/false/"0" = ouvert.
     * Volontairement tolérant sur le typage des valeurs (bool, int, string)
     * puisque le format n'est pas strictement normé sur ce point en pratique.
     *
     * @return array{0: ?bool, 1: ?string} [réservation, URL de politique]
     */
    private function parseTdmRep(string $json): array
    {
        $data = json_decode($json, associative: true);
        if (!is_array($data)) {
            return [null, null];
        }

        $reservation = null;
        if (array_key_exists('tdm-reservation', $data)) {
            $value = $data['tdm-reservation'];
            if (is_array($value) && array_key_exists('type', $value)) {
                $value = $value['type']; // certaines implémentations imbriquent { "type": "1" }
            }
            $reservation = in_array($value, [1, '1', true, 'true'], true);
        }

        $policyUrl = isset($data['tdm-policy']) && is_string($data['tdm-policy']) ? $data['tdm-policy'] : null;

        return [$reservation, $policyUrl];
    }

    /**
     * Détecte la présence (pas le contenu détaillé) de JSON-LD et de
     * microdata — signal d'intention/maturité du webmaster, indépendant de
     * ce que dit robots.txt. Cherche dans le HTML disponible (le <head>
     * tronqué qu'on a récupéré via Range), pas garanti de tout voir si le
     * balisage est uniquement dans le <body>.
     *
     * @return array{0: bool, 1: bool} [JSON-LD présent, microdata présent]
     */
    private function detectStructuredData(string $html): array
    {
        $hasJsonLd = (bool) preg_match('/<script[^>]+type=["\']application\/ld\+json["\']/i', $html);
        $hasMicrodata = (bool) preg_match('/\bitemscope\b/i', $html) || (bool) preg_match('/\bitemtype=["\']https?:\/\/schema\.org/i', $html);

        return [$hasJsonLd, $hasMicrodata];
    }

    /** @return array<string, string> */
    private function aiBotPolicyFromRobots(RobotsTxt $policy): array
    {
        $result = [];
        foreach (Config::AI_BOTS as $bot) {
            $result[$bot] = $policy->check($bot, '/');
        }

        return $result;
    }

    /** @return array{0: ?string, 1: string[]} [langue par défaut, langues alternatives] */
    private function extractLanguages(string $html): array
    {
        $default = null;
        if (preg_match('/<html[^>]+lang=["\']([a-zA-Z-]+)["\']/i', $html, $m) === 1) {
            // On ne garde que le sous-tag de langue principal ("pl-PL" -> "pl") :
            // la variante pays reste utile pour resolveCountry() (sa propre
            // lecture, indépendante), mais default_language doit rester un
            // simple code langue cohérent pour l'agrégation/le scoring.
            $default = strtolower(explode('-', $m[1], 2)[0]);
        }

        $alternates = [];
        if (preg_match_all('/<link[^>]+rel=["\']alternate["\'][^>]+hreflang=["\']([a-zA-Z-]+)["\']/i', $html, $matches) > 0) {
            foreach ($matches[1] as $lang) {
                $lang = strtolower($lang);
                if ($lang !== $default && $lang !== 'x-default' && !in_array($lang, $alternates, true)) {
                    $alternates[] = $lang;
                }
            }
        }

        return [$default, $alternates];
    }

    /** Résout le pays : TLD en priorité, repli via og:locale ou variante pays de lang si absent (ex. .eu). */
    private function resolveCountry(string $domain, string $html): ?string
    {
        $fromTld = $this->countryFromTld($domain);
        if ($fromTld !== null) {
            return $fromTld;
        }

        // Repli 1 : og:locale (ex. "fr_BE" -> BE)
        if (preg_match('/<meta[^>]+property=["\']og:locale["\'][^>]+content=["\']([a-zA-Z]{2})[_-]([a-zA-Z]{2})["\']/i', $html, $m) === 1) {
            return strtoupper($m[2]);
        }

        // Repli 2 : <html lang="fr-BE"> (variante pays de la langue)
        if (preg_match('/<html[^>]+lang=["\']([a-zA-Z]{2})-([a-zA-Z]{2})["\']/i', $html, $m) === 1) {
            return strtoupper($m[2]);
        }

        return null;
    }

    private function countryFromTld(string $domain): ?string
    {
        $lastDot = strrpos($domain, '.');
        if ($lastDot === false) {
            return null;
        }
        $tld = strtolower(substr($domain, $lastDot + 1));

        if ($tld === 'eu') {
            return null; // supranational — géré par le repli, jamais déduit du TLD
        }

        if (isset(Config::COUNTRY_BY_SPECIAL_TLD[$tld])) {
            return Config::COUNTRY_BY_SPECIAL_TLD[$tld];
        }

        // ccTLD réel : le TLD est directement le code pays ISO 3166-1 alpha-2
        // pour la quasi-totalité des cas (be, fr, de, nl...).
        if (strlen($tld) === 2 && ctype_alpha($tld)) {
            return strtoupper($tld);
        }

        return null;
    }
}
