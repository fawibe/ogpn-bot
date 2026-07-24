<?php

declare(strict_types=1);

namespace OgpnBot;

final class TldRegistry
{
    /**
     * Registre volontairement minimal côté scanner : il décrit les tags utiles
     * aux résultats. La liste exhaustive active_scan est maintenue côté
     * Infomaniak/api/TldRegistry.php, car elle pilote Common Crawl.
     */
    private const PROFILES = [
        'eu' => ['type' => 'euTLD', 'country_code' => 'EU', 'groups' => ['EU_TLD', 'EU', 'EEE']],
        'xn--e1a4c' => ['type' => 'euIDN', 'country_code' => 'EU', 'groups' => ['EU_TLD', 'EU', 'EEE']],
        'xn--qxa6a' => ['type' => 'euIDN', 'country_code' => 'EU', 'groups' => ['EU_TLD', 'EU', 'EEE']],
        'uk' => ['type' => 'ccTLD', 'country_code' => 'GB', 'groups' => ['COE', 'UK_GROUP']],
        'gb' => ['type' => 'reserved', 'country_code' => 'GB', 'groups' => ['RESERVED', 'UK_GROUP']],
        'no' => ['type' => 'ccTLD', 'country_code' => 'NO', 'groups' => ['EEE', 'AELE', 'COE', 'SCHENGEN_ASSOCIATED']],
        'is' => ['type' => 'ccTLD', 'country_code' => 'IS', 'groups' => ['EEE', 'AELE', 'COE', 'SCHENGEN_ASSOCIATED']],
        'li' => ['type' => 'ccTLD', 'country_code' => 'LI', 'groups' => ['EEE', 'AELE', 'COE', 'SCHENGEN_ASSOCIATED']],
        'ch' => ['type' => 'ccTLD', 'country_code' => 'CH', 'groups' => ['AELE', 'COE', 'SCHENGEN_ASSOCIATED']],
        'ad' => ['type' => 'ccTLD', 'country_code' => 'AD', 'groups' => ['COE', 'MICROSTATE']],
        'mc' => ['type' => 'ccTLD', 'country_code' => 'MC', 'groups' => ['COE', 'MICROSTATE']],
        'sm' => ['type' => 'ccTLD', 'country_code' => 'SM', 'groups' => ['COE', 'MICROSTATE']],
        'va' => ['type' => 'ccTLD', 'country_code' => 'VA', 'groups' => ['MICROSTATE']],
        // Candidats UE officiels: AL, BA, GE, MD, ME, MK, RS, TR, UA. Kosovo reste absent:
        // candidat potentiel, pas candidat officiel, et pas membre du Conseil de l'Europe.
        'al' => ['type' => 'ccTLD', 'country_code' => 'AL', 'groups' => ['COE', 'EU_CANDIDATE', 'EXTENDED_POLITICAL_EUROPE']],
        'am' => ['type' => 'ccTLD', 'country_code' => 'AM', 'groups' => ['COE', 'EXTENDED_POLITICAL_EUROPE']],
        'az' => ['type' => 'ccTLD', 'country_code' => 'AZ', 'groups' => ['COE', 'EXTENDED_POLITICAL_EUROPE']],
        'ba' => ['type' => 'ccTLD', 'country_code' => 'BA', 'groups' => ['COE', 'EU_CANDIDATE', 'EXTENDED_POLITICAL_EUROPE']],
        'ge' => ['type' => 'ccTLD', 'country_code' => 'GE', 'groups' => ['COE', 'EU_CANDIDATE', 'EXTENDED_POLITICAL_EUROPE']],
        'md' => ['type' => 'ccTLD', 'country_code' => 'MD', 'groups' => ['COE', 'EU_CANDIDATE', 'EXTENDED_POLITICAL_EUROPE']],
        'me' => ['type' => 'ccTLD', 'country_code' => 'ME', 'groups' => ['COE', 'EU_CANDIDATE', 'EXTENDED_POLITICAL_EUROPE']],
        'mk' => ['type' => 'ccTLD', 'country_code' => 'MK', 'groups' => ['COE', 'EU_CANDIDATE', 'EXTENDED_POLITICAL_EUROPE']],
        'rs' => ['type' => 'ccTLD', 'country_code' => 'RS', 'groups' => ['COE', 'EU_CANDIDATE', 'EXTENDED_POLITICAL_EUROPE']],
        'tr' => ['type' => 'ccTLD', 'country_code' => 'TR', 'groups' => ['COE', 'EU_CANDIDATE', 'EXTENDED_POLITICAL_EUROPE']],
        'ua' => ['type' => 'ccTLD', 'country_code' => 'UA', 'groups' => ['COE', 'EU_CANDIDATE', 'EXTENDED_POLITICAL_EUROPE']],
    ];

    /** @return array{tld: ?string, type: ?string, country_code: ?string, groups: string[]} */
    public static function profileForDomain(string $domain): array
    {
        $tld = self::extractTld($domain);
        if ($tld === null) {
            return ['tld' => null, 'type' => null, 'country_code' => null, 'groups' => []];
        }

        if (isset(self::PROFILES[$tld])) {
            return ['tld' => $tld] + self::PROFILES[$tld];
        }

        if (isset(Config::COUNTRY_BY_SPECIAL_TLD[$tld])) {
            $countryCode = Config::COUNTRY_BY_SPECIAL_TLD[$tld];
            $groups = ['GEO_TLD'];
            if (Config::isEuMember($countryCode)) {
                $groups[] = 'EU';
                $groups[] = 'EEE';
                $groups[] = 'COE';
            } elseif ($countryCode === 'CH') {
                $groups[] = 'AELE';
                $groups[] = 'COE';
                $groups[] = 'SCHENGEN_ASSOCIATED';
            } elseif ($countryCode === 'GB') {
                $groups[] = 'COE';
                $groups[] = 'UK_GROUP';
            }

            return ['tld' => $tld, 'type' => 'geoTLD', 'country_code' => $countryCode, 'groups' => array_values(array_unique($groups))];
        }

        if (strlen($tld) === 2 && ctype_alpha($tld)) {
            $countryCode = strtoupper($tld);
            $groups = Config::isEuMember($countryCode) ? ['EU', 'EEE', 'COE'] : [];
            return ['tld' => $tld, 'type' => 'ccTLD', 'country_code' => $countryCode, 'groups' => $groups];
        }

        return ['tld' => $tld, 'type' => null, 'country_code' => null, 'groups' => []];
    }

    private static function extractTld(string $domain): ?string
    {
        $lastDot = strrpos($domain, '.');
        if ($lastDot === false || $lastDot === strlen($domain) - 1) {
            return null;
        }

        return strtolower(substr($domain, $lastDot + 1));
    }
}
