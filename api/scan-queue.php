<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Content-Type: application/json');

requireValidToken();

$limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 50;

$domains = loadDomains();

// Tri : jamais scanné (null) en premier, puis du plus ancien au plus récent.
uasort($domains, function (array $a, array $b): int {
    $aTime = $a['last_scanned_at'] ?? null;
    $bTime = $b['last_scanned_at'] ?? null;

    if ($aTime === null && $bTime === null) {
        return 0;
    }
    if ($aTime === null) {
        return -1;
    }
    if ($bTime === null) {
        return 1;
    }

    return strcmp($aTime, $bTime);
});

$selected = array_slice(array_keys($domains), 0, $limit);

echo json_encode(['domains' => $selected], JSON_UNESCAPED_SLASHES);
