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

/**
 * Nombre de domaines demandés par run. Contrairement à Infomaniak (limité à
 * 1x/heure côté cron Common Crawl, contrainte qui avait motivé un chiffre
 * prudent au départ), GitHub Actions n'a pas cette restriction — la vraie
 * limite est le temps d'exécution du job (15 min, voir
 * .github/workflows/scan.yml).
 *
 * Débit : le cron est passé de 1x/heure à 1x/15min, et
 * Http::MAX_CONCURRENT_DOMAINS de 4 à 12 (voir Http.php) — les deux
 * ensemble doivent déjà réduire sensiblement le temps par run. BATCH_SIZE
 * n'a volontairement pas été touché dans ce changement : à augmenter dans un
 * second temps, une fois confirmé par plusieurs runs réels que la marge sous
 * les 15 minutes le permet (observer la durée totale dans les logs GitHub
 * Actions après le changement de concurrence ci-dessus, avant de toucher à
 * ce chiffre).
 */
const BATCH_SIZE = 800; //300

function fail(string $message): never
{
    fwrite(STDERR, "[erreur] {$message}\n");
    exit(1);
}

$apiUrl = getenv('OGPN_API_URL');
$apiToken = getenv('OGPN_API_TOKEN');
$priorityTlds = getenv('OGPN_PRIORITY_TLDS'); // optionnel, ex. "fr,be,lu,ch,mc" — voir scan.yml pour comment l'activer/désactiver

if ($apiUrl === false || $apiUrl === '') {
    fail('Variable d\'environnement OGPN_API_URL manquante.');
}
if ($apiToken === false || $apiToken === '') {
    fail('Variable d\'environnement OGPN_API_TOKEN manquante.');
}

$http = new Http();
$queue = new Queue($http, $apiUrl, $apiToken);
$scanner = new Scanner($http);

echo "Demande d'un lot de " . BATCH_SIZE . " domaines" . ($priorityTlds !== false && $priorityTlds !== '' ? " (priorité : {$priorityTlds})" : '') . "...\n";

try {
    $domains = $queue->fetchNextBatch(BATCH_SIZE, $priorityTlds !== false ? $priorityTlds : null);
} catch (\RuntimeException $e) {
    fail("Échec de la récupération du lot : {$e->getMessage()}");
}

if ($domains === []) {
    echo "Aucun domaine dû pour ce run. Fin.\n";
    exit(0);
}

echo 'Lot reçu : ' . count($domains) . " domaine(s).\n";

$startedAt = microtime(true);
$results = $scanner->scanBatch($domains);
$elapsed = round(microtime(true) - $startedAt, 1);

$blocked = 0;
$unreachable = 0;
$scanned = 0;
foreach ($results as $result) {
    if ($result->robotsStatus === 'unreachable') {
        $unreachable++;
    } elseif ($result->robotsBlocksEverything) {
        $blocked++;
    } else {
        $scanned++;
    }
}

echo "Scan terminé en {$elapsed}s — {$scanned} scanné(s) en entier, {$blocked} bloqué(s) par robots.txt, {$unreachable} injoignable(s).\n";

try {
    $queue->submitResults($results);
} catch (\RuntimeException $e) {
    fail("Échec de l'envoi des résultats : {$e->getMessage()}");
}

echo "Résultats envoyés avec succès.\n";
exit(0);
