<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Content-Type: application/json');

requireValidToken();

$rawBody = file_get_contents('php://input');
$payload = json_decode((string) $rawBody, associative: true);

if (!is_array($payload) || !isset($payload['results']) || !is_array($payload['results'])) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request', 'message' => 'Champ "results" manquant ou invalide.']);
    exit;
}

$lockFile = __DIR__ . '/../storage/locks/scan-ingest.lock';
if (!acquireLock($lockFile)) {
    // Un autre appel scan-ingest est en cours — plutôt que d'écraser son
    // écriture, on demande au client de réessayer. Rare en pratique (un
    // seul cron horaire), mais évite une corruption silencieuse si jamais
    // deux runs se chevauchent.
    http_response_code(409);
    echo json_encode(['error' => 'busy', 'message' => 'Un autre envoi est en cours, réessayez dans quelques secondes.']);
    exit;
}

try {
    $domains = loadDomains();
    $received = 0;
    $unknown = [];

    foreach ($payload['results'] as $result) {
        if (!is_array($result) || !isset($result['domain']) || !is_string($result['domain'])) {
            continue;
        }
        $domain = $result['domain'];

        if (!isset($domains[$domain])) {
            // Domaine inconnu de notre liste (ne devrait pas arriver en usage
            // normal, mais on l'accueille plutôt que de le rejeter — mieux
            // vaut une donnée en trop qu'un résultat de scan perdu).
            $unknown[] = $domain;
            $domains[$domain] = ['last_scanned_at' => null, 'last_result' => null];
        }

        $domains[$domain]['last_scanned_at'] = $result['scanned_at'] ?? gmdate('c');
        $domains[$domain]['last_result'] = $result;
        $received++;
    }

    saveDomains($domains);
} finally {
    releaseLock($lockFile);
}

echo json_encode(['received' => $received, 'unknown_domains' => $unknown], JSON_UNESCAPED_SLASHES);
