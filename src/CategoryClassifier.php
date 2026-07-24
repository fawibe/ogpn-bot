<?php

declare(strict_types=1);

namespace OgpnBot;

/**
 * Classification de catégorie, style IAB Content Taxonomy 3.0 (Tier 1
 * + sous-catégorie prudente). La classification porte exclusivement sur la page principale
 * récupérée par le scan, pas sur le site complet.
 *
 * Trois sources de signal, dans cet ordre de priorité :
 *
 *  1. Données structurées JSON-LD puis microdata schema.org — quand le site
 *     déclare lui-même son type, c'est plus fiable qu'une heuristique par mots-clés.
 *  2. Balises éditoriales fortes : <title>, meta description, OpenGraph/Twitter
 *     title/description. Elles décrivent la page et pèsent plus lourd que le corps.
 *  3. Texte visible nettoyé : le <head>, les scripts, styles, SVG, templates et
 *     blocs de code sont supprimés avant l'analyse par dictionnaire.
 *
 * Le dictionnaire de mots-clés par langue détectée + socle minimal commun est
 * pondéré par des couples/séries de termes et des contextes négatifs.
 *     Un mot isolé ne suffit donc pas à classer une page d'accueil.
 *     Les correspondances restent strictes : pas de lemmatisation automatique,
 *     mais les termes d'un seul mot acceptent quelques pluriels simples selon
 *     la langue détectée. Les langues à déclinaisons complexes et les variantes
 *     métier ambiguës restent à ajouter progressivement aux dictionnaires et aux
 *     couples context_required.
 *
 * "sensitive_topics" (limité à Adult & Explicit) n'est testé qu'en tout
 * dernier recours, si rien de neutre n'a matché.
 */
final class CategoryClassifier
{
    private const DICTIONARIES_DIR = __DIR__ . '/../dictionaries';
    private const MIN_CONFIDENCE = 35;
    private const MIN_SENSITIVE_CONFIDENCE = 55;

    /** Correspondance schema.org @type -> catégorie Tier 1 — volontairement partielle, à enrichir au fil du temps. */
    private const JSON_LD_TYPE_MAP = [
        'governmentorganization' => 'government',
        'localbusiness' => 'business_and_finance',
        'store' => 'shopping',
        'onlinestore' => 'shopping',
        'restaurant' => 'food_and_drink',
        'foodestablishment' => 'food_and_drink',
        'cafeorcoffeeshop' => 'food_and_drink',
        'bakery' => 'food_and_drink',
        'bar' => 'food_and_drink',
        'medicalorganization' => 'medical_health',
        'hospital' => 'medical_health',
        'physician' => 'medical_health',
        'dentist' => 'medical_health',
        'educationalorganization' => 'education',
        'school' => 'education',
        'college' => 'education',
        'university' => 'education',
        'sportsorganization' => 'sports',
        'sportsclub' => 'sports',
        'hotel' => 'travel',
        'lodgingbusiness' => 'travel',
        'travelagency' => 'travel',
        'airline' => 'transport_and_logistics',
        'taxi' => 'transport_and_logistics',
        'taxiservice' => 'transport_and_logistics',
        'busorcoach' => 'transport_and_logistics',
        'flight' => 'transport_and_logistics',
        'traintrip' => 'transport_and_logistics',
        'boattrip' => 'transport_and_logistics',
        'deliveryservice' => 'transport_and_logistics',
        'parcelservice' => 'transport_and_logistics',
        'movingcompany' => 'transport_and_logistics',
        'automotivebusiness' => 'automotive',
        'autodealer' => 'automotive',
        'autorepair' => 'automotive',
        'realestateagent' => 'real_estate',
        'financialservice' => 'personal_finance',
        'bankorcreditunion' => 'personal_finance',
        'insuranceagency' => 'personal_finance',
        'legalservice' => 'business_and_finance',
        'attorney' => 'business_and_finance',
        'book' => 'books_and_literature',
        'movietheater' => 'entertainment',
        'musicgroup' => 'entertainment',
        'museum' => 'attractions',
        'artgallery' => 'fine_art',
        'zoo' => 'attractions',
        'amusementpark' => 'attractions',
        'petstore' => 'pets',
        'veterinarycare' => 'pets',
        'gymandfitnesscenter' => 'healthy_living',
        'placeofworship' => 'religion_and_spirituality',
        'church' => 'religion_and_spirituality',
        'mosque' => 'religion_and_spirituality',
        'synagogue' => 'religion_and_spirituality',
    ];

    /** @var array<string, string[]>|null Cache en mémoire — chargé une seule fois par instance. */
    private ?array $minimalDictionary = null;

    /** @var array<string, array<string, string[]>> Cache par langue déjà chargée. */
    private array $languageDictionaryCache = [];

    /**
     * @return array{
     *   category: string,
     *   status: 'identified'|'unidentified',
     *   source: 'json_ld'|'microdata'|'keywords'|'homepage_insufficient_signals',
     *   confidence: int,
     *   signals: string[],
     *   negative_signals: string[],
     *   category_tier2: ?string,
     *   category_tier2_confidence: int,
     *   category_tier2_signals: string[]
     * }
     */
    public function classify(string $html, ?string $defaultLanguage): array
    {
        $structuredCategory = $this->classifyFromStructuredData($html);
        if ($structuredCategory !== null) {
            $classificationText = $this->metadataToSearchableText($html) . ' ' . $this->htmlToVisibleSearchableText($html);
            $tier2 = $this->classifyTier2($structuredCategory['category'], $classificationText, $defaultLanguage);
            return [
                'category' => $structuredCategory['category'],
                'status' => 'identified',
                'source' => $structuredCategory['source'],
                'confidence' => 90,
                'signals' => [$structuredCategory['signal']],
                'negative_signals' => [],
                'category_tier2' => $tier2['slug'],
                'category_tier2_confidence' => $tier2['confidence'],
                'category_tier2_signals' => $tier2['signals'],
            ];
        }

        $keywordClassification = $this->classifyFromKeywords($html, $defaultLanguage);
        if ($keywordClassification !== null) {
            return $keywordClassification;
        }

        return [
            'category' => 'unidentified',
            'status' => 'unidentified',
            'source' => 'homepage_insufficient_signals',
            'confidence' => 0,
            'signals' => [],
            'negative_signals' => [],
            'category_tier2' => null,
            'category_tier2_confidence' => 0,
            'category_tier2_signals' => [],
        ];
    }

    /** @return array{category: string, source: 'json_ld'|'microdata', signal: string}|null */
    private function classifyFromStructuredData(string $html): ?array
    {
        $jsonLdCategory = $this->classifyFromJsonLd($html);
        if ($jsonLdCategory !== null) {
            return [
                'category' => $jsonLdCategory,
                'source' => 'json_ld',
                'signal' => 'json_ld:@type',
            ];
        }

        $microdataCategory = $this->classifyFromMicrodata($html);
        if ($microdataCategory !== null) {
            return [
                'category' => $microdataCategory,
                'source' => 'microdata',
                'signal' => 'microdata:itemtype',
            ];
        }

        return null;
    }

    /** Cherche un @type JSON-LD reconnu — signal le plus fiable, testé en premier. */
    private function classifyFromJsonLd(string $html): ?string
    {
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json(?:\s*;[^"\']*)?["\'][^>]*>(.*?)<\/script>/is', $html, $matches) === false) {
            return null;
        }

        foreach ($matches[1] ?? [] as $jsonText) {
            $decoded = json_decode($jsonText, associative: true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($this->extractJsonLdTypes($decoded) as $type) {
                $mapped = self::JSON_LD_TYPE_MAP[strtolower($type)] ?? null;
                if ($mapped !== null) {
                    return $mapped;
                }
            }
        }

        return null;
    }

    /** Cherche un itemtype schema.org reconnu dans les microdonnées HTML. */
    private function classifyFromMicrodata(string $html): ?string
    {
        if (preg_match_all('/\bitemtype\s*=\s*["\']([^"\']*schema\.org\/([^"\'\s#?]+)[^"\']*)["\']/i', $html, $matches) === false) {
            return null;
        }

        foreach ($matches[2] ?? [] as $type) {
            $type = strtolower(trim((string) $type));
            $mapped = self::JSON_LD_TYPE_MAP[$type] ?? null;
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $decoded
     * @return string[]
     */
    private function extractJsonLdTypes(array $decoded): array
    {
        $types = [];
        $type = $decoded['@type'] ?? null;
        if (is_string($type)) {
            $types[] = $type;
        }
        if (is_array($type)) {
            foreach ($type as $entry) {
                if (is_string($entry)) {
                    $types[] = $entry;
                }
            }
        }
        // Bloc @graph (plusieurs entités décrites ensemble) — on parcourt tout
        // le graphe pour ne pas laisser un type générique masquer un type métier.
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            foreach ($decoded['@graph'] as $entry) {
                if (is_array($entry)) {
                    $types = [...$types, ...$this->extractJsonLdTypes($entry)];
                }
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @return array{
     *   category: string,
     *   status: 'identified',
     *   source: 'keywords',
     *   confidence: int,
     *   signals: string[],
     *   negative_signals: string[],
     *   category_tier2: ?string,
     *   category_tier2_confidence: int,
     *   category_tier2_signals: string[]
     * }|null
     */
    private function classifyFromKeywords(string $html, ?string $defaultLanguage): ?array
    {
        $dictionary = $this->buildEffectiveDictionary($defaultLanguage);
        $metadataText = $this->metadataToSearchableText($html);
        $visibleText = $this->htmlToVisibleSearchableText($html);
        $classificationText = trim($metadataText . ' ' . $visibleText);

        $best = null;

        foreach ($dictionary as $slug => $keywords) {
            if ($slug === 'sensitive_topics') {
                continue; // testé séparément, en tout dernier recours
            }
            $score = $this->scoreCategory($slug, $metadataText, $visibleText, $keywords, $defaultLanguage);
            if ($score['confidence'] >= self::MIN_CONFIDENCE && ($best === null || $score['confidence'] > $best['confidence'])) {
                $best = $score;
            }
        }

        if ($best !== null) {
            $tier2 = $this->classifyTier2($best['category'], $classificationText, $defaultLanguage);
            return [
                'category' => $best['category'],
                'status' => 'identified',
                'source' => 'keywords',
                'confidence' => $best['confidence'],
                'signals' => $best['signals'],
                'negative_signals' => $best['negative_signals'],
                'category_tier2' => $tier2['slug'],
                'category_tier2_confidence' => $tier2['confidence'],
                'category_tier2_signals' => $tier2['signals'],
            ];
        }

        // Dernier recours : Sensitive Topics (Adult & Explicit uniquement).
        $sensitiveKeywords = $dictionary['sensitive_topics'] ?? [];
        $sensitiveScore = $this->scoreCategory('sensitive_topics', $metadataText, $visibleText, $sensitiveKeywords, $defaultLanguage);
        if ($sensitiveScore['confidence'] >= self::MIN_SENSITIVE_CONFIDENCE) {
            $tier2 = $this->classifyTier2('sensitive_topics', $classificationText, $defaultLanguage);
            return [
                'category' => 'sensitive_topics',
                'status' => 'identified',
                'source' => 'keywords',
                'confidence' => $sensitiveScore['confidence'],
                'signals' => $sensitiveScore['signals'],
                'negative_signals' => $sensitiveScore['negative_signals'],
                'category_tier2' => $tier2['slug'],
                'category_tier2_confidence' => $tier2['confidence'],
                'category_tier2_signals' => $tier2['signals'],
            ];
        }

        return null;
    }

    /**
     * @param string[] $keywords
     * @return array{category: string, confidence: int, signals: string[], negative_signals: string[]}
     */
    private function scoreCategory(string $slug, string $metadataText, string $visibleText, array $keywords, ?string $language): array
    {
        $signals = [];
        $negativeSignals = [];
        $score = 0;
        $allText = trim($metadataText . ' ' . $visibleText);

        foreach ($keywords as $keyword) {
            if ($metadataText !== '' && $this->containsTerm($metadataText, $keyword, $language)) {
                $signals[] = 'meta:' . $keyword;
                $score += str_contains($keyword, ' ') || str_contains($keyword, '-') ? 16 : 6;
            }
            if ($visibleText !== '' && $this->containsTerm($visibleText, $keyword, $language)) {
                $signals[] = $keyword;
                $score += str_contains($keyword, ' ') || str_contains($keyword, '-') ? 8 : 3;
            }
        }

        foreach (self::CONTEXT_RULES[$slug]['strong_phrases'] ?? [] as $phrase) {
            if ($metadataText !== '' && $this->containsTerm($metadataText, $phrase, $language)) {
                $signals[] = 'meta:' . $phrase;
                $score += 35;
            }
            if ($visibleText !== '' && $this->containsTerm($visibleText, $phrase, $language)) {
                $signals[] = $phrase;
                $score += 25;
            }
        }

        foreach (self::CONTEXT_RULES[$slug]['context_required'] ?? [] as $terms) {
            if ($this->containsAllTerms($allText, $terms, $language)) {
                $signals[] = implode(' + ', $terms);
                $score += 30;
            }
        }

        foreach (self::CONTEXT_RULES[$slug]['negative_context'] ?? [] as $terms) {
            if ($this->containsAllTerms($allText, $terms, $language)) {
                $negativeSignals[] = implode(' + ', $terms);
                $score -= 35;
            }
        }

        $confidence = max(0, min(100, $score));

        return [
            'category' => $slug,
            'confidence' => $confidence,
            'signals' => array_values(array_unique($signals)),
            'negative_signals' => array_values(array_unique($negativeSignals)),
        ];
    }

    /** @return array{slug: ?string, confidence: int, signals: string[]} */
    private function classifyTier2(string $category, string $text, ?string $language): array
    {
        $best = ['slug' => null, 'confidence' => 0, 'signals' => []];

        foreach (self::TIER2_RULES[$category] ?? [] as $slug => $rule) {
            $signals = [];
            $score = 0;

            foreach ($rule['phrases'] ?? [] as $phrase) {
                if ($this->containsTerm($text, $phrase, $language)) {
                    $signals[] = $phrase;
                    $score += str_contains($phrase, ' ') || str_contains($phrase, '-') ? 18 : 8;
                }
            }

            foreach ($rule['required'] ?? [] as $terms) {
                if ($this->containsAllTerms($text, $terms, $language)) {
                    $signals[] = implode(' + ', $terms);
                    $score += 30;
                }
            }

            $confidence = max(0, min(100, $score));
            if ($confidence >= 35 && $confidence > $best['confidence']) {
                $best = [
                    'slug' => $slug,
                    'confidence' => $confidence,
                    'signals' => array_values(array_unique($signals)),
                ];
            }
        }

        return $best;
    }

    private function metadataToSearchableText(string $html): string
    {
        $parts = [];

        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match) === 1) {
            $parts[] = $match[1];
        }

        if (preg_match_all('/<meta\b([^>]+)>/is', $html, $matches) > 0) {
            foreach ($matches[1] as $attributes) {
                $name = $this->extractHtmlAttribute($attributes, 'name');
                $property = $this->extractHtmlAttribute($attributes, 'property');
                $content = $this->extractHtmlAttribute($attributes, 'content');
                if ($content === null || trim($content) === '') {
                    continue;
                }

                $key = strtolower((string) ($name ?? $property ?? ''));
                if (in_array($key, ['description', 'keywords', 'og:title', 'og:description', 'twitter:title', 'twitter:description'], true)) {
                    $parts[] = $content;
                }
            }
        }

        $text = html_entity_decode(strip_tags(implode(' ', $parts)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function htmlToVisibleSearchableText(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
        $html = preg_replace('/<head\b[^>]*>.*?<\/head>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<template\b[^>]*>.*?<\/template>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<canvas\b[^>]*>.*?<\/canvas>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<(?:pre|code|kbd|samp|textarea|select)\b[^>]*>.*?<\/(?:pre|code|kbd|samp|textarea|select)>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<(?:input|button)\b[^>]*>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<[^>]+\b(?:hidden|aria-hidden\s*=\s*(["\'])true\1|style\s*=\s*(["\'])[^"\']*display\s*:\s*none[^"\']*\2)[^>]*>.*?<\/[^>]+>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function extractHtmlAttribute(string $attributes, string $name): ?string
    {
        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $attributes, $match) === 1) {
            return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    /** @param string[] $terms */
    private function containsAllTerms(string $text, array $terms, ?string $language): bool
    {
        foreach ($terms as $term) {
            if (!$this->containsTerm($text, $term, $language)) {
                return false;
            }
        }

        return true;
    }

    private function containsTerm(string $text, string $term, ?string $language): bool
    {
        $variants = array_map(
            static fn (string $variant): string => preg_quote($variant, '/'),
            $this->termVariants($term, $language)
        );
        $pattern = '/(?<![\p{L}\p{N}])(?:' . implode('|', $variants) . ')(?![\p{L}\p{N}])/iu';

        return preg_match($pattern, $text) === 1;
    }

    /**
     * @return string[]
     */
    private function termVariants(string $term, ?string $language): array
    {
        $term = trim($term);
        $variants = [$term];

        if ($term === '' || preg_match('/\s|-/u', $term) === 1 || preg_match('/^[\p{L}]{4,}$/u', $term) !== 1) {
            return $variants;
        }

        $language = $this->normalizeLanguage($language);
        $lower = strtolower($term);
        if (preg_match('/[sxz]$/u', $lower) === 1) {
            return $variants;
        }

        switch ($language) {
            case 'fr':
                $variants[] = preg_match('/(eau|au|eu)$/u', $lower) === 1 ? $term . 'x' : $term . 's';
                break;

            case 'en':
                if (preg_match('/[^aeiou]y$/u', $lower) === 1) {
                    $variants[] = substr($term, 0, -1) . 'ies';
                } elseif (preg_match('/(ch|sh)$/u', $lower) === 1) {
                    $variants[] = $term . 'es';
                } else {
                    $variants[] = $term . 's';
                }
                break;

            case 'de':
            case 'nl':
                $variants[] = $term . 's';
                $variants[] = $term . 'en';
                break;

            case 'es':
            case 'pt':
                $variants[] = preg_match('/[aeiou]$/u', $lower) === 1 ? $term . 's' : $term . 'es';
                break;

            case 'tr':
                $variants[] = preg_match('/[eiöü]$/u', $lower) === 1 ? $term . 'ler' : $term . 'lar';
                break;

            default:
                $variants[] = $term . 's';
        }

        return array_values(array_unique($variants));
    }

    private function normalizeLanguage(?string $language): string
    {
        $language = strtolower(trim((string) $language));
        $language = preg_replace('/[_-].*$/', '', $language) ?? $language;

        return $language !== '' ? $language : 'en';
    }

    /**
     * Combine le dictionnaire de la langue détectée, s'il existe sur disque,
     * avec le socle minimal commun, toujours actif.
     *
     * @return array<string, string[]>
     */
    private function buildEffectiveDictionary(?string $defaultLanguage): array
    {
        $minimal = $this->loadMinimalDictionary();
        $primary = $this->loadLanguageDictionary($defaultLanguage ?? 'en');
        if ($primary === [] && $this->normalizeLanguage($defaultLanguage) !== 'en') {
            // Si la langue déclarée du site n'a pas encore de dictionnaire
            // exploitable, on retombe sur l'anglais. Le résultat restera souvent
            // "unidentified", mais on évite de classer avec le seul socle commun.
            $primary = $this->loadLanguageDictionary('en');
        }

        $merged = $minimal;
        foreach ($primary as $slug => $keywords) {
            $merged[$slug] = array_unique([...($merged[$slug] ?? []), ...$keywords]);
        }

        return $merged;
    }

    /** @return array<string, string[]> */
    private function loadMinimalDictionary(): array
    {
        if ($this->minimalDictionary === null) {
            $this->minimalDictionary = $this->loadDictionaryFile(self::DICTIONARIES_DIR . '/common-minimal.json');
            if ($this->minimalDictionary === []) {
                $this->minimalDictionary = $this->loadDictionaryFile(self::DICTIONARIES_DIR . '/en-minimal.json');
            }
        }

        return $this->minimalDictionary;
    }

    /** @return array<string, string[]> */
    private function loadLanguageDictionary(string $language): array
    {
        $language = $this->normalizeLanguage($language);
        if (isset($this->languageDictionaryCache[$language])) {
            return $this->languageDictionaryCache[$language];
        }

        $path = self::DICTIONARIES_DIR . "/{$language}.json";
        $dictionary = is_file($path) ? $this->loadDictionaryFile($path) : [];
        $this->languageDictionaryCache[$language] = $dictionary;

        return $dictionary;
    }

    /** @return array<string, string[]> */
    private function loadDictionaryFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return [];
        }
        $decoded = json_decode($content, associative: true);

        if (!is_array($decoded)) {
            return [];
        }

        $dictionary = [];
        foreach ($decoded as $slug => $keywords) {
            if (!is_string($slug) || str_starts_with($slug, '_') || !is_array($keywords)) {
                continue;
            }

            $dictionary[$slug] = array_values(array_filter(
                $keywords,
                static fn (mixed $keyword): bool => is_string($keyword) && trim($keyword) !== ''
            ));
        }

        return $dictionary;
    }

    /**
     * Couples/séries et contextes négatifs destinés à éviter les pièges les
     * plus courants sur une page d'accueil : un auteur qui parle de sexualité,
     * un fournisseur pour restaurants, une agence qui cite ses clients, etc.
     *
     * @var array<string, array{strong_phrases?: string[], context_required?: string[][], negative_context?: string[][]}>
     */
    private const CONTEXT_RULES = [
        'education' => [
            'strong_phrases' => [
                'centre de formation', 'formation professionnelle', 'organisme de formation',
                'training center', 'vocational training', 'online course',
            ],
            'context_required' => [
                ['formation', 'inscription'], ['formation', 'certification'], ['cours', 'programme'],
                ['training', 'registration'], ['course', 'certification'], ['academy', 'students'],
            ],
            'negative_context' => [
                ['article', 'formation'], ['actualité', 'formation'], ['blog', 'formation'],
                ['client', 'formation'], ['agency', 'training'], ['article', 'training'],
            ],
        ],
        'attractions' => [
            'strong_phrases' => ['parc d’attractions', 'parc attraction', 'musée interactif', 'amusement park', 'theme park', 'visitor attraction'],
            'context_required' => [
                ['billetterie', 'visite'], ['tickets', 'visit'], ['attraction', 'horaires'],
                ['museum', 'tickets'], ['zoo', 'tickets'], ['park', 'tickets'],
            ],
            'negative_context' => [
                ['article', 'attraction'], ['blog', 'attraction'], ['physics', 'attraction'],
            ],
        ],
        'automotive' => [
            'strong_phrases' => ['garage automobile', 'concession automobile', 'contrôle technique', 'auto repair', 'car dealer', 'car dealership'],
            'context_required' => [
                ['voiture', 'garage'], ['véhicule', 'entretien'], ['pneu', 'montage'],
                ['car', 'repair'], ['vehicle', 'service'], ['tyre', 'fitting'],
            ],
            'negative_context' => [
                ['article', 'voiture'], ['actualité', 'automobile'], ['movie', 'cars'],
            ],
        ],
        'books_and_literature' => [
            'strong_phrases' => ['maison d’édition', 'maison edition', 'librairie indépendante', 'book publisher', 'book shop', 'online bookstore'],
            'context_required' => [
                ['livre', 'auteur'], ['livre', 'auteurs'], ['livres', 'auteur'], ['livres', 'auteurs'],
                ['roman', 'édition'], ['romans', 'édition'], ['librairie', 'commande'],
                ['book', 'author'], ['novel', 'publisher'], ['bookstore', 'order'],
            ],
            'negative_context' => [
                ['livre', 'comptable'], ['booking', 'hotel'], ['booking', 'appointment'],
            ],
        ],
        'business_and_finance' => [
            'strong_phrases' => ['cabinet de conseil', 'services aux entreprises', 'expert-comptable', 'business consulting', 'accounting firm', 'corporate services'],
            'context_required' => [
                ['entreprise', 'conseil'], ['société', 'service'], ['comptable', 'fiscalité'],
                ['business', 'consulting'], ['company', 'services'], ['accounting', 'tax'],
            ],
            'negative_context' => [
                ['finance', 'personnelle'], ['personal', 'finance'], ['article', 'business'],
            ],
        ],
        'transport_and_logistics' => [
            'strong_phrases' => [
                'transport public', 'transport en commun', 'billets de train',
                'horaires en gare', 'transport ferroviaire', 'compagnie aérienne',
                'transport maritime', 'transport de marchandises', 'livraison express',
                'public transport', 'train tickets', 'rail transport', 'parcel delivery',
                'freight logistics',
            ],
            'context_required' => [
                ['train', 'billet'], ['train', 'gare'], ['voyager', 'réserver'],
                ['bus', 'voyage'], ['aéroport', 'vol'], ['colis', 'livraison'],
                ['fret', 'transport'], ['marchandises', 'transport'], ['logistique', 'transport'],
                ['train', 'tickets'], ['airport', 'flight'], ['freight', 'shipping'],
                ['parcel', 'delivery'], ['public', 'transport'],
            ],
            'negative_context' => [
                ['article', 'transport'], ['actualité', 'transport'], ['voiture', 'location'],
                ['voyage', 'blog'], ['travel', 'blog'],
            ],
        ],
        'careers' => [
            'strong_phrases' => ['offres d’emploi', 'recrutement en cours', 'job openings', 'career opportunities', 'vacancies'],
            'context_required' => [
                ['emploi', 'candidature'], ['recrutement', 'poste'], ['cv', 'offre'],
                ['job', 'apply'], ['career', 'vacancy'], ['recruitment', 'position'],
            ],
            'negative_context' => [
                ['formation', 'emploi'], ['article', 'emploi'], ['job', 'training'],
            ],
        ],
        'technology_and_computing' => [
            'strong_phrases' => ['développement logiciel', 'cybersécurité', 'hébergement web', 'software development', 'cybersecurity', 'cloud computing'],
            'context_required' => [
                ['logiciel', 'développement'], ['site', 'web'], ['sécurité', 'informatique'],
                ['software', 'development'], ['web', 'hosting'], ['security', 'audit'],
            ],
            'negative_context' => [
                ['article', 'technologie'], ['actualité', 'ia'], ['blog', 'software'],
            ],
        ],
        'entertainment' => [
            'strong_phrases' => ['salle de spectacle', 'cinéma', 'concert live', 'movie theater', 'live show', 'streaming platform'],
            'context_required' => [
                ['spectacle', 'billets'], ['concert', 'tickets'], ['cinéma', 'programme'],
                ['show', 'tickets'], ['movie', 'screening'], ['music', 'event'],
            ],
            'negative_context' => [
                ['article', 'cinéma'], ['critique', 'film'], ['film', 'review'],
            ],
        ],
        'events' => [
            'strong_phrases' => ['agenda des événements', 'inscription événement', 'event registration', 'event calendar', 'conference agenda'],
            'context_required' => [
                ['événement', 'inscription'], ['agenda', 'billetterie'], ['conférence', 'programme'],
                ['event', 'registration'], ['agenda', 'tickets'], ['conference', 'schedule'],
            ],
            'negative_context' => [
                ['article', 'événement'], ['news', 'event'], ['event', 'tracking'],
            ],
        ],
        'family_and_relationships' => [
            'strong_phrases' => ['conseil conjugal', 'médiation familiale', 'family counseling', 'relationship counseling'],
            'context_required' => [
                ['famille', 'accompagnement'], ['couple', 'thérapie'], ['parentalité', 'atelier'],
                ['family', 'support'], ['couple', 'therapy'], ['parenting', 'workshop'],
            ],
            'negative_context' => [
                ['article', 'famille'], ['family', 'business'], ['family', 'hotel'],
            ],
        ],
        'fine_art' => [
            'strong_phrases' => ['galerie d’art', 'exposition d’art', 'art gallery', 'art exhibition', 'contemporary art'],
            'context_required' => [
                ['artiste', 'exposition'], ['galerie', 'œuvres'], ['peinture', 'collection'],
                ['artist', 'exhibition'], ['gallery', 'artworks'], ['painting', 'collection'],
            ],
            'negative_context' => [
                ['martial', 'art'], ['article', 'art'], ['art', 'software'],
            ],
        ],
        'food_and_drink' => [
            'strong_phrases' => [
                'réserver une table', 'notre menu', 'restaurant gastronomique',
                'restauration rapide', 'restaurant familial', 'carte des plats',
                'plat du jour', 'formule midi', 'réservation de table',
                'book a table', 'our menu', 'online ordering',
            ],
            'context_required' => [
                ['restaurant', 'menu'], ['chef', 'réservation'], ['traiteur', 'événement'],
                ['resto', 'menu'], ['resto', 'réservation'], ['restos', 'menu'],
                ['brasserie', 'menu'], ['brasserie', 'réservation'], ['bistrot', 'menu'],
                ['bistro', 'menu'], ['carte', 'plats'],
                ['eatery', 'menu'], ['diner', 'menu'], ['bistro', 'booking'],
                ['restaurant', 'reservation'], ['chef', 'booking'], ['catering', 'event'],
                ['lokanta', 'menü'], ['restoran', 'rezervasyon'],
            ],
            'negative_context' => [
                ['fournisseur', 'restaurant'], ['grossiste', 'restaurant'], ['matériel', 'horeca'],
                ['agence', 'restaurant'], ['portfolio', 'restaurant'],
                ['restauration', 'patrimoine'], ['restauration', 'meuble'], ['restauration', 'œuvre'],
                ['restauration', 'batiment'], ['restauration', 'bâtiment'],
                ['cuisine', 'équipée'], ['cuisine', 'equipped'], ['cuisine', 'showroom'],
                ['cuisine', 'placement'], ['cuisine', 'rénovation'], ['cuisine', 'renovation'],
                ['cuisine', 'immobilière'], ['cuisine', 'immobilier'], ['cuisine', 'appartement'],
                ['matériel', 'cuisine'], ['équipement', 'cuisine'], ['ustensiles', 'cuisine'],
                ['supplier', 'restaurant'], ['wholesale', 'restaurant'], ['equipment', 'horeca'],
                ['agency', 'restaurant'], ['portfolio', 'restaurant'],
                ['kitchen', 'fitted'], ['kitchen', 'renovation'], ['kitchen', 'showroom'],
                ['kitchen', 'equipment'], ['kitchen', 'apartment'],
            ],
        ],
        'healthy_living' => [
            'strong_phrases' => ['coach sportif', 'bien-être', 'studio yoga', 'fitness center', 'wellness coaching', 'yoga studio'],
            'context_required' => [
                ['yoga', 'cours'], ['fitness', 'abonnement'], ['bien-être', 'atelier'],
                ['yoga', 'class'], ['fitness', 'membership'], ['wellness', 'coach'],
            ],
            'negative_context' => [
                ['actualité', 'santé'], ['article', 'bien-être'], ['medical', 'clinic'],
            ],
        ],
        'hobbies_and_interests' => [
            'strong_phrases' => ['club de loisirs', 'atelier créatif', 'do it yourself', 'hobby club', 'craft workshop'],
            'context_required' => [
                ['atelier', 'créatif'], ['loisir', 'club'], ['bricolage', 'atelier'],
                ['hobby', 'club'], ['craft', 'workshop'], ['diy', 'project'],
            ],
            'negative_context' => [
                ['boutique', 'loisir'], ['shop', 'hobby'], ['professional', 'workshop'],
            ],
        ],
        'home_and_garden' => [
            'strong_phrases' => [
                'cuisine équipée', 'cuisine sur mesure', 'placement de cuisine',
                'rénovation de cuisine', 'showroom cuisine', 'cuisiniste',
                'fitted kitchen', 'kitchen renovation', 'kitchen showroom',
            ],
            'context_required' => [
                ['cuisine', 'équipée'], ['cuisine', 'sur mesure'], ['cuisine', 'placement'],
                ['cuisine', 'rénovation'], ['cuisine', 'showroom'], ['cuisine', 'meubles'],
                ['kitchen', 'fitted'], ['kitchen', 'renovation'], ['kitchen', 'showroom'],
            ],
            'negative_context' => [
                ['restaurant', 'cuisine'], ['chef', 'cuisine'], ['menu', 'cuisine'],
                ['recette', 'cuisine'], ['restaurant', 'kitchen'], ['chef', 'kitchen'],
            ],
        ],
        'medical_health' => [
            'strong_phrases' => ['prendre rendez-vous', 'cabinet médical', 'medical appointment', 'health clinic'],
            'context_required' => [
                ['médecin', 'rendez-vous'], ['clinique', 'patient'], ['pharmacie', 'médicament'],
                ['doctor', 'appointment'], ['clinic', 'patient'], ['pharmacy', 'medicine'],
            ],
            'negative_context' => [
                ['commune', 'santé'], ['actualité', 'santé'], ['article', 'santé'],
                ['municipality', 'health'], ['news', 'health'], ['article', 'health'],
            ],
        ],
        'personal_celebrations_and_life_events' => [
            'strong_phrases' => ['organisation mariage', 'liste de naissance', 'wedding planner', 'baby shower', 'birthday party'],
            'context_required' => [
                ['mariage', 'organisation'], ['anniversaire', 'fête'], ['naissance', 'cadeau'],
                ['wedding', 'planning'], ['birthday', 'party'], ['baby', 'registry'],
            ],
            'negative_context' => [
                ['article', 'mariage'], ['wedding', 'news'], ['birth', 'certificate'],
            ],
        ],
        'personal_finance' => [
            'strong_phrases' => ['crédit hypothécaire', 'prêt personnel', 'assurance vie', 'personal loan', 'mortgage broker', 'life insurance'],
            'context_required' => [
                ['crédit', 'simulation'], ['prêt', 'taux'], ['assurance', 'devis'],
                ['loan', 'rate'], ['mortgage', 'quote'], ['insurance', 'quote'],
            ],
            'negative_context' => [
                ['entreprise', 'finance'], ['corporate', 'finance'], ['article', 'credit'],
            ],
        ],
        'pets' => [
            'strong_phrases' => ['clinique vétérinaire', 'animalerie', 'toilettage chien', 'veterinary clinic', 'pet shop', 'dog grooming'],
            'context_required' => [
                ['chien', 'toilettage'], ['chat', 'vétérinaire'], ['animal', 'soins'],
                ['dog', 'grooming'], ['cat', 'veterinary'], ['pet', 'care'],
            ],
            'negative_context' => [
                ['pet', 'project'], ['animal', 'article'], ['chien', 'actualité'],
            ],
        ],
        'pop_culture' => [
            'strong_phrases' => ['culture pop', 'fan club', 'comic con', 'pop culture', 'fan convention'],
            'context_required' => [
                ['fan', 'communauté'], ['comic', 'festival'], ['manga', 'convention'],
                ['fan', 'community'], ['comic', 'convention'], ['manga', 'festival'],
            ],
            'negative_context' => [
                ['population', 'culture'], ['popular', 'science'], ['culture', 'institution'],
            ],
        ],
        'real_estate' => [
            'strong_phrases' => [
                'cuisine équipée', 'appartement à vendre', 'maison à vendre',
                'estimation immobilière', 'real estate agency', 'estate agent',
                'house for sale', 'real estate listing',
            ],
            'context_required' => [
                ['immobilier', 'vente'], ['appartement', 'location'], ['propriété', 'estimation'],
                ['cuisine', 'équipée'], ['cuisine', 'appartement'], ['cuisine', 'maison'],
                ['cuisine', 'immobilier'], ['property', 'sale'], ['apartment', 'rent'],
                ['real estate', 'valuation'], ['kitchen', 'apartment'], ['kitchen', 'property'],
            ],
            'negative_context' => [
                ['article', 'immobilier'], ['property', 'management', 'software'],
            ],
        ],
        'religion_and_spirituality' => [
            'strong_phrases' => ['lieu de culte', 'paroisse', 'centre spirituel', 'place of worship', 'spiritual center'],
            'context_required' => [
                ['messe', 'horaire'], ['paroisse', 'communauté'], ['prière', 'culte'],
                ['worship', 'service'], ['church', 'community'], ['prayer', 'meeting'],
            ],
            'negative_context' => [
                ['article', 'religion'], ['history', 'church'], ['tourism', 'church'],
            ],
        ],
        'science' => [
            'strong_phrases' => ['centre de recherche', 'laboratoire de recherche', 'scientific research', 'research institute'],
            'context_required' => [
                ['recherche', 'laboratoire'], ['science', 'publication'], ['chercheur', 'projet'],
                ['research', 'laboratory'], ['science', 'publication'], ['researcher', 'project'],
            ],
            'negative_context' => [
                ['science', 'fiction'], ['article', 'science'], ['popular', 'science'],
            ],
        ],
        'shopping' => [
            'strong_phrases' => ['ajouter au panier', 'commander en ligne', 'add to cart', 'checkout'],
            'context_required' => [
                ['boutique', 'panier'], ['acheter', 'livraison'], ['shop', 'cart'], ['buy', 'delivery'],
            ],
            'negative_context' => [
                ['agence', 'boutique'], ['portfolio', 'boutique'], ['agency', 'shop'], ['portfolio', 'shop'],
            ],
        ],
        'sports' => [
            'strong_phrases' => ['club sportif', 'salle de sport', 'football club', 'sports club', 'fitness club'],
            'context_required' => [
                ['club', 'entraînement'], ['sport', 'inscription'], ['match', 'calendrier'],
                ['club', 'training'], ['sport', 'membership'], ['match', 'schedule'],
            ],
            'negative_context' => [
                ['article', 'sport'], ['sports', 'news'], ['betting', 'sports'],
            ],
        ],
        'style_and_fashion' => [
            'strong_phrases' => ['collection mode', 'prêt-à-porter', 'salon de coiffure', 'fashion collection', 'hair salon'],
            'context_required' => [
                ['mode', 'collection'], ['vêtement', 'boutique'], ['coiffure', 'rendez-vous'],
                ['fashion', 'collection'], ['clothing', 'shop'], ['hair', 'appointment'],
            ],
            'negative_context' => [
                ['style', 'css'], ['style', 'guide'], ['fashion', 'article'],
            ],
        ],
        'travel' => [
            'strong_phrases' => [
                'agence de voyage', 'réservation hôtel', 'office du tourisme',
                'travel agency', 'hotel booking', 'tourism office',
            ],
            'context_required' => [
                ['voyage', 'réservation'], ['hôtel', 'chambre'], ['tourisme', 'visite'],
                ['travel', 'booking'], ['hotel', 'room'], ['tourism', 'visit'],
            ],
            'negative_context' => [
                ['article', 'voyage'], ['travel', 'blog'], ['business', 'travel'],
                ['fret', 'transport'], ['marchandises', 'transport'], ['colis', 'livraison'],
            ],
        ],
        'video_gaming' => [
            'strong_phrases' => ['jeu vidéo', 'studio de jeux', 'gaming community', 'video game', 'game studio'],
            'context_required' => [
                ['jeu', 'vidéo'], ['gaming', 'tournoi'], ['studio', 'game'],
                ['game', 'download'], ['video', 'game'], ['esport', 'team'],
            ],
            'negative_context' => [
                ['jeu', 'concours'], ['game', 'theory'], ['video', 'marketing'],
            ],
        ],
        'government' => [
            'strong_phrases' => ['commune de', 'hôtel de ville', 'service public', 'municipality of', 'city council', 'public service'],
            'context_required' => [
                ['commune', 'administration'], ['mairie', 'horaires'], ['service', 'citoyen'],
                ['municipality', 'services'], ['city', 'council'], ['public', 'administration'],
            ],
            'negative_context' => [
                ['agence', 'public'], ['public', 'relations'], ['service', 'client'],
            ],
        ],
        'sensitive_topics' => [
            'strong_phrases' => [
                'contenu pour adultes', 'contenu sexuel explicite',
                'adult entertainment', 'explicit sexual content',
            ],
            'context_required' => [
                ['adulte', 'vidéo'], ['sexe', 'rencontre'], ['porno', 'vidéo'],
                ['adult', 'video'], ['sex', 'dating'], ['porn', 'video'],
            ],
            'negative_context' => [
                ['livre', 'sexualité'], ['auteur', 'sexualité'], ['recherche', 'sexualité'],
                ['santé sexuelle', 'prévention'], ['éducation', 'sexualité'],
                ['book', 'sexuality'], ['author', 'sexuality'], ['research', 'sexuality'],
                ['sexual health', 'prevention'], ['education', 'sexuality'],
            ],
        ],
    ];

    /**
     * Niveau 2 volontairement léger : seulement quelques sous-catégories
     * lisibles et utiles quand les signaux de la homepage sont suffisamment
     * explicites. Aucune sous-catégorie n'est forcée.
     *
     * @var array<string, array<string, array{phrases?: string[], required?: string[][]}>>
     */
    private const TIER2_RULES = [
        'attractions' => [
            'museum_gallery' => ['phrases' => ['musée', 'museum', 'art gallery', 'galerie d’art']],
            'theme_park' => ['phrases' => ['parc d’attractions', 'amusement park', 'theme park']],
            'zoo_aquarium' => ['phrases' => ['zoo', 'aquarium']],
        ],
        'automotive' => [
            'car_dealer' => ['phrases' => ['concession automobile', 'car dealer', 'car dealership']],
            'auto_repair' => ['phrases' => ['garage automobile', 'auto repair', 'vehicle service']],
            'tires_parts' => ['phrases' => ['pneus', 'pièces auto', 'tyre fitting', 'auto parts']],
        ],
        'books_and_literature' => [
            'bookstore' => ['phrases' => ['librairie', 'bookstore', 'book shop']],
            'publisher' => ['phrases' => ['maison d’édition', 'éditeur', 'book publisher']],
            'author' => ['phrases' => ['auteur', 'écrivain', 'author', 'writer']],
        ],
        'careers' => [
            'job_board' => ['phrases' => ['offres d’emploi', 'job openings', 'vacancies']],
            'recruitment_agency' => ['phrases' => ['cabinet de recrutement', 'recruitment agency', 'recrutement']],
        ],
        'education' => [
            'vocational_training' => [
                'phrases' => ['formation professionnelle', 'centre de formation', 'organisme de formation', 'vocational training', 'training center', 'mesleki eğitim', 'eğitim merkezi'],
                'required' => [['formation', 'certification'], ['training', 'certification'], ['eğitim', 'sertifika']],
            ],
            'higher_education' => [
                'phrases' => ['université', 'haute école', 'university', 'college', 'üniversite'],
            ],
            'online_learning' => [
                'phrases' => ['e-learning', 'formation en ligne', 'online course', 'distance learning', 'online eğitim', 'uzaktan eğitim'],
            ],
        ],
        'entertainment' => [
            'cinema' => ['phrases' => ['cinéma', 'movie theater', 'screening']],
            'live_music' => ['phrases' => ['concert', 'live music', 'salle de concert']],
            'performing_arts' => ['phrases' => ['salle de spectacle', 'theater', 'théâtre', 'live show']],
        ],
        'events' => [
            'conference' => ['phrases' => ['conférence', 'conference agenda', 'summit']],
            'festival' => ['phrases' => ['festival', 'billetterie festival']],
            'event_registration' => ['phrases' => ['inscription événement', 'event registration']],
        ],
        'family_and_relationships' => [
            'parenting' => ['phrases' => ['parentalité', 'parenting workshop']],
            'couple_family_counseling' => ['phrases' => ['conseil conjugal', 'médiation familiale', 'family counseling', 'relationship counseling']],
        ],
        'fine_art' => [
            'art_gallery' => ['phrases' => ['galerie d’art', 'art gallery']],
            'art_exhibition' => ['phrases' => ['exposition d’art', 'art exhibition']],
            'artist_portfolio' => ['phrases' => ['portfolio artiste', 'artist portfolio']],
        ],
        'food_and_drink' => [
            'restaurant' => [
                'phrases' => [
                    'réserver une table', 'notre menu', 'restaurant gastronomique',
                    'restauration rapide', 'restaurant familial', 'carte des plats',
                    'plat du jour', 'formule midi', 'réservation de table',
                    'book a table', 'our menu', 'restoran menüsü',
                ],
                'required' => [
                    ['restaurant', 'menu'], ['restaurant', 'reservation'],
                    ['resto', 'menu'], ['resto', 'réservation'], ['restos', 'menu'],
                    ['brasserie', 'menu'], ['brasserie', 'réservation'],
                    ['bistrot', 'menu'], ['bistro', 'menu'], ['carte', 'plats'],
                    ['eatery', 'menu'], ['diner', 'menu'], ['bistro', 'booking'],
                    ['restoran', 'menü'], ['lokanta', 'menü'], ['restoran', 'rezervasyon'],
                ],
            ],
            'catering' => [
                'phrases' => ['service traiteur', 'catering service', 'event catering', 'catering hizmeti'],
                'required' => [['traiteur', 'événement'], ['catering', 'event']],
            ],
            'food_truck' => [
                'phrases' => ['food truck', 'foodtruck', 'food-truck'],
            ],
            'grocery' => [
                'phrases' => ['épicerie', 'supermarché', 'grocery store', 'supermarket', 'market', 'bakkal', 'süpermarket'],
            ],
        ],
        'healthy_living' => [
            'fitness' => ['phrases' => ['salle de sport', 'fitness center', 'fitness membership']],
            'yoga_wellness' => ['phrases' => ['studio yoga', 'yoga studio', 'bien-être', 'wellness coaching']],
            'coaching' => ['phrases' => ['coach sportif', 'personal trainer', 'wellness coach']],
        ],
        'hobbies_and_interests' => [
            'crafts_diy' => ['phrases' => ['atelier créatif', 'bricolage', 'craft workshop', 'do it yourself']],
            'hobby_club' => ['phrases' => ['club de loisirs', 'hobby club']],
        ],
        'home_and_garden' => [
            'kitchen_design' => ['phrases' => ['cuisine équipée', 'cuisine sur mesure', 'cuisiniste', 'fitted kitchen']],
            'renovation' => ['phrases' => ['rénovation de cuisine', 'kitchen renovation', 'home renovation']],
            'garden' => ['phrases' => ['jardinage', 'garden center', 'landscaping']],
        ],
        'technology_and_computing' => [
            'cybersecurity' => [
                'phrases' => ['cybersécurité', 'cybersecurity', 'security audit', 'penetration testing', 'siber güvenlik'],
            ],
            'software_development' => [
                'phrases' => ['développement logiciel', 'développement web', 'software development', 'web development', 'yazılım geliştirme'],
            ],
            'hosting_cloud' => [
                'phrases' => ['hébergement web', 'cloud computing', 'web hosting', 'hosting provider', 'bulut bilişim'],
            ],
            'artificial_intelligence' => [
                'phrases' => ['intelligence artificielle', 'artificial intelligence', 'machine learning', 'yapay zeka'],
            ],
        ],
        'business_and_finance' => [
            'consulting' => [
                'phrases' => ['cabinet de conseil', 'consulting', 'consultancy', 'danışmanlık'],
                'required' => [['conseil', 'entreprise'], ['consulting', 'business']],
            ],
            'manufacturing' => [
                'phrases' => ['fabricant', 'manufacturing', 'manufacturer', 'üretici', 'imalat'],
            ],
            'wholesale' => [
                'phrases' => ['grossiste', 'wholesale', 'supplier', 'toptan'],
            ],
        ],
        'transport_and_logistics' => [
            'passenger_rail' => [
                'phrases' => ['billets de train', 'train tickets', 'transport ferroviaire', 'rail transport', 'horaires en gare'],
                'required' => [['train', 'billet'], ['train', 'gare'], ['train', 'tickets']],
            ],
            'passenger_air' => [
                'phrases' => ['compagnie aérienne', 'aéroport', 'airline', 'airport', 'flight'],
                'required' => [['aéroport', 'vol'], ['airport', 'flight']],
            ],
            'passenger_bus_taxi' => [
                'phrases' => ['transport en commun', 'voyages en bus', 'autocar', 'taxi', 'vtc', 'public transport'],
                'required' => [['bus', 'voyage'], ['public', 'transport']],
            ],
            'maritime_transport' => [
                'phrases' => ['transport maritime', 'ferry', 'port', 'shipping'],
                'required' => [['bateau', 'port'], ['ferry', 'port']],
            ],
            'freight_logistics' => [
                'phrases' => ['fret', 'transport de marchandises', 'logistique', 'supply chain', 'parcel delivery', 'freight logistics'],
                'required' => [['fret', 'transport'], ['marchandises', 'transport'], ['colis', 'livraison'], ['freight', 'shipping']],
            ],
        ],
        'personal_celebrations_and_life_events' => [
            'wedding' => ['phrases' => ['organisation mariage', 'wedding planner', 'wedding planning']],
            'birthday_party' => ['phrases' => ['anniversaire', 'birthday party']],
            'birth_baby' => ['phrases' => ['liste de naissance', 'baby registry', 'baby shower']],
        ],
        'personal_finance' => [
            'mortgage' => ['phrases' => ['crédit hypothécaire', 'mortgage broker', 'mortgage quote']],
            'loans' => ['phrases' => ['prêt personnel', 'personal loan']],
            'insurance' => ['phrases' => ['assurance vie', 'life insurance', 'insurance quote']],
        ],
        'pets' => [
            'veterinary' => ['phrases' => ['clinique vétérinaire', 'veterinary clinic', 'vet clinic']],
            'pet_store' => ['phrases' => ['animalerie', 'pet shop', 'pet store']],
            'grooming' => ['phrases' => ['toilettage chien', 'dog grooming']],
        ],
        'pop_culture' => [
            'fan_community' => ['phrases' => ['fan club', 'fan community']],
            'comics_manga' => ['phrases' => ['comic con', 'manga', 'comic convention']],
        ],
        'government' => [
            'municipality' => [
                'phrases' => ['commune', 'mairie', 'hôtel de ville', 'municipality', 'city council', 'town hall', 'belediye'],
            ],
            'ministry' => [
                'phrases' => ['ministère', 'ministry', 'bakanlık'],
            ],
            'embassy_consulate' => [
                'phrases' => ['ambassade', 'consulat', 'embassy', 'consulate', 'büyükelçilik', 'konsolosluk'],
            ],
        ],
        'medical_health' => [
            'clinic' => [
                'phrases' => ['cabinet médical', 'clinique', 'medical clinic', 'health clinic', 'klinik'],
            ],
            'pharmacy' => [
                'phrases' => ['pharmacie', 'pharmacy', 'eczane'],
            ],
            'dentistry' => [
                'phrases' => ['dentiste', 'cabinet dentaire', 'dentist', 'dental clinic', 'diş hekimi'],
            ],
        ],
        'religion_and_spirituality' => [
            'place_of_worship' => ['phrases' => ['lieu de culte', 'place of worship', 'church', 'mosque', 'synagogue']],
            'spiritual_center' => ['phrases' => ['centre spirituel', 'spiritual center']],
        ],
        'science' => [
            'research_institute' => ['phrases' => ['centre de recherche', 'research institute', 'laboratoire de recherche']],
            'laboratory' => ['phrases' => ['laboratoire', 'laboratory']],
        ],
        'travel' => [
            'hotel' => [
                'phrases' => ['hôtel', 'hotel', 'accommodation', 'konaklama', 'otel'],
            ],
            'travel_agency' => [
                'phrases' => ['agence de voyage', 'travel agency', 'tour operator', 'seyahat acentesi'],
            ],
            'tourism' => [
                'phrases' => ['office du tourisme', 'tourism office', 'tourist information', 'turizm'],
            ],
        ],
        'shopping' => [
            'ecommerce' => [
                'phrases' => ['ajouter au panier', 'commander en ligne', 'add to cart', 'checkout', 'online store', 'sepete ekle'],
            ],
            'retail_store' => [
                'phrases' => ['boutique', 'magasin', 'retail store', 'shop', 'mağaza'],
            ],
        ],
        'sports' => [
            'sports_club' => ['phrases' => ['club sportif', 'sports club', 'football club']],
            'fitness_sport' => ['phrases' => ['salle de sport', 'fitness club']],
            'esports' => ['phrases' => ['esport', 'esports team']],
        ],
        'style_and_fashion' => [
            'fashion_retail' => ['phrases' => ['collection mode', 'prêt-à-porter', 'fashion collection']],
            'hair_beauty' => ['phrases' => ['salon de coiffure', 'hair salon', 'coiffure']],
            'clothing' => ['phrases' => ['vêtement', 'clothing shop']],
        ],
        'real_estate' => [
            'real_estate_agency' => [
                'phrases' => ['agence immobilière', 'real estate agency', 'estate agent', 'emlak ofisi'],
            ],
            'property_rental' => [
                'phrases' => ['appartement à louer', 'location immobilière', 'property rental', 'kiralık daire'],
            ],
            'property_sale' => [
                'phrases' => ['appartement à vendre', 'maison à vendre', 'house for sale', 'property sale'],
            ],
        ],
        'video_gaming' => [
            'game_studio' => ['phrases' => ['studio de jeux', 'game studio']],
            'gaming_community' => ['phrases' => ['gaming community', 'gaming', 'esport']],
            'video_game' => ['phrases' => ['jeu vidéo', 'video game']],
        ],
        'sensitive_topics' => [
            'adult_explicit' => [
                'phrases' => ['contenu sexuel explicite', 'contenu pour adultes', 'explicit sexual content', 'adult entertainment', 'yetişkin içerik'],
            ],
        ],
    ];
}
