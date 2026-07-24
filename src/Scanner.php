<?php

declare(strict_types=1);

namespace OgpnBot;

final class Scanner
{
    private readonly CategoryClassifier $categoryClassifier;
    private readonly ProviderRegistry $providerRegistry;

    public function __construct(
        private readonly Http $http,
    ) {
        $this->categoryClassifier = new CategoryClassifier();
        $this->providerRegistry = new ProviderRegistry();
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
        // --- Filtrage de sécurité : format de domaine plausible, avant toute
        // requête réseau (voir DomainSafety pour ce qui est couvert/pas couvert) ---
        $results = [];
        $validDomains = [];
        foreach ($domains as $domain) {
            if (!DomainSafety::isPlausibleDomain($domain)) {
                $results[$domain] = new ScanResult(
                    domain: $domain,
                    robotsBlocksEverything: false,
                    robotsStatus: 'unreachable',
                    aiBotPolicy: [],
                    filePresence: [],
                    fileMisplaced: [],
                    fileConflict: [],
                    providerReferenceVersion: $this->providerRegistry->version(),
                    providerReferenceSha256: $this->providerRegistry->sha256(),
                    countryCode: null,
                    euMember: false,
                    defaultLanguage: null,
                    alternateLanguages: [],
                    httpStatus: null,
                    error: 'Format de domaine invalide — jamais contacté.',
                );
                continue;
            }
            $validDomains[] = $domain;
        }

        // --- Vague 1 : robots.txt uniquement, pour tous les domaines du lot ---
        $wave1Requests = [];
        foreach ($validDomains as $domain) {
            $wave1Requests[$domain] = [
                'robots' => new RequestSpec("https://{$domain}/robots.txt"),
            ];
        }
        $wave1Results = $this->http->fetchBatch($wave1Requests);

        // --- Décision par domaine + préparation de la vague 2 ---
        $wave2Requests = [];
        $robotsByDomain = [];
        foreach ($validDomains as $domain) {
            $robotsFetch = $wave1Results[$domain]['robots'];

            if (!$robotsFetch->ok) {
                // Domaine injoignable dès robots.txt : inutile de continuer.
                $robotsByDomain[$domain] = ['status' => 'unreachable', 'policy' => null];
                continue;
            }

            if (!$robotsFetch->exists()) {
                // Pas de robots.txt (404 etc.) : absence de règle, on continue.
                $robotsByDomain[$domain] = ['status' => 'robots_absent', 'policy' => null];
                $wave2Requests[$domain] = $this->buildWave2Requests($domain);
                continue;
            }

            $policy = new RobotsTxt($robotsFetch->body ?? '');
            $decision = $policy->check(Http::USER_AGENT_TOKEN, '/');
            $robotsByDomain[$domain] = ['status' => 'robots_allowed', 'policy' => $policy, 'decision' => $decision];

            if ($decision === 'disallowed') {
                $robotsByDomain[$domain]['status'] = 'robots_blocked';
                // Respect strict : on s'arrête ici pour ce domaine, on ne lit
                // même pas les autres fichiers RMF.
                continue;
            }

            $wave2Requests[$domain] = $this->buildWave2Requests($domain);
        }

        $wave2Results = $wave2Requests !== [] ? $this->http->fetchBatch($wave2Requests) : [];

        // --- Vague 3 : page d'analyse linguistique prioritaire ---
        // La racine d'un site multilingue renvoie souvent une version NL/EN, une
        // splash page ou une page très pauvre. Pour la catégorie, on privilégie
        // explicitement FR puis EN, puis la langue déclarée par le site si son
        // dictionnaire existe. Les signaux RMF restent lus à leurs emplacements
        // normés ; seule la page HTML utilisée pour l'analyse éditoriale change.
        $languagePageRequests = [];
        foreach ($validDomains as $domain) {
            if (!isset($wave2Results[$domain]['html'])) {
                continue;
            }
            $languageCandidates = $this->buildLanguagePageRequests(
                $domain,
                $wave2Results[$domain]['html']->body ?? '',
            );
            if ($languageCandidates !== []) {
                $languagePageRequests[$domain] = $languageCandidates;
            }
        }
        if ($languagePageRequests !== []) {
            $languagePageResults = $this->http->fetchBatch($languagePageRequests);
            foreach ($languagePageResults as $domain => $requests) {
                $wave2Results[$domain] = [...($wave2Results[$domain] ?? []), ...$requests];
            }
        }

        // --- Assemblage des résultats --- ($results contient déjà les domaines
        // invalides écartés en amont, on complète plutôt que d'écraser)
        foreach ($validDomains as $domain) {
            $robotsInfo = $robotsByDomain[$domain];

            if ($robotsInfo['status'] === 'unreachable') {
                $tldProfile = TldRegistry::profileForDomain($domain);
                $tldCountry = $this->countryFromTld($domain);
                $results[$domain] = new ScanResult(
                    domain: $domain,
                    robotsBlocksEverything: false,
                    robotsStatus: 'unreachable',
                    aiBotPolicy: [],
                    filePresence: [],
                    fileMisplaced: [],
                    fileConflict: [],
                    providerReferenceVersion: $this->providerRegistry->version(),
                    providerReferenceSha256: $this->providerRegistry->sha256(),
                    countryCode: $tldCountry,
                    euMember: Config::isEuMember($tldCountry),
                    defaultLanguage: null,
                    alternateLanguages: [],
                    httpStatus: null,
                    tld: $tldProfile['tld'],
                    tldType: $tldProfile['type'],
                    tldGroups: $tldProfile['groups'],
                    error: $wave1Results[$domain]['robots']->errorMessage,
                );
                continue;
            }

            $blocked = ($robotsInfo['policy'] ?? null) instanceof RobotsTxt
                && $robotsInfo['decision'] === 'disallowed';

            if ($blocked) {
                $tldProfile = TldRegistry::profileForDomain($domain);
                $tldCountry = $this->countryFromTld($domain);
                $results[$domain] = new ScanResult(
                    domain: $domain,
                    robotsBlocksEverything: true,
                    robotsStatus: 'robots_blocked',
                    aiBotPolicy: $this->aiBotPolicyFromRobots($robotsInfo['policy']),
                    unknownAiBotGroups: $this->unknownAiBotGroupsFromRobots($robotsInfo['policy']),
                    filePresence: [],
                    fileMisplaced: [],
                    fileConflict: [],
                    providerReferenceVersion: $this->providerRegistry->version(),
                    providerReferenceSha256: $this->providerRegistry->sha256(),
                    countryCode: $tldCountry,
                    euMember: Config::isEuMember($tldCountry),
                    defaultLanguage: null,
                    alternateLanguages: [],
                    httpStatus: null,
                    tld: $tldProfile['tld'],
                    tldType: $tldProfile['type'],
                    tldGroups: $tldProfile['groups'],
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

    /** @return array<string, RequestSpec> */
    private function buildLanguagePageRequests(string $domain, string $rootHtml): array
    {
        $requests = [];
        $seen = ["https://{$domain}/" => true];

        foreach ($this->analysisLanguagePriority($rootHtml) as $language) {
            $index = 0;
            foreach ($this->extractHreflangUrls($domain, $rootHtml, $language) as $url) {
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $requests["html_lang_{$language}_{$index}"] = new RequestSpec($url, Http::HTML_HEAD_RANGE_BYTES);
                $index++;
            }

            foreach ($this->standardLanguageUrls($domain, $language) as $url) {
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $requests["html_lang_{$language}_{$index}"] = new RequestSpec($url, Http::HTML_HEAD_RANGE_BYTES);
                $index++;
            }
        }

        return array_slice($requests, 0, 12, preserve_keys: true);
    }

    /** @return string[] */
    private function analysisLanguagePriority(string $rootHtml): array
    {
        $languages = ['fr', 'en'];
        [$defaultLanguage, $alternateLanguages] = $this->extractLanguages($rootHtml);

        foreach ([$defaultLanguage, ...$alternateLanguages] as $language) {
            $language = $this->normalizeLanguageCode($language);
            if ($language !== null && !in_array($language, $languages, true) && $this->hasDictionaryForLanguage($language)) {
                $languages[] = $language;
            }
        }

        return $languages;
    }

    /** @return string[] */
    private function extractHreflangUrls(string $domain, string $html, string $preferredLanguage): array
    {
        $urls = [];
        if ($html === '') {
            return $urls;
        }

        if (preg_match_all('/<link\b([^>]+)>/i', $html, $matches) <= 0) {
            return $urls;
        }

        foreach ($matches[1] as $attributes) {
            $rel = strtolower((string) $this->extractTagAttribute($attributes, 'rel'));
            if (!str_contains($rel, 'alternate')) {
                continue;
            }

            $hreflang = strtolower((string) $this->extractTagAttribute($attributes, 'hreflang'));
            $href = $this->extractTagAttribute($attributes, 'href');
            if ($href === null || $hreflang === '' || $hreflang === 'x-default') {
                continue;
            }

            $language = explode('-', str_replace('_', '-', $hreflang), 2)[0];
            if ($language !== $preferredLanguage) {
                continue;
            }

            $url = $this->normalizeInternalUrl($domain, $href);
            if ($url !== null && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /** @return string[] */
    private function standardLanguageUrls(string $domain, string $language): array
    {
        return [
            "https://{$domain}/{$language}",
            "https://{$domain}/{$language}/",
            "https://{$domain}/{$language}-be",
            "https://{$domain}/{$language}-be/",
        ];
    }

    private function normalizeInternalUrl(string $domain, string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return null;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        } elseif (str_starts_with($href, '/')) {
            $href = "https://{$domain}" . $href;
        } elseif (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)) {
            $href = "https://{$domain}/" . ltrim($href, '/');
        }

        $host = parse_url($href, PHP_URL_HOST);
        if (!is_string($host) || !$this->sameSiteHost($host, $domain)) {
            return null;
        }

        return $href;
    }

    private function sameSiteHost(string $host, string $domain): bool
    {
        $host = strtolower(trim($host, '.'));
        $domain = strtolower(trim($domain, '.'));
        if ($host === $domain || $host === 'www.' . $domain) {
            return true;
        }
        if (str_starts_with($domain, 'www.') && $host === substr($domain, 4)) {
            return true;
        }

        return $this->baseDomain($host) === $this->baseDomain($domain);
    }

    /**
     * @param array<string, FetchResult> $wave2
     * @return array{result: ?FetchResult, source: string}
     */
    private function selectAnalysisHtmlCandidate(string $domain, array $wave2): array
    {
        $rootHtml = ($wave2['html'] ?? null) instanceof FetchResult ? (string) $wave2['html']->body : '';
        foreach ($this->analysisLanguagePriority($rootHtml) as $language) {
            $keys = array_filter(
                array_keys($wave2),
                static fn (string $key): bool => str_starts_with($key, "html_lang_{$language}_"),
            );
            sort($keys);
            foreach ($keys as $key) {
                $candidate = $wave2[$key] ?? null;
                if ($candidate instanceof FetchResult && $this->isUsableHtmlResult($candidate)) {
                    return [
                        'result' => $candidate,
                        'source' => $this->analysisSourceForLanguageUrl($domain, $rootHtml, $language, $candidate->url),
                    ];
                }
            }
        }

        $root = $wave2['html'] ?? null;
        return [
            'result' => $root instanceof FetchResult ? $root : null,
            'source' => $root instanceof FetchResult ? 'root' : 'missing',
        ];
    }

    private function analysisSourceForLanguageUrl(string $domain, string $rootHtml, string $language, string $url): string
    {
        if (in_array($url, $this->extractHreflangUrls($domain, $rootHtml, $language), true)) {
            return 'hreflang';
        }

        return 'standard_path';
    }

    private function hasDictionaryForLanguage(string $language): bool
    {
        $language = $this->normalizeLanguageCode($language);
        if ($language === null) {
            return false;
        }

        return is_file(__DIR__ . "/../dictionaries/{$language}.json");
    }

    private function normalizeLanguageCode(?string $language): ?string
    {
        $language = strtolower(trim((string) $language));
        if ($language === '' || $language === 'x-default') {
            return null;
        }
        $language = str_replace('_', '-', $language);
        $language = explode('-', $language, 2)[0];

        return preg_match('/^[a-z]{2,3}$/', $language) === 1 ? $language : null;
    }

    private function isUsableHtmlResult(FetchResult $result): bool
    {
        if (!$result->exists()) {
            return false;
        }

        $body = trim((string) $result->body);
        if ($body === '' || stripos($body, '<html') === false) {
            return false;
        }

        return strlen(strip_tags($this->stripHeavyHtmlNoise($body))) >= 200;
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

        $htmlCandidate = $this->selectAnalysisHtmlCandidate($domain, $wave2);
        $htmlResult = $htmlCandidate['result'];
        $analysisSource = $htmlCandidate['source'];
        $html = $htmlResult?->body ?? '';
        $htmlWithoutComments = $this->stripHtmlComments($html);
        $analysisHtml = $this->stripHeavyHtmlNoise($htmlWithoutComments);

        $robotsPolicy = $robotsInfo['policy'] ?? null;
        $aiBotPolicy = $robotsPolicy instanceof RobotsTxt ? $this->aiBotPolicyFromRobots($robotsPolicy) : [];
        $unknownAiBotGroups = $robotsPolicy instanceof RobotsTxt ? $this->unknownAiBotGroupsFromRobots($robotsPolicy) : [];

        [$defaultLanguage, $alternateLanguages] = $this->extractLanguages($analysisHtml);
        $tldProfile = TldRegistry::profileForDomain($domain);
        $countryCode = $this->resolveCountry($domain, $analysisHtml);

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

        [$hasJsonLd, $hasMicrodata] = $this->detectStructuredData($htmlWithoutComments);
        $cspHeader = $this->extractHeader($htmlResult?->headersRaw ?? '', 'Content-Security-Policy');
        // Le CSP liste souvent des domaines tiers autorisés qui n'apparaissent
        // pas forcément dans les 16 Ko de HTML récupérés (scripts chargés
        // plus loin dans la page) — même détecteur, texte combiné.
        $dependencies = $this->detectDependencies($htmlWithoutComments, $cspHeader ?? '');
        $resourceEvidence = $this->extractExternalResourceEvidence($htmlWithoutComments, $domain);
        $unknownDependencies = $this->detectUnknownDependencies($domain, $resourceEvidence, $dependencies);
        $consentSignals = $this->detectConsentSignals($htmlWithoutComments, $analysisHtml, $dependencies);
        $euMember = Config::isEuMember($countryCode);
        $foodtruckMentions = $this->countFoodtruckMentions($analysisHtml);
        $socialPresence = $this->detectSocialPresence($analysisHtml);
        $categoryClassification = $this->categoryClassifier->classify($analysisHtml, $defaultLanguage);

        // Si robots.txt a bien répondu mais que la page HTML elle-même a
        // échoué ensuite (site tombé entre les deux requêtes, timeout...),
        // on veut garder une trace de cette erreur — jusqu'ici seul l'échec
        // de robots.txt lui-même était enregistré, laissant ce cas muet.
        $htmlError = ($htmlResult !== null && !$htmlResult->ok) ? $htmlResult->errorMessage : null;

        return new ScanResult(
            domain: $domain,
            robotsBlocksEverything: false,
            robotsStatus: $robotsInfo['status'],
            aiBotPolicy: $aiBotPolicy,
            unknownAiBotGroups: $unknownAiBotGroups,
            filePresence: $filePresence,
            fileMisplaced: $fileMisplaced,
            fileConflict: $fileConflict,
            providerReferenceVersion: $this->providerRegistry->version(),
            providerReferenceSha256: $this->providerRegistry->sha256(),
            countryCode: $countryCode,
            euMember: $euMember,
            defaultLanguage: $defaultLanguage,
            alternateLanguages: $alternateLanguages,
            httpStatus: $htmlResult?->statusCode,
            analysisUrl: $htmlResult?->url,
            analysisSource: $analysisSource,
            tld: $tldProfile['tld'],
            tldType: $tldProfile['type'],
            tldGroups: $tldProfile['groups'],
            tdmReservation: $tdmReservation,
            tdmPolicyUrl: $tdmPolicyUrl,
            hasJsonLd: $hasJsonLd,
            hasMicrodata: $hasMicrodata,
            dependencies: $dependencies,
            unknownDependencies: $unknownDependencies,
            consentSignals: $consentSignals,
            ipAddress: $htmlResult?->primaryIp,
            sslIssuer: $htmlResult?->sslIssuer,
            foodtruckMentions: $foodtruckMentions,
            socialPresence: $socialPresence,
            category: $categoryClassification['category'],
            categoryStatus: $categoryClassification['status'],
            categorySource: $categoryClassification['source'],
            categoryConfidence: $categoryClassification['confidence'],
            categorySignals: $categoryClassification['signals'],
            categoryNegativeSignals: $categoryClassification['negative_signals'],
            categoryTier2: $categoryClassification['category_tier2'],
            categoryTier2Confidence: $categoryClassification['category_tier2_confidence'],
            categoryTier2Signals: $categoryClassification['category_tier2_signals'],
            error: $htmlError,
        );
    }

    /**
     * Allège le HTML destiné aux analyses textuelles. Le HTML brut reste
     * utilisé pour les signaux techniques (scripts, link, CSP, dépendances).
     * Ici on retire surtout les blocs qui peuvent manger une grande partie
     * des 150 Ko récupérés sans aider la catégorisation : SVG inline, images
     * longues, srcset/base64, styles et scripts inline.
     */
    private function stripHeavyHtmlNoise(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', ' ', $html) ?? $html;
        $html = preg_replace_callback(
            '/<script\b([^>]*)>.*?<\/script>/is',
            static function (array $matches): string {
                $attributes = $matches[1] ?? '';
                return preg_match('/\btype\s*=\s*["\']application\/ld\+json(?:\s*;[^"\']*)?["\']/i', $attributes) === 1
                    ? $matches[0]
                    : ' ';
            },
            $html
        ) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<template\b[^>]*>.*?<\/template>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<canvas\b[^>]*>.*?<\/canvas>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<(?:pre|code|kbd|samp|textarea|select)\b[^>]*>.*?<\/(?:pre|code|kbd|samp|textarea|select)>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<picture\b[^>]*>.*?<\/picture>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<(?:input|button)\b[^>]*>/is', ' ', $html) ?? $html;
        $html = preg_replace('/\s(?:srcset|sizes|data-[a-z0-9_-]+)=["\'][^"\']{80,}["\']/i', ' ', $html) ?? $html;
        $html = preg_replace('/data:image\/[a-z0-9.+-]+;base64,[a-z0-9+\/=]{80,}/i', ' ', $html) ?? $html;

        return $html;
    }

    private function stripHtmlComments(string $html): string
    {
        if ($html === '') {
            return '';
        }

        return preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
    }

    /** Compte les occurrences de "food truck"/"foodtruck"/"food-truck", insensible à la casse — signal brut, pas propre à un projet précis. */
    private function countFoodtruckMentions(string $html): int
    {
        return preg_match_all('/food[\s-]?truck/i', $html) ?: 0;
    }

    /** Extrait la valeur d'un en-tête HTTP donné depuis le bloc d'en-têtes brut — donnée brute, aucune interprétation. */
    private function extractHeader(string $headersRaw, string $headerName): ?string
    {
        if (preg_match('/^' . preg_quote($headerName, '/') . ':\s*(.+)$/mi', $headersRaw, $m) === 1) {
            return trim($m[1]);
        }

        return null;
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
        $hasJsonLd = (bool) preg_match('/<script[^>]+type=["\']application\/ld\+json(?:\s*;[^"\']*)?["\']/i', $html);
        $hasMicrodata = (bool) preg_match('/\bitemscope\b/i', $html) || (bool) preg_match('/\bitemtype=["\']https?:\/\/schema\.org/i', $html);

        return [$hasJsonLd, $hasMicrodata];
    }

    /**
     * Détecte les fournisseurs connus visibles dans le HTML (CDN, analytics,
     * trackers, CMP, captcha, paiement, CMS) — approche par recherche de
     * sous-chaîne, volontairement simple. Un même fournisseur n'apparaît
     * qu'une fois même si son pattern matche plusieurs fois.
     *
     * @return array<int, array{name: string, category: string, eu_status: string}>
     */
    private function detectDependencies(string $html, string $cspHeader = ''): array
    {
        $found = [];
        $activeResourceText = $this->extractActiveDependencyText($html) . "\n" . $cspHeader;
        $fullText = $html . "\n" . $cspHeader;

        foreach ($this->providerRegistry->providers() as $provider) {
            $category = (string) ($provider['category'] ?? '');
            $searchText = $this->dependencyRequiresActiveResource($category) ? $activeResourceText : $fullText;
            foreach ($provider['patterns'] as $pattern) {
                if (stripos($searchText, $pattern) !== false) {
                    $category = (string) $provider['category'];
                    $found[] = [
                        'name' => $provider['name'],
                        'category' => $category,
                        'eu_status' => $provider['eu_status'],
                        'dependency_score' => $this->dependencySovereigntyWeight((string) $provider['eu_status']),
                        'tracking_governance_role' => $this->trackingGovernanceRole($category),
                    ];
                    break; // un seul pattern suffit, pas la peine de tester les autres pour ce fournisseur
                }
            }
        }

        return $found;
    }

    private function dependencySovereigntyWeight(string $euStatus): float
    {
        return match ($euStatus) {
            'vert' => 1.0,
            'gris', 'jaune' => 0.5,
            default => 0.0,
        };
    }

    private function trackingGovernanceRole(string $category): ?string
    {
        return match ($category) {
            'cmp' => 'cmp',
            'advertising_tracker' => 'advertising_tracker',
            'social_pixel' => 'social_pixel',
            'identifier_attribution' => 'identifier_attribution',
            'tag_manager', 'marketing_cloud' => 'marketing_infrastructure',
            'analytics' => 'analytics',
            'rum_monitoring' => 'rum_monitoring',
            'session_replay' => 'session_replay',
            'ab_testing' => 'ab_testing',
            default => null,
        };
    }

    private function dependencyRequiresActiveResource(string $category): bool
    {
        return in_array($category, [
            'analytics',
            'tag_manager',
            'marketing_cloud',
            'advertising_tracker',
            'social_pixel',
            'identifier_attribution',
            'rum_monitoring',
            'session_replay',
            'ab_testing',
            'cmp',
            'captcha',
            'payment',
            'support_chat',
        ], true);
    }

    private function extractActiveDependencyText(string $html): string
    {
        $parts = [];
        $rules = [
            '/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i',
            '/<iframe\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i',
            '/<embed\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i',
            '/<object\b[^>]*\bdata=["\']([^"\']+)["\'][^>]*>/i',
            '/<form\b[^>]*\baction=["\']([^"\']+)["\'][^>]*>/i',
        ];

        foreach ($rules as $pattern) {
            if (preg_match_all($pattern, $html, $matches) <= 0) {
                continue;
            }
            foreach ($matches[0] as $tag) {
                $parts[] = $tag;
            }
        }

        if (preg_match_all('/<link\b([^>]+)>/i', $html, $matches) > 0) {
            foreach ($matches[0] as $index => $tag) {
                $attrs = $matches[1][$index] ?? '';
                $rel = '';
                if (preg_match('/\brel=["\']([^"\']+)["\']/i', $attrs, $relMatch)) {
                    $rel = strtolower($relMatch[1]);
                }
                $as = '';
                if (preg_match('/\bas=["\']([^"\']+)["\']/i', $attrs, $asMatch)) {
                    $as = strtolower($asMatch[1]);
                }

                if (
                    str_contains($rel, 'stylesheet')
                    || str_contains($rel, 'modulepreload')
                    || (str_contains($rel, 'preload') && in_array($as, ['script', 'style', 'font'], true))
                ) {
                    $parts[] = $tag;
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Extrait seulement les domaines tiers issus de sources HTML fortes déjà
     * téléchargées. Pas de DNS/MX/WHOIS ici : le scanner doit rester rapide
     * (< quelques secondes/domaine) et éthique, avec un coût réseau borné.
     *
     * @return array<string, string[]> Domaine observé => sources d'observation
     */
    private function extractExternalResourceEvidence(string $html, string $domain): array
    {
        $evidence = [];
        $rules = [
            'script_src' => '/<script\b[^>]*\bsrc=["\']([^"\']+)["\']/i',
            'iframe_src' => '/<iframe\b[^>]*\bsrc=["\']([^"\']+)["\']/i',
            'embed_src' => '/<embed\b[^>]*\bsrc=["\']([^"\']+)["\']/i',
            'object_data' => '/<object\b[^>]*\bdata=["\']([^"\']+)["\']/i',
            'form_action' => '/<form\b[^>]*\baction=["\']([^"\']+)["\']/i',
        ];

        foreach ($rules as $source => $pattern) {
            if (preg_match_all($pattern, $html, $matches) <= 0) {
                continue;
            }
            foreach ($matches[1] as $url) {
                $this->addExternalEvidence($evidence, $url, $source, $domain);
            }
        }

        if (preg_match_all('/<link\b([^>]+)>/i', $html, $matches) > 0) {
            foreach ($matches[1] as $attrs) {
                if (!preg_match('/\bhref=["\']([^"\']+)["\']/i', $attrs, $hrefMatch)) {
                    continue;
                }
                $rel = '';
                if (preg_match('/\brel=["\']([^"\']+)["\']/i', $attrs, $relMatch)) {
                    $rel = strtolower($relMatch[1]);
                }

                $as = '';
                if (preg_match('/\bas=["\']([^"\']+)["\']/i', $attrs, $asMatch)) {
                    $as = strtolower($asMatch[1]);
                }

                $isStrongLink = str_contains($rel, 'stylesheet')
                    || str_contains($rel, 'modulepreload')
                    || (str_contains($rel, 'preload') && in_array($as, ['script', 'style', 'font'], true));

                if ($isStrongLink) {
                    $this->addExternalEvidence($evidence, $hrefMatch[1], 'link_href', $domain);
                }
            }
        }

        ksort($evidence);
        return array_slice($evidence, 0, 40, preserve_keys: true);
    }

    /** @param array<string, string[]> $evidence */
    private function addExternalEvidence(array &$evidence, string $url, string $source, string $domain): void
    {
        $host = $this->normalizeObservedHost($url);
        if ($host === '' || !$this->isVendorCandidateHost($host, $domain)) {
            return;
        }

        $evidence[$host] ??= [];
        if (!in_array($source, $evidence[$host], true)) {
            $evidence[$host][] = $source;
        }
    }

    /**
     * @param array<string, string[]> $resourceEvidence
     * @param array<int, array{name: string, category: string, eu_status: string}> $dependencies
     * @return array<int, array{domain: string, sources: string[], suggested_category: string, evidence_types: string[]}>
     */
    private function detectUnknownDependencies(string $domain, array $resourceEvidence, array $dependencies): array
    {
        if ($resourceEvidence === []) {
            return [];
        }

        $unknown = [];
        foreach ($resourceEvidence as $host => $sources) {
            if ($this->matchesKnownDependency($host, $dependencies)) {
                continue;
            }

            $unknown[] = [
                'domain' => $host,
                'sources' => $sources,
                'suggested_category' => $this->suggestDependencyCategory($host, $sources),
                'evidence_types' => array_values(array_unique($sources)),
            ];
        }

        return array_slice($unknown, 0, 25);
    }

    /** @param array<int, array{name: string, category: string, eu_status: string}> $dependencies */
    private function matchesKnownDependency(string $host, array $dependencies): bool
    {
        $matchedNames = array_column($dependencies, 'name');
        foreach ($this->providerRegistry->providers() as $provider) {
            if (!in_array($provider['name'], $matchedNames, true)) {
                continue;
            }
            foreach ($provider['patterns'] as $pattern) {
                if ($this->dependencyPatternMatchesHost($pattern, $host)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function dependencyPatternMatchesHost(string $pattern, string $host): bool
    {
        $pattern = strtolower(trim($pattern));
        $host = strtolower(trim($host, '.'));

        if ($pattern === '' || $host === '') {
            return false;
        }

        // Some dependency signatures deliberately include a path for precise
        // detection (e.g. googletagmanager.com/gtag/js). Unknown dependency
        // filtering only sees the observed host, so compare against the host
        // portion of such signatures instead of treating them as unrelated.
        $patternHost = $this->hostFromDependencyPattern($pattern);
        if ($patternHost !== '') {
            return $host === $patternHost || str_ends_with($host, '.' . $patternHost);
        }

        return stripos($host, $pattern) !== false || stripos($pattern, $host) !== false;
    }

    private function hostFromDependencyPattern(string $pattern): string
    {
        if (str_contains($pattern, '://')) {
            $host = parse_url($pattern, PHP_URL_HOST);
            return is_string($host) ? strtolower(trim($host, '.')) : '';
        }

        if (!str_contains($pattern, '.')) {
            return '';
        }

        $candidate = str_contains($pattern, '/')
            ? explode('/', $pattern, 2)[0]
            : $pattern;
        $candidate = strtolower(trim($candidate, '.'));

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $candidate) === 1 ? $candidate : '';
    }

    private function normalizeObservedHost(string $value): string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, 'data:') || str_starts_with($value, 'mailto:') || str_starts_with($value, 'tel:')) {
            return '';
        }
        if (str_starts_with($value, '//')) {
            $value = 'https:' . $value;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        return strtolower(trim($host, '.'));
    }

    private function isVendorCandidateHost(string $host, string $domain): bool
    {
        if (!DomainSafety::isPlausibleDomain($host) || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        $hostBase = $this->baseDomain($host);
        $domainBase = $this->baseDomain($domain);
        if ($hostBase === '' || $domainBase === '' || $hostBase === $domainBase) {
            return false;
        }

        // Bruit fréquent : domaines de standards, schémas ou fichiers passifs.
        foreach (['schema.org', 'w3.org', 'ogp.me', 'purl.org'] as $ignored) {
            if ($host === $ignored || str_ends_with($host, '.' . $ignored)) {
                return false;
            }
        }

        return true;
    }

    private function baseDomain(string $host): string
    {
        $host = strtolower(trim($host, '.'));
        $parts = explode('.', $host);
        $n = count($parts);
        if ($n <= 2) {
            return $host;
        }

        $last2 = $parts[$n - 2] . '.' . $parts[$n - 1];
        $last3 = $parts[$n - 3] . '.' . $last2;
        $secondLevelPublic = ['co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'com.au', 'com.br', 'com.pl', 'com.tr', 'com.mt', 'com.cy', 'com.gr', 'co.nz', 'com.ua'];

        return in_array($last2, $secondLevelPublic, true) ? $last3 : $last2;
    }

    /** @param string[] $sources */
    private function suggestDependencyCategory(string $host, array $sources): string
    {
        $text = $host . ' ' . implode(' ', $sources);
        return match (true) {
            str_contains($text, 'pay') || str_contains($text, 'checkout') || str_contains($text, 'billing') => 'payment_candidate',
            str_contains($text, 'analytics') || str_contains($text, 'track') || str_contains($text, 'stats') => 'analytics_or_tracking_candidate',
            str_contains($text, 'cdn') || str_contains($text, 'static') || str_contains($text, 'assets') => 'cdn_or_static_candidate',
            str_contains($text, 'font') => 'fonts_candidate',
            str_contains($text, 'captcha') || str_contains($text, 'challenge') => 'captcha_candidate',
            str_contains($text, 'iframe') || str_contains($text, 'video') || str_contains($text, 'player') => 'embed_candidate',
            default => 'unknown_vendor_candidate',
        };
    }

    /**
     * Indices de gouvernance du consentement. Ce n'est pas un verdict de
     * conformite : sans rendu navigateur complet, on observe des traces HTML
     * et scripts, utiles surtout pour des statistiques agregees.
     *
     * @param array<int, array{name: string, category: string, eu_status: string}> $dependencies
     * @return array{
     *     cmp_detected: bool,
     *     cmp_providers: string[],
     *     advertising_trackers: string[],
     *     tracker_categories: string[],
     *     consent_friction_score_auto: int,
     *     consent_friction_score_manual: null,
     *     human_review_needed: bool,
     *     signals: array<int, array{type: string, evidence_type: string, evidence: string}>
     * }
     */
    private function detectConsentSignals(string $rawHtml, string $analysisHtml, array $dependencies): array
    {
        $cmpProviders = [];
        $advertisingTrackers = [];
        $analyticsProviders = [];
        $monitoringProviders = [];
        $trackerCategories = [];
        foreach ($dependencies as $dependency) {
            $name = (string) ($dependency['name'] ?? '');
            $category = (string) ($dependency['category'] ?? '');
            if ($name === '') {
                continue;
            }
            if ($category === 'cmp') {
                $cmpProviders[] = $name;
            }
            if (in_array($category, [
                'tracker', // compatibilite anciens resultats
                'advertising_tracker',
                'social_pixel',
                'identifier_attribution',
                'tag_manager',
                'marketing_cloud',
            ], true)) {
                $advertisingTrackers[] = $name;
                $trackerCategories[] = $category;
            }
            if ($category === 'analytics') {
                $analyticsProviders[] = $name;
            }
            if (in_array($category, ['rum_monitoring', 'session_replay', 'ab_testing'], true)) {
                $monitoringProviders[] = $name;
                $trackerCategories[] = $category;
            }
        }

        $text = strtolower($this->compactVisibleText($analysisHtml));
        $technical = strtolower($rawHtml);
        $signals = [];

        $this->addConsentSignalIf($signals, $this->containsAny($text, [
            'cookie', 'cookies', 'consentement', 'consent', 'privacy choices',
            'vie privee', 'vie privée', 'donnees personnelles', 'données personnelles',
            'traceur', 'traceurs', 'tracking', 'publicite personnalisee', 'publicité personnalisée',
        ]), 'consent_banner_candidate', 'visible_text', 'cookie_or_consent_terms');

        $this->addConsentSignalIf($signals, $this->containsAny($text, [
            'tout accepter', 'accepter tout', 'j accepte', "j'accepte", 'accept all',
            'allow all', 'akkoord', 'alles accepteren', 'aceptar todo',
        ]), 'accept_all_visible', 'visible_text', 'accept_terms_visible');

        $rejectVisible = $this->containsAny($text, [
            'tout refuser', 'refuser tout', 'continuer sans accepter', 'reject all',
            'deny all', 'decline all', 'refuse all', 'alles weigeren', 'weigeren',
            'rechazar todo',
        ]);
        $this->addConsentSignalIf($signals, $rejectVisible, 'reject_all_visible', 'visible_text', 'reject_terms_visible');

        $settingsVisible = $this->containsAny($text, [
            'parametrer', 'paramétrer', 'personnaliser', 'gerer mes choix', 'gérer mes choix',
            'manage choices', 'manage options', 'privacy settings', 'preferences',
            'voorkeuren', 'instellingen', 'configurar',
        ]);
        $this->addConsentSignalIf($signals, $settingsVisible, 'settings_visible', 'visible_text', 'settings_terms_visible');

        $payOrConsent = $this->containsAny($text, [
            'payer ou accepter', 'abonnez-vous ou acceptez', 'abonnement ou cookies',
            's abonner ou accepter', "s'abonner ou accepter", 'pay or consent',
            'subscribe or accept', 'subscription or cookies', 'pay or accept cookies',
            'betalen of accepteren', 'abonneren of accepteren',
        ]);
        $this->addConsentSignalIf($signals, $payOrConsent, 'pay_or_consent_candidate', 'visible_text', 'pay_or_accept_terms');

        $this->addConsentSignalIf($signals, $this->containsAny($technical, [
            '__tcfapi', 'iabtcf', 'euconsent-v2', 'addtl_consent',
            'tcstring', 'cmpapi', 'gdpr_applies',
        ]), 'iab_tcf_candidate', 'technical_marker', 'tcf_technical_markers');

        $acceptVisible = $this->hasSignal($signals, 'accept_all_visible');
        $bannerCandidate = $this->hasSignal($signals, 'consent_banner_candidate');
        $payOrConsentCandidate = $this->hasSignal($signals, 'pay_or_consent_candidate');
        $tcfCandidate = $this->hasSignal($signals, 'iab_tcf_candidate');
        if ($acceptVisible && !$rejectVisible && $settingsVisible) {
            $signals[] = [
                'type' => 'asymmetric_choice_candidate',
                'evidence_type' => 'derived_signal',
                'evidence' => 'accept_and_settings_without_visible_reject',
            ];
        }

        if ($advertisingTrackers !== [] && $cmpProviders === [] && !$bannerCandidate) {
            $signals[] = [
                'type' => 'tracking_without_visible_cmp_candidate',
                'evidence_type' => 'derived_signal',
                'evidence' => 'advertising_or_attribution_dependencies_without_visible_cmp',
            ];
        }

        $score = $this->consentFrictionScore(
            cmpDetected: $cmpProviders !== [],
            bannerCandidate: $bannerCandidate,
            acceptVisible: $acceptVisible,
            rejectVisible: $rejectVisible,
            settingsVisible: $settingsVisible,
            payOrConsentCandidate: $payOrConsentCandidate,
            tcfCandidate: $tcfCandidate,
            advertisingTrackerCount: count(array_unique($advertisingTrackers)),
            monitoringProviderCount: count(array_unique($monitoringProviders)),
        );

        return [
            'cmp_detected' => $cmpProviders !== [],
            'cmp_providers' => array_values(array_unique($cmpProviders)),
            'advertising_trackers' => array_values(array_unique($advertisingTrackers)),
            'analytics_providers' => array_values(array_unique($analyticsProviders)),
            'monitoring_providers' => array_values(array_unique($monitoringProviders)),
            'tracker_categories' => array_values(array_unique($trackerCategories)),
            'consent_friction_score_auto' => $score,
            'consent_friction_score_manual' => null,
            'human_review_needed' => $score >= 3 || $payOrConsentCandidate || $this->hasSignal($signals, 'tracking_without_visible_cmp_candidate'),
            'methodology' => 'observed_html_signals_only_not_legal_verdict',
            'signals' => array_slice($signals, 0, 12),
        ];
    }

    private function consentFrictionScore(
        bool $cmpDetected,
        bool $bannerCandidate,
        bool $acceptVisible,
        bool $rejectVisible,
        bool $settingsVisible,
        bool $payOrConsentCandidate,
        bool $tcfCandidate,
        int $advertisingTrackerCount,
        int $monitoringProviderCount,
    ): int {
        if ($payOrConsentCandidate) {
            return 5;
        }

        $score = 0;
        if ($cmpDetected || $bannerCandidate) {
            $score = 1;
        }
        if ($advertisingTrackerCount > 0 || $monitoringProviderCount > 0) {
            $score = max($score, 2);
        }
        if ($tcfCandidate || $advertisingTrackerCount >= 3) {
            $score = max($score, 3);
        }
        if ($acceptVisible && !$rejectVisible && $settingsVisible) {
            $score = max($score, 3);
        }
        if ($acceptVisible && !$rejectVisible && !$settingsVisible) {
            $score = max($score, 4);
        }
        if (($advertisingTrackerCount > 0 || $monitoringProviderCount > 0) && !$cmpDetected && !$bannerCandidate) {
            $score = max($score, 3);
        }

        return min(5, $score);
    }

    private function compactVisibleText(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    /** @param string[] $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array{type: string, evidence_type: string, evidence: string}> $signals */
    private function addConsentSignalIf(array &$signals, bool $condition, string $type, string $evidenceType, string $evidence): void
    {
        if (!$condition || $this->hasSignal($signals, $type)) {
            return;
        }

        $signals[] = ['type' => $type, 'evidence_type' => $evidenceType, 'evidence' => $evidence];
    }

    /** @param array<int, array{type: string, evidence_type: string, evidence: string}> $signals */
    private function hasSignal(array $signals, string $type): bool
    {
        foreach ($signals as $signal) {
            if (($signal['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Détecte la présence de l'organisation sur les réseaux sociaux et
     * plateformes de code — recherche STRICTEMENT limitée aux attributs
     * href="", jamais dans le texte ni le code technique de la page
     * (contrairement à detectDependencies). Un mot "Facebook" mentionné
     * dans un article ne compte pas ; un vrai lien vers une page Facebook
     * compte.
     *
     * @return array<int, array{name: string, slug: string, eu_status: string, type: ?string}>
     */
    private function detectSocialPresence(string $html): array
    {
        $hrefs = $this->extractHrefs($html);
        $found = [];

        foreach ($hrefs as $href) {
            $matchedFixedDomain = false;

            // Premier passage : plateformes à domaine fixe (recherche simple).
            foreach (Config::SOCIAL_PLATFORMS as $platform) {
                if ($platform['generic'] ?? false) {
                    continue; // traitées au second passage seulement
                }
                foreach ($platform['patterns'] as $pattern) {
                    if (stripos($href, $pattern) !== false) {
                        $matchedFixedDomain = true;
                        $detectedType = $this->detectSocialLinkType($href, $platform);

                        if (!isset($found[$platform['slug']])) {
                            $found[$platform['slug']] = [
                                'name' => $platform['name'],
                                'slug' => $platform['slug'],
                                'eu_status' => $platform['eu_status'],
                                'type' => $detectedType,
                            ];
                        } elseif ($found[$platform['slug']]['type'] === null && $detectedType !== null) {
                            // Un lien précédent pour ce même réseau n'avait pas
                            // permis d'identifier un type précis — celui-ci le
                            // permet, on complète plutôt que de garder "null".
                            $found[$platform['slug']]['type'] = $detectedType;
                        }
                        break 2;
                    }
                }
            }

            if ($matchedFixedDomain) {
                continue;
            }

            // Second passage : motifs génériques (Mastodon) — uniquement pour
            // les liens qu'aucune plateforme à domaine fixe n'a réclamés, afin
            // d'éviter les faux positifs (TikTok/Threads utilisent aussi "/@").
            foreach (Config::SOCIAL_PLATFORMS as $platform) {
                if (!($platform['generic'] ?? false)) {
                    continue;
                }
                foreach ($platform['patterns'] as $pattern) {
                    if (preg_match($pattern, $href) === 1) {
                        if (!isset($found[$platform['slug']])) {
                            $found[$platform['slug']] = [
                                'name' => $platform['name'],
                                'slug' => $platform['slug'],
                                'eu_status' => $platform['eu_status'],
                                'type' => null,
                            ];
                        }
                        break 2;
                    }
                }
            }
        }

        return array_values($found);
    }

    /** @return string[] Toutes les valeurs d'attributs href="" trouvées dans le HTML. */
    private function extractHrefs(string $html): array
    {
        if (preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches) > 0) {
            return $matches[1];
        }

        return [];
    }

    private function extractTagAttribute(string $attributes, string $name): ?string
    {
        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $attributes, $match) === 1) {
            return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    /** @param array{type_patterns?: array<string, string>, default_type?: string} $platform */
    private function detectSocialLinkType(string $href, array $platform): ?string
    {
        foreach ($platform['type_patterns'] ?? [] as $type => $pattern) {
            if (stripos($href, $pattern) !== false) {
                return $type;
            }
        }

        return $platform['default_type'] ?? null;
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

    /**
     * User-agents déclarés dans robots.txt qui ne correspondent à aucune
     * entrée connue de Config::AI_BOTS ni à un crawler classique connu
     * (Config::KNOWN_NON_AI_USER_AGENTS). Ne préjuge pas qu'il s'agisse d'un
     * bot IA — juste un candidat à qualifier manuellement, au même esprit que
     * unknown_dependencies pour les fournisseurs tiers.
     *
     * @return string[]
     */
    private function unknownAiBotGroupsFromRobots(RobotsTxt $policy): array
    {
        $known = array_map(strtolower(...), Config::AI_BOTS);
        $noise = Config::KNOWN_NON_AI_USER_AGENTS;

        $unknown = [];
        foreach ($policy->declaredUserAgents() as $agent) {
            if (in_array($agent, $known, true) || in_array($agent, $noise, true)) {
                continue;
            }
            $unknown[] = $agent;
        }

        sort($unknown);

        return $unknown;
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

        if (in_array($tld, Config::NON_COUNTRY_TLDS, true)) {
            return null; // supranational ou réservé — jamais déduit mécaniquement
        }

        if (isset(Config::COUNTRY_BY_SPECIAL_TLD[$tld])) {
            return Config::COUNTRY_BY_SPECIAL_TLD[$tld];
        }

        $tldProfile = TldRegistry::profileForDomain($domain);
        if ($tldProfile['country_code'] !== null && $tldProfile['country_code'] !== 'EU') {
            return $tldProfile['country_code'];
        }

        // ccTLD réel : le TLD est directement le code pays ISO 3166-1 alpha-2
        // pour la quasi-totalité des cas (be, fr, de, nl...).
        if (strlen($tld) === 2 && ctype_alpha($tld)) {
            return strtoupper($tld);
        }

        return null;
    }
}
