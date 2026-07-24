<?php

declare(strict_types=1);

namespace OgpnBot;

/**
 * Validation de format de domaine — filtre gratuit (aucun appel réseau) avant
 * de contacter quoi que ce soit. Rejette les cas les plus évidents (IP
 * littérale, localhost, TLD internes) qui pourraient sinon faire pointer le
 * bot vers une ressource interne au réseau GitHub Actions/Infomaniak.
 *
 * Ne protège PAS contre un DNS rebinding sophistiqué (domaine légitime au
 * moment de cette vérification, repointé vers une IP interne au moment de
 * la connexion réelle) — cette protection plus poussée nécessiterait une
 * résolution DNS préalable, un appel synchrone sans timeout garanti qu'on a
 * délibérément écarté du scan pour les mêmes raisons que la résolution
 * MX/TXT (voir Scanner.php).
 */
final class DomainSafety
{
    public static function isPlausibleDomain(string $domain): bool
    {
        // Rejette une IP littérale (v4 ou v6) utilisée directement comme domaine.
        if (filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        // Format hostname basique : labels alphanumériques/tirets séparés par
        // des points, pas de tiret en début/fin de label.
        if (preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$/', $domain) !== 1) {
            return false;
        }

        $lower = strtolower($domain);
        $blockedSuffixes = ['.local', '.internal', '.localdomain', '.test', '.invalid', '.example'];
        foreach ($blockedSuffixes as $suffix) {
            if ($lower === 'localhost' || str_ends_with($lower, $suffix)) {
                return false;
            }
        }

        return true;
    }
}
