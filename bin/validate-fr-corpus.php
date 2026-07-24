<?php

declare(strict_types=1);

namespace OgpnBot;

spl_autoload_register(function (string $class): void {
    $prefix = 'OgpnBot\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$corpusPath = $argv[1] ?? (__DIR__ . '/../validation/fr-category-corpus.json');
$content = is_file($corpusPath) ? file_get_contents($corpusPath) : false;
if ($content === false) {
    fwrite(STDERR, "Corpus introuvable: {$corpusPath}\n");
    exit(1);
}

$corpus = json_decode($content, associative: true);
if (!is_array($corpus) || !isset($corpus['entries']) || !is_array($corpus['entries'])) {
    fwrite(STDERR, "Corpus invalide: {$corpusPath}\n");
    exit(1);
}

$entries = [];
$domains = [];
foreach ($corpus['entries'] as $entry) {
    if (!is_array($entry) || !isset($entry['domain'], $entry['expected_category'])) {
        continue;
    }
    $domain = strtolower(trim((string) $entry['domain']));
    if ($domain === '') {
        continue;
    }
    $entries[$domain][] = $entry;
    $domains[] = $domain;
}
$domains = array_values(array_unique($domains));

$scanner = new Scanner(new Http());
$results = $scanner->scanBatch($domains);

$ok = 0;
$categoryMismatch = 0;
$tier2Mismatch = 0;
$unreachable = 0;

echo "domain,expected_category,actual_category,expected_tier2,actual_tier2,confidence,tier2_confidence,status,analysis_url,analysis_source,category_status,category_source,default_language,alternate_languages,signals\n";

foreach ($entries as $domain => $domainEntries) {
    $result = $results[$domain] ?? null;
    foreach ($domainEntries as $entry) {
        $expectedCategory = (string) $entry['expected_category'];
        $expectedTier2 = isset($entry['expected_tier2']) ? (string) $entry['expected_tier2'] : '';

        if (!$result instanceof ScanResult || $result->robotsStatus === 'unreachable' || $result->error !== null) {
            $unreachable++;
            echo csvRow([
                $domain,
                $expectedCategory,
                $result?->category ?? 'unreachable',
                $expectedTier2,
                $result?->categoryTier2 ?? '',
                (string) ($result?->categoryConfidence ?? 0),
                (string) ($result?->categoryTier2Confidence ?? 0),
                $result?->robotsStatus ?? 'missing_result',
                $result?->analysisUrl ?? '',
                $result?->analysisSource ?? 'missing',
                $result?->categoryStatus ?? 'unidentified',
                $result?->categorySource ?? 'homepage_insufficient_signals',
                $result?->defaultLanguage ?? '',
                implode('|', $result?->alternateLanguages ?? []),
                $result?->error ?? '',
            ]);
            continue;
        }

        $categoryMatches = $result->category === $expectedCategory;
        $tier2Matches = $expectedTier2 === '' || $result->categoryTier2 === $expectedTier2;

        if ($categoryMatches && $tier2Matches) {
            $ok++;
        } elseif (!$categoryMatches) {
            $categoryMismatch++;
        } else {
            $tier2Mismatch++;
        }

        echo csvRow([
            $domain,
            $expectedCategory,
            $result->category,
            $expectedTier2,
            $result->categoryTier2 ?? '',
            (string) $result->categoryConfidence,
            (string) $result->categoryTier2Confidence,
            $categoryMatches && $tier2Matches ? 'ok' : ($categoryMatches ? 'tier2_mismatch' : 'category_mismatch'),
            $result->analysisUrl ?? '',
            $result->analysisSource,
            $result->categoryStatus,
            $result->categorySource,
            $result->defaultLanguage ?? '',
            implode('|', $result->alternateLanguages),
            implode('|', $result->categorySignals),
        ]);
    }
}

fwrite(STDERR, "OK={$ok}; category_mismatch={$categoryMismatch}; tier2_mismatch={$tier2Mismatch}; unreachable_or_error={$unreachable}\n");
exit(($categoryMismatch === 0 && $tier2Mismatch === 0) ? 0 : 2);

/** @param string[] $columns */
function csvRow(array $columns): string
{
    $escaped = array_map(
        static fn (string $value): string => '"' . str_replace('"', '""', $value) . '"',
        $columns,
    );

    return implode(',', $escaped) . "\n";
}
