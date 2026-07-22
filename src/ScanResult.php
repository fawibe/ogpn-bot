<?php

declare(strict_types=1);

namespace OgpnBot;

final class ScanResult
{
    /**
     * @param array<string, string> $aiBotPolicy Nom du bot => 'allowed'|'disallowed'|'not_mentioned'
     * @param array<string, bool> $filePresence Clé de fichier => présence (au moins un des deux emplacements pour le groupe B)
     * @param array<string, bool> $fileMisplaced Clé de fichier (groupe B uniquement) => trouvé à la racine mais pas en well-known
     * @param array<string, bool> $fileConflict Clé de fichier (groupe B uniquement) => trouvé aux deux endroits avec un contenu différent
     * @param string[] $alternateLanguages Codes de langue détectés via hreflang, hors langue par défaut
     */
    public function __construct(
        public readonly string $domain,
        public readonly bool $robotsBlocksEverything,
        public readonly string $robotsStatus,
        public readonly array $aiBotPolicy,
        public readonly array $filePresence,
        public readonly array $fileMisplaced,
        public readonly array $fileConflict,
        public readonly ?string $countryCode,
        public readonly ?string $defaultLanguage,
        public readonly array $alternateLanguages,
        public readonly ?int $httpStatus,
        /** Réserve TDM déclarée dans tdmrep.json — true si réservé (TDM interdit sauf accord), false si explicitement ouvert, null si fichier absent/illisible. */
        public readonly ?bool $tdmReservation = null,
        /** URL de la politique TDM détaillée, si fournie dans tdmrep.json. */
        public readonly ?string $tdmPolicyUrl = null,
        /** Présence d'au moins un bloc <script type="application/ld+json"> dans le <head> — signal d'intention/maturité, indépendant du contenu exact. */
        public readonly bool $hasJsonLd = false,
        /** Présence d'attributs microdata (itemscope/itemtype) dans le <head>. */
        public readonly bool $hasMicrodata = false,
        public readonly ?string $error = null,
    ) {
    }
}
