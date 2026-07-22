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
        public readonly string $countrySource,
        public readonly ?string $defaultLanguage,
        public readonly array $alternateLanguages,
        public readonly ?int $httpStatus,
        public readonly ?string $error = null,
    ) {
    }
}
