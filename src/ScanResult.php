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
        /** Version lisible du référentiel fournisseurs utilisé pour détecter les dépendances. */
        public readonly string $providerReferenceVersion,
        /** Hash SHA-256 du référentiel fournisseurs utilisé, pour preuve/reproductibilité. */
        public readonly string $providerReferenceSha256,
        public readonly ?string $countryCode,
        /** true si countryCode fait partie des 27 membres de l'UE — false sinon (y compris si countryCode est null ou européen hors UE). */
        public readonly bool $euMember,
        public readonly ?string $defaultLanguage,
        public readonly array $alternateLanguages,
        public readonly ?int $httpStatus,
        /** URL réellement utilisée pour l'analyse éditoriale/catégorielle. */
        public readonly ?string $analysisUrl = null,
        /** Origine de l'URL analysée : root, hreflang, standard_path ou missing. */
        public readonly string $analysisSource = 'missing',
        /** TLD final du domaine, sans point. */
        public readonly ?string $tld = null,
        /** Type de TLD : ccTLD, geoTLD, euTLD, euIDN, reserved... */
        public readonly ?string $tldType = null,
        /** @var string[] Tags de périmètre : EU, EEE, AELE, COE, GEO_TLD, etc. */
        public readonly array $tldGroups = [],
        /** Réserve TDM déclarée dans tdmrep.json — true si réservé (TDM interdit sauf accord), false si explicitement ouvert, null si fichier absent/illisible. */
        public readonly ?bool $tdmReservation = null,
        /** URL de la politique TDM détaillée, si fournie dans tdmrep.json. */
        public readonly ?string $tdmPolicyUrl = null,
        /** Présence d'au moins un bloc <script type="application/ld+json"> dans le <head> — signal d'intention/maturité, indépendant du contenu exact. */
        public readonly bool $hasJsonLd = false,
        /** Présence d'attributs microdata (itemscope/itemtype) dans le <head>. */
        public readonly bool $hasMicrodata = false,
        /** @var array<int, array{name: string, category: string, eu_status: string, dependency_score?: float, tracking_governance_role?: ?string}> Fournisseurs détectés dans le HTML (CDN, analytics, trackers...), voir data/providers.json. */
        public readonly array $dependencies = [],
        /** @var array<int, array{domain: string, sources: string[], suggested_category: string, evidence_types: string[]}> Domaines tiers candidats non reconnus, à qualifier en agrégé. */
        public readonly array $unknownDependencies = [],
        /** @var array<string, mixed> Indices heuristiques sur CMP, traceurs publicitaires et parcours de consentement. */
        public readonly array $consentSignals = [],
        /** IP observée en contactant le domaine — donnée brute, aucune interprétation (hébergeur réel/ASN à résoudre séparément, pas dans le bot de scan). */
        public readonly ?string $ipAddress = null,
        /** Émetteur du certificat SSL (ex. "Let's Encrypt") — donnée brute, récupérée sur la connexion HTTPS déjà établie, aucune requête supplémentaire. */
        public readonly ?string $sslIssuer = null,
        /** Nombre d'occurrences de "food truck"/"foodtruck"/"food-truck" dans le HTML — signal brut simple, tous domaines, pas seulement .be. */
        public readonly int $foodtruckMentions = 0,
        /** @var array<int, array{name: string, slug: string, eu_status: string, type: ?string}> Réseaux sociaux/plateformes de code détectés — via href uniquement, jamais via texte ou script chargé (voir Config::SOCIAL_PLATFORMS). */
        public readonly array $socialPresence = [],
        /** Catégorie Tier 1 (style IAB), slug — vaut "unidentified" si la page principale ne donne pas assez de signaux. */
        public readonly string $category = 'unidentified',
        /** Statut explicite de classification de la page principale : identified/unidentified. */
        public readonly string $categoryStatus = 'unidentified',
        /** Origine de la catégorie : json_ld, microdata, keywords ou homepage_insufficient_signals. */
        public readonly string $categorySource = 'homepage_insufficient_signals',
        /** Confiance heuristique 0-100. Ne doit pas être interprétée comme une probabilité statistique. */
        public readonly int $categoryConfidence = 0,
        /** @var string[] Signaux positifs ayant contribué à la catégorie. */
        public readonly array $categorySignals = [],
        /** @var string[] Contextes négatifs détectés, utiles pour expliquer les corrections. */
        public readonly array $categoryNegativeSignals = [],
        /** Sous-catégorie indicative, non forcée. Null si les signaux de la homepage sont insuffisants. */
        public readonly ?string $categoryTier2 = null,
        /** Confiance heuristique 0-100 pour la sous-catégorie. */
        public readonly int $categoryTier2Confidence = 0,
        /** @var string[] Signaux positifs ayant contribué à la sous-catégorie. */
        public readonly array $categoryTier2Signals = [],
        public readonly ?string $error = null,
        /** @var string[] User-agents déclarés dans robots.txt hors Config::AI_BOTS et hors crawlers classiques connus (Config::KNOWN_NON_AI_USER_AGENTS) — candidats à qualifier manuellement, jamais un verdict. */
        public readonly array $unknownAiBotGroups = [],
    ) {
    }
}
