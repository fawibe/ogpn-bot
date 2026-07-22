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

/** Nombre de domaines demandés par run — volontairement prudent, voir notes de conception du projet. */
const BATCH_SIZE = 75;

function fail(string $message): never
{
    fwrite(STDERR, "[erreur] {$message}\n");
    exit(1);
}

$apiUrl = getenv('OGPN_API_URL');
$apiToken = getenv('OGPN_API_TOKEN');

if ($apiUrl === false || $apiUrl === '') {
    fail('Variable d\'environnement OGPN_API_URL manquante.');
}
if ($apiToken === false || $apiToken === '') {
    fail('Variable d\'environnement OGPN_API_TOKEN manquante.');
}

$http = new Http();
$queue = new Queue($http, $apiUrl, $apiToken);
$scanner = new Scanner($http);

echo "Demande d'un lot de " . BATCH_SIZE . " domaines...\n";

try {
    $domains = $queue->fetchNextBatch(BATCH_SIZE);
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
