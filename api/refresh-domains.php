<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/CommonCrawlIndex.php';

use OgpnBot\Http;

/**
 * TLD européens visés — ccTLD réels + gTLD villes/régions pertinents.
 * Liste volontairement gérée ici plutôt que dans Config.php (côté src/),
 * puisqu'elle concerne l'alimentation serveur, pas le comportement du bot.
 */
const TARGET_TLDS = [
    // ccTLD — Union européenne + Espace économique européen + voisins directs
    'be', 'fr', 'de', 'nl', 'lu', 'at', 'ch', 'it', 'es', 'pt',
    'ie', 'dk', 'se', 'fi', 'no', 'is', 'pl', 'cz', 'sk', 'hu',
    'ro', 'bg', 'gr', 'hr', 'si', 'ee', 'lv', 'lt', 'mt', 'cy',
    'gb', 'li', 'eu',
    // gTLD villes/régions européens (cf. Config::COUNTRY_BY_SPECIAL_TLD côté bot)
    'brussels', 'vlaanderen', 'wien', 'tirol', 'berlin', 'hamburg', 'koeln',
    'bayern', 'ruhr', 'saarland', 'nrw', 'paris', 'bzh', 'alsace', 'corsica',
    'scot', 'london', 'wales', 'cymru', 'gal', 'cat', 'eus', 'frl',
    'amsterdam', 'rotterdam', 'zuerich',
];

const STATE_FILE = __DIR__ . '/../storage/refresh-state.json';
const MAX_TLDS_PER_RUN = 2;

/** @return array{crawl_id: string, current_tld_index: int, tld_progress: array<string, int>} */
function loadRefreshState(): array
{
    if (!is_file(STATE_FILE)) {
        return ['crawl_id' => CommonCrawlIndex::DEFAULT_CRAWL_ID, 'current_tld_index' => 0, 'tld_progress' => []];
    }
    $raw = @file_get_contents(STATE_FILE);
    $data = $raw !== false ? json_decode($raw, associative: true) : null;
    if (!is_array($data)) {
        return ['crawl_id' => CommonCrawlIndex::DEFAULT_CRAWL_ID, 'current_tld_index' => 0, 'tld_progress' => []];
    }

    return [
        'crawl_id' => $data['crawl_id'] ?? CommonCrawlIndex::DEFAULT_CRAWL_ID,
        'current_tld_index' => (int) ($data['current_tld_index'] ?? 0),
        'tld_progress' => is_array($data['tld_progress'] ?? null) ? $data['tld_progress'] : [],
    ];
}

function saveRefreshState(array $state): void
{
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Fusionne les domaines nouvellement découverts dans domains.json — n'écrase
 * jamais une entrée existante (on perdrait last_scanned_at/last_result).
 *
 * @param string[] $newDomains
 * @return int Nombre de domaines effectivement nouveaux.
 */
function mergeNewDomains(array $newDomains): int
{
    $domains = loadDomains();
    $added = 0;

    foreach ($newDomains as $domain) {
        if (!isset($domains[$domain])) {
            $domains[$domain] = ['last_scanned_at' => null, 'last_result' => null];
            $added++;
        }
    }

    if ($added > 0) {
        saveDomains($domains);
    }

    return $added;
}

// --- Point d'entrée ---

$lockFile = __DIR__ . '/../storage/locks/refresh-domains.lock';
if (!acquireLock($lockFile)) {
    echo "Un autre run de refresh-domains est déjà en cours (ou verrou périmé pas encore expiré). Fin.\n";
    exit(0);
}

try {
    $state = loadRefreshState();
    $http = new Http();
    $index = new CommonCrawlIndex($http);

    $processedThisRun = 0;
    $totalNewDomains = 0;

    while ($processedThisRun < MAX_TLDS_PER_RUN && $state['current_tld_index'] < count(TARGET_TLDS)) {
        $tld = TARGET_TLDS[$state['current_tld_index']];
        $resumePage = $state['tld_progress'][$tld] ?? 0;

        try {
            $result = $index->domainsForTld($state['crawl_id'], $tld, $resumePage);
        } catch (\RuntimeException $e) {
            // On n'interrompt pas tout le run pour un TLD en erreur (API
            // temporairement indisponible, etc.) — on log et on réessaiera
            // ce même TLD/page au prochain déclenchement, sans avancer l'état.
            fwrite(STDERR, "[avertissement] .{$tld} page {$resumePage} : {$e->getMessage()}\n");
            break;
        }

        $added = mergeNewDomains($result['domains']);
        $totalNewDomains += $added;
        echo ".{$tld} page {$resumePage} : " . count($result['domains']) . " domaine(s) trouvé(s), {$added} nouveau(x).\n";

        if ($result['nextPage'] === null) {
            echo ".{$tld} terminé.\n";
            unset($state['tld_progress'][$tld]);
            $state['current_tld_index']++;
            $processedThisRun++;
        } else {
            $state['tld_progress'][$tld] = $result['nextPage'];
            break; // on ne passe pas au TLD suivant tant que celui-ci n'est pas fini
        }
    }

    if ($state['current_tld_index'] >= count(TARGET_TLDS)) {
        echo "Cycle complet sur les " . count(TARGET_TLDS) . " TLD — {$totalNewDomains} nouveau(x) domaine(s) au total sur ce run.\n";
        @unlink(STATE_FILE); // prêt pour un nouveau cycle au prochain déclenchement
    } else {
        saveRefreshState($state);
        echo "Progression sauvegardée : TLD #{$state['current_tld_index']}/" . count(TARGET_TLDS) . ".\n";
    }
} finally {
    releaseLock($lockFile);
}
