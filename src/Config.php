<?php

declare(strict_types=1);

namespace OgpnBot;

/**
 * Constantes de configuration du bot — pas de logique ici, uniquement des
 * tables de données à maintenir à jour au fil du temps.
 */
final class Config
{
    /**
     * Bots IA reconnus dans robots.txt — liste volontairement non exhaustive,
     * à enrichir au fil du temps. Le nom est celui utilisé dans la directive
     * "User-agent:" de robots.txt.
     */
    public const AI_BOTS = [
        'GPTBot',           // OpenAI — entraînement
        'ChatGPT-User',     // OpenAI — navigation en direct
        'OAI-SearchBot',    // OpenAI — recherche
        'ClaudeBot',        // Anthropic — entraînement/crawl
        'Claude-User',      // Anthropic — navigation en direct
        'Claude-SearchBot', // Anthropic — recherche
        'CCBot',            // Common Crawl — alimente de nombreux modèles
        'Google-Extended',  // Google — entraînement (distinct de Googlebot)
        'GoogleOther',      // Google — usages divers hors indexation classique
        'Applebot-Extended',// Apple — entraînement (distinct d'Applebot)
        'Bytespider',       // ByteDance/TikTok
        'PerplexityBot',    // Perplexity — crawl
        'Perplexity-User',  // Perplexity — navigation en direct
        'Amazonbot',        // Amazon
        'FacebookBot',      // Meta — entraînement
        'Meta-ExternalAgent', // Meta — entraînement/indexation
        'cohere-ai',        // Cohere
        'Diffbot',          // Diffbot — extraction structurée
        'ImagesiftBot',     // ImageSift
        'omgili',           // Webz.io / omgili
        'YouBot',           // You.com
    ];

    /**
     * Groupe A — convention "racine uniquement" : aucune norme well-known
     * n'existe pour ces fichiers, chercher dans .well-known/ n'aurait pas de
     * sens normatif.
     */
    public const GROUP_A_ROOT_ONLY = [
        'llms' => 'llms.txt',
        'humans' => 'humans.txt',
    ];

    /**
     * Groupe B — convention "well-known" : recherché aux deux emplacements
     * (racine ET well-known), avec un flag de positionnement indépendant du
     * contenu. Le nom de fichier bien-known peut différer du nom racine.
     */
    public const GROUP_B_DUAL_LOOKUP = [
        'ai_txt' => 'ai.txt',
        'security_txt' => 'security.txt',
        'tdmrep' => 'tdmrep.json',
        'ai_policy' => 'ai-policy.json',
    ];

    public const WELL_KNOWN_PREFIX = '/.well-known/';

    /**
     * Pays par TLD — deux mécanismes distincts :
     *  - ccTLD réels (norme ISO 3166-1 alpha-2) : dérivés automatiquement,
     *    pas besoin de les lister ici (le TLD EST le code pays).
     *  - gTLD villes/régions : aucune norme, table écrite à la main.
     * ".eu" et l'absence de correspondance sont volontairement absents :
     * ils déclenchent la logique de repli (lang/og/microdata) côté Scanner.
     */
    public const COUNTRY_BY_SPECIAL_TLD = [
        'brussels' => 'BE',
        'vlaanderen' => 'BE',
        'wien' => 'AT',
        'tirol' => 'AT',
        'berlin' => 'DE',
        'hamburg' => 'DE',
        'koeln' => 'DE',
        'bayern' => 'DE',
        'ruhr' => 'DE',
        'saarland' => 'DE',
        'nrw' => 'DE',
        'paris' => 'FR',
        'bzh' => 'FR',
        'alsace' => 'FR',
        'corsica' => 'FR',
        'scot' => 'GB',
        'london' => 'GB',
        'wales' => 'GB',
        'cymru' => 'GB',
        'gal' => 'ES',
        'cat' => 'ES',
        'eus' => 'ES', // Pays basque, frontalier FR/ES — Espagne par convention documentée
        'frl' => 'NL',
        'amsterdam' => 'NL',
        'rotterdam' => 'NL',
        'zuerich' => 'CH',
        'quebec' => 'CA',
    ];
}
