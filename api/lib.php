<?php

declare(strict_types=1);

/**
 * Helpers partagés par scan-queue.php, scan-ingest.php et refresh-domains.php.
 * Pas de framework, pas de dépendance — cohérent avec l'hébergement mutualisé
 * visé (Infomaniak) et la logique "aussi simple que possible pour l'instant".
 */

const DOMAINS_FILE = __DIR__ . '/../storage/domains.json';
const TOKEN_FILE = __DIR__ . '/../storage/secrets/api-token.txt';

/**
 * Vérifie le jeton envoyé dans l'en-tête Authorization: Bearer <jeton>.
 * Répond 401 et termine l'exécution si absent ou invalide.
 */
function requireValidToken(): void
{
    $expected = @file_get_contents(TOKEN_FILE);
    if ($expected === false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'server_misconfigured', 'message' => 'Jeton API non configuré côté serveur.']);
        exit;
    }
    $expected = trim($expected);

    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $provided = str_starts_with($authHeader, 'Bearer ') ? substr($authHeader, 7) : '';

    // Comparaison à temps constant — évite qu'un attaquant devine le jeton
    // caractère par caractère via des différences de temps de réponse.
    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
}

/**
 * Charge domains.json. Renvoie un tableau vide si le fichier n'existe pas
 * encore ou est vide — un déploiement neuf ne doit jamais planter dessus.
 *
 * @return array<string, array{last_scanned_at: ?string, last_result: ?array}>
 */
function loadDomains(): array
{
    if (!is_file(DOMAINS_FILE)) {
        return [];
    }
    $raw = file_get_contents(DOMAINS_FILE);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, associative: true);
    return is_array($data) ? $data : [];
}

/**
 * Écrit domains.json de façon atomique : écrit dans un fichier temporaire
 * puis renomme — un rename() est atomique sur un même système de fichiers,
 * donc aucun lecteur concurrent ne peut jamais voir un fichier à moitié écrit.
 *
 * @param array<string, array{last_scanned_at: ?string, last_result: ?array}> $domains
 */
function saveDomains(array $domains): void
{
    $dir = dirname(DOMAINS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, recursive: true);
    }

    $json = json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new \RuntimeException('Échec de sérialisation JSON de domains.json.');
    }

    $tmpFile = DOMAINS_FILE . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmpFile, $json, LOCK_EX) === false) {
        throw new \RuntimeException('Échec d\'écriture du fichier temporaire.');
    }
    if (!rename($tmpFile, DOMAINS_FILE)) {
        @unlink($tmpFile);
        throw new \RuntimeException('Échec du remplacement atomique de domains.json.');
    }
}

/**
 * Verrou applicatif simple : empêche deux exécutions concurrentes de
 * scan-queue.php ou refresh-domains.php de se marcher dessus (utile si un
 * run traîne et que le suivant démarre avant sa fin). Le verrou expire tout
 * seul après STALE_LOCK_SECONDS, pour ne jamais bloquer indéfiniment si un
 * run précédent a planté sans le libérer proprement.
 */
const STALE_LOCK_SECONDS = 120;

function acquireLock(string $lockFile): bool
{
    $dir = dirname($lockFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, recursive: true);
    }

    if (is_file($lockFile)) {
        $age = time() - (int) filemtime($lockFile);
        if ($age < STALE_LOCK_SECONDS) {
            return false;
        }
        // Verrou périmé — probablement un run précédent qui a planté.
        @unlink($lockFile);
    }

    $handle = @fopen($lockFile, 'x');
    if ($handle === false) {
        return false; // créé entre-temps par un autre process (rare, mais possible)
    }
    fclose($handle);
    return true;
}

function releaseLock(string $lockFile): void
{
    @unlink($lockFile);
}
