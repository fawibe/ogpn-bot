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
     * User-agents fréquents dans robots.txt qui NE sont PAS des bots IA —
     * moteurs de recherche classiques, outils SEO, aperçus de liens sociaux,
     * wildcard générique. Exclus de la détection "bot IA inconnu" pour que
     * cette liste reste exploitable (des dizaines de sites déclarent des
     * règles pour Googlebot/Bingbot/AhrefsBot sans que ce soit un signal
     * utile pour ce projet). Volontairement non exhaustive, à enrichir au
     * même titre qu'AI_BOTS si du bruit récurrent apparaît.
     */
    public const KNOWN_NON_AI_USER_AGENTS = [
        '*',
        'googlebot', 'googlebot-image', 'googlebot-news', 'googlebot-video', 'adsbot-google', 'mediapartners-google',
        'bingbot', 'msnbot', 'adidxbot',
        'yandexbot', 'yandex',
        'baiduspider',
        'duckduckbot', 'duckduckgo-favicons-bot',
        'slurp', // Yahoo
        'sogou',
        'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'seznambot', 'blexbot', 'petalbot',
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'pinterestbot', 'whatsapp', 'telegrambot', 'discordbot',
        'applebot', // distinct d'Applebot-Extended, qui LUI est dans AI_BOTS
        'archive.org_bot', 'ia_archiver',
    ];

    /**
     * Groupe A — convention "racine uniquement" : aucune norme well-known
     * n'existe pour ces fichiers, chercher dans .well-known/ n'aurait pas de
     * sens normatif.
     */
    public const GROUP_A_ROOT_ONLY = [
        'llms' => 'llms.txt',
    ];

    /**
     * Groupe B — convention "well-known" : recherché aux deux emplacements
     * (racine ET well-known), avec un flag de positionnement indépendant du
     * contenu. Le nom de fichier bien-known peut différer du nom racine.
     */
    public const GROUP_B_DUAL_LOOKUP = [
        'ai_txt' => 'ai.txt',
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
        'gent' => 'BE',
        'vlaanderen' => 'BE',
        'wien' => 'AT',
        'tirol' => 'AT',
        'berlin' => 'DE',
        'hamburg' => 'DE',
        'koeln' => 'DE',
        'cologne' => 'DE',
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
        'barcelona' => 'ES',
        'madrid' => 'ES',
        'gal' => 'ES',
        'cat' => 'ES',
        'eus' => 'ES', // Pays basque, frontalier FR/ES — Espagne par convention documentée
        'frl' => 'NL',
        'amsterdam' => 'NL',
        'swiss' => 'CH',
        'zuerich' => 'CH',
    ];

    /**
     * TLD supranationaux ou réservés qui ne doivent jamais être transformés
     * mécaniquement en code pays ISO. Les IDN .eu sont stockés ici en punycode,
     * format le plus robuste pour les URLs et Common Crawl.
     */
    public const NON_COUNTRY_TLDS = [
        'eu', 'xn--e1a4c', 'xn--qxa6a', // .eu, .ею, .ευ
        'gb', // réservé/historique ; le TLD opérationnel britannique est .uk
    ];

    /**
     * Pays réellement membres de l'Union européenne (27) — distinct de
     * "européen" au sens large. CH, GB, NO, IS, LI sont européens mais hors
     * UE : un site chez eux peut être parfaitement souverain sans pour
     * autant relever du même cadre réglementaire (RGPD applicable
     * différemment, AI Act, etc.) — les deux notions ne doivent jamais être
     * confondues dans le scoring.
     */
    public const EU_MEMBER_COUNTRIES = [
        'BE', 'FR', 'DE', 'NL', 'LU', 'AT', 'IT', 'ES', 'PT',
        'IE', 'DK', 'SE', 'FI', 'PL', 'CZ', 'SK', 'HU', 'RO',
        'BG', 'GR', 'HR', 'SI', 'EE', 'LV', 'LT', 'MT', 'CY',
    ];

    public static function isEuMember(?string $countryCode): bool
    {
        return $countryCode !== null && in_array($countryCode, self::EU_MEMBER_COUNTRIES, true);
    }

    /**
     * Fournisseurs/services connus, détectables depuis le HTML (balises
     * <script src>, <link href>) — trois listes de souveraineté :
     *  - rouge : hors UE, juridiction étrangère
     *  - vert  : souveraineté UE confirmée
     *  - gris  : neutre/open source — la souveraineté dépend de l'hébergeur,
     *            pas du logiciel lui-même
     * Liste volontairement non exhaustive, à enrichir au fil du temps.
     * Ne couvre que ce qui est visible dans le HTML — l'hébergeur réel (via
     * IP/ASN) et les enregistrements DNS (MX, etc.) nécessitent une détection
     * séparée, pas encore en place (voir notes de conception du projet).
     */
    public const DEPENDENCY_PROVIDERS = [
        // --- CDN ---
        ['name' => 'Cloudflare', 'category' => 'cdn', 'eu_status' => 'rouge', 'patterns' => ['cloudflare.com', 'cdnjs.cloudflare.com', 'cf-ipv6.com']],
        ['name' => 'jsDelivr', 'category' => 'cdn', 'eu_status' => 'gris', 'patterns' => ['jsdelivr.net']],
        ['name' => 'cdnjs (sans Cloudflare)', 'category' => 'cdn', 'eu_status' => 'gris', 'patterns' => ['cdnjs.com']],
        ['name' => 'Bunny CDN', 'category' => 'cdn', 'eu_status' => 'vert', 'patterns' => ['b-cdn.net', 'bunny.net']],
        ['name' => 'Amazon CloudFront', 'category' => 'cdn', 'eu_status' => 'rouge', 'patterns' => ['cloudfront.net']],
        ['name' => 'Fastly', 'category' => 'cdn_waf', 'eu_status' => 'rouge', 'patterns' => ['fastly.net']],
        ['name' => 'Akamai', 'category' => 'cdn_waf', 'eu_status' => 'rouge', 'patterns' => ['akamaihd.net', 'akamai.net', 'akamai.com', 'akamaized.net']],
        ['name' => 'unpkg', 'category' => 'cdn_js', 'eu_status' => 'rouge', 'patterns' => ['unpkg.com']],
        ['name' => 'Bunny Fonts', 'category' => 'fonts', 'eu_status' => 'vert', 'patterns' => ['fonts.bunny.net']],

        // --- Polices ---
        ['name' => 'Google Fonts', 'category' => 'fonts', 'eu_status' => 'rouge', 'patterns' => ['fonts.googleapis.com', 'fonts.gstatic.com']],
        ['name' => 'Adobe Fonts', 'category' => 'fonts', 'eu_status' => 'rouge', 'patterns' => ['use.typekit.net']],

        // --- Analytics / mesure d'audience ---
        ['name' => 'Google Analytics', 'category' => 'analytics', 'eu_status' => 'rouge', 'patterns' => ['google-analytics.com', 'analytics.js', 'ga.js', '/g/collect']],
        ['name' => 'Google Firebase Analytics', 'category' => 'analytics', 'eu_status' => 'rouge', 'patterns' => ['firebase-analytics.js', 'firebase/analytics', 'firebase.google.com/docs/analytics', 'app-measurement.com']],
        ['name' => 'Matomo (cloud ou auto-hébergé)', 'category' => 'analytics', 'eu_status' => 'jaune', 'patterns' => ['matomo.cloud', 'matomo.js', 'piwik.js']],
        ['name' => 'Plausible', 'category' => 'analytics', 'eu_status' => 'vert', 'patterns' => ['plausible.io']],
        ['name' => 'Hotjar', 'category' => 'analytics', 'eu_status' => 'rouge', 'patterns' => ['hotjar.com']],
        ['name' => 'Microsoft Clarity', 'category' => 'analytics', 'eu_status' => 'rouge', 'patterns' => ['clarity.ms']],
        ['name' => 'Google Tag Manager', 'category' => 'tag_manager', 'eu_status' => 'rouge', 'patterns' => ['googletagmanager.com/gtm.js', 'googletagmanager.com/gtag/js']],
        ['name' => 'Adobe Analytics', 'category' => 'analytics', 'eu_status' => 'rouge', 'patterns' => ['omtrdc.net', '2o7.net', 'adobedc.net', 'adobedtm.com']],
        ['name' => 'Adobe Experience Cloud', 'category' => 'marketing_cloud', 'eu_status' => 'rouge', 'patterns' => ['demdex.net', 'everesttech.net', 'assets.adobedtm.com', 'adobe.com/experience-cloud']],
        ['name' => 'Adobe Advertising / Ad Cloud', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['adobedc.net', 'everesttech.net', 'demdex.net', 'adcloud', 'adobe_mc']],
        ['name' => 'Mixpanel', 'category' => 'analytics', 'eu_status' => 'rouge', 'patterns' => ['mixpanel.com', 'mxpnl.com']],
        ['name' => 'Segment', 'category' => 'analytics', 'eu_status' => 'rouge', 'patterns' => ['segment.com', 'segment.io']],
        ['name' => 'Fathom Analytics', 'category' => 'analytics', 'eu_status' => 'jaune', 'patterns' => ['usefathom.com', 'cdn.usefathom.com']],
        ['name' => 'Statcounter', 'category' => 'analytics', 'eu_status' => 'rouge', 'patterns' => ['statcounter.com', 'statcounter.js']],
        ['name' => 'Cloudflare Web Analytics / Insights', 'category' => 'analytics', 'eu_status' => 'rouge', 'patterns' => ['static.cloudflareinsights.com', 'cloudflareinsights.com/beacon.min.js']],
        ['name' => 'Piano Analytics / AT Internet', 'category' => 'analytics', 'eu_status' => 'jaune', 'patterns' => ['piano.io', 'atinternet.com', 'xiti.com', 'smarttag.js']],
        ['name' => 'Simple Analytics', 'category' => 'analytics', 'eu_status' => 'vert', 'patterns' => ['simpleanalytics.com']],
        ['name' => 'Umami', 'category' => 'analytics', 'eu_status' => 'gris', 'patterns' => ['umami.js', 'umami.is']],
        ['name' => 'Pirsch', 'category' => 'analytics', 'eu_status' => 'vert', 'patterns' => ['pirsch.io']],

        // --- Monitoring navigateur / session replay / experimentation ---
        ['name' => 'Bugsnag', 'category' => 'rum_monitoring', 'eu_status' => 'rouge', 'patterns' => ['bugsnag.com', 'bugsnag-js']],
        ['name' => 'Sentry Browser', 'category' => 'rum_monitoring', 'eu_status' => 'rouge', 'patterns' => ['browser.sentry-cdn.com', 'sentry.io', '@sentry/browser']],
        ['name' => 'Datadog RUM', 'category' => 'rum_monitoring', 'eu_status' => 'rouge', 'patterns' => ['datadoghq-browser-agent.com', 'datadog-rum']],
        ['name' => 'New Relic Browser', 'category' => 'rum_monitoring', 'eu_status' => 'rouge', 'patterns' => ['js-agent.newrelic.com', 'bam.nr-data.net']],
        ['name' => 'Contentsquare', 'category' => 'session_replay', 'eu_status' => 'jaune', 'patterns' => ['contentsquare.net', 'contentsquare.com', 'uxa.cloud']],
        ['name' => 'FullStory', 'category' => 'session_replay', 'eu_status' => 'rouge', 'patterns' => ['fullstory.com', 'edge.fullstory.com']],
        ['name' => 'LogRocket', 'category' => 'session_replay', 'eu_status' => 'rouge', 'patterns' => ['logrocket.com', 'cdn.lr-ingest.com']],
        ['name' => 'Mouseflow', 'category' => 'session_replay', 'eu_status' => 'rouge', 'patterns' => ['mouseflow.com']],
        ['name' => 'Crazy Egg', 'category' => 'session_replay', 'eu_status' => 'rouge', 'patterns' => ['crazyegg.com']],
        ['name' => 'AB Tasty', 'category' => 'ab_testing', 'eu_status' => 'jaune', 'patterns' => ['abtasty.com', 'try.abtasty.com']],
        ['name' => 'Optimizely', 'category' => 'ab_testing', 'eu_status' => 'rouge', 'patterns' => ['optimizely.com', 'cdn.optimizely.com']],

        // --- Authentification ---
        ['name' => 'Google Identity/Sign-In', 'category' => 'auth', 'eu_status' => 'rouge', 'patterns' => ['accounts.google.com/gsi', 'apis.google.com/js/platform.js']],
        ['name' => 'Facebook Login', 'category' => 'auth', 'eu_status' => 'rouge', 'patterns' => ['connect.facebook.net/en_US/sdk.js']],

        // --- Trackers publicitaires / attribution / réseaux sociaux ---
        ['name' => 'Meta Pixel', 'category' => 'social_pixel', 'eu_status' => 'rouge', 'patterns' => ['connect.facebook.net', 'facebook.com/tr']],
        ['name' => 'TikTok Pixel', 'category' => 'social_pixel', 'eu_status' => 'rouge', 'patterns' => ['analytics.tiktok.com', 'business-api.tiktok.com', 'tiktok.com/i18n/pixel/events.js']],
        ['name' => 'Pinterest Tag', 'category' => 'social_pixel', 'eu_status' => 'rouge', 'patterns' => ['s.pinimg.com/ct/', 'ct.pinterest.com', 'analytics.pinterest.com']],
        ['name' => 'LinkedIn Insight', 'category' => 'social_pixel', 'eu_status' => 'rouge', 'patterns' => ['snap.licdn.com', 'linkedin.com/li/track']],
        ['name' => 'X (Twitter) Ads', 'category' => 'social_pixel', 'eu_status' => 'rouge', 'patterns' => ['static.ads-twitter.com', 'analytics.twitter.com']],
        ['name' => 'Snapchat Pixel', 'category' => 'social_pixel', 'eu_status' => 'rouge', 'patterns' => ['sc-static.net/scevent.min.js', 'tr.snapchat.com']],
        ['name' => 'Reddit Pixel', 'category' => 'social_pixel', 'eu_status' => 'rouge', 'patterns' => ['alb.reddit.com', 'events.reddit.com', 'redditstatic.com/ads/pixel.js']],
        ['name' => 'Google Ads', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['googleadservices.com', 'googlesyndication.com', 'google.com/ads', 'googleads.g.doubleclick.net']],
        ['name' => 'DoubleClick', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['doubleclick.net']],
        ['name' => 'Google AdServices', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['www.googleadservices.com/pagead/conversion', 'adservice.google.']],
        ['name' => 'Microsoft Advertising / Bing UET', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['bat.bing.com', 'bing.com/bat.js']],
        ['name' => 'Criteo', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['criteo.com', 'criteo.net']],
        ['name' => 'Taboola', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['taboola.com']],
        ['name' => 'Outbrain', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['outbrain.com', 'outbrainimg.com']],
        ['name' => 'The Trade Desk', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['adsrvr.org', 'thetradedesk.com']],
        ['name' => 'Quantcast', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['quantserve.com', 'quantcount.com', 'quantcast.com']],
        ['name' => 'PubMatic', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['pubmatic.com', 'pub.network']],
        ['name' => 'Magnite / Rubicon', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['rubiconproject.com', 'magnite.com']],
        ['name' => 'Index Exchange', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['indexexchange.com']],
        ['name' => 'OpenX', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['openx.net']],
        ['name' => 'Media.net', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['media.net']],
        ['name' => 'Adform', 'category' => 'advertising_tracker', 'eu_status' => 'jaune', 'patterns' => ['adform.net']],
        ['name' => 'Smart AdServer / Equativ', 'category' => 'advertising_tracker', 'eu_status' => 'jaune', 'patterns' => ['smartadserver.com', 'equativ.net']],
        ['name' => 'Teads', 'category' => 'advertising_tracker', 'eu_status' => 'jaune', 'patterns' => ['teads.tv', 'teads.com']],
        ['name' => 'Amazon Ads', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['amazon-adsystem.com', 'aaxads.com']],
        ['name' => 'AdRoll', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['adroll.com', 'd.adroll.com']],
        ['name' => 'Lotame', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['crwdcntrl.net', 'lotame.com']],
        ['name' => 'LiveRamp', 'category' => 'advertising_tracker', 'eu_status' => 'rouge', 'patterns' => ['liveramp.com', 'rlcdn.com']],
        ['name' => 'Utiq', 'category' => 'identifier_attribution', 'eu_status' => 'jaune', 'patterns' => ['utiq.com', 'utiq.io']],
        ['name' => 'Singular', 'category' => 'identifier_attribution', 'eu_status' => 'rouge', 'patterns' => ['singular.net', 'sdk-api-v1.singular.net']],
        ['name' => 'AppsFlyer', 'category' => 'identifier_attribution', 'eu_status' => 'rouge', 'patterns' => ['appsflyer.com', 'appsflyersdk.com']],
        ['name' => 'Adjust', 'category' => 'identifier_attribution', 'eu_status' => 'rouge', 'patterns' => ['adjust.com', 'adjust.net']],
        ['name' => 'Branch', 'category' => 'identifier_attribution', 'eu_status' => 'rouge', 'patterns' => ['branch.io', 'app.link']],
        ['name' => 'Adometry', 'category' => 'identifier_attribution', 'eu_status' => 'rouge', 'patterns' => ['adometry.com']],

        // --- Gestion du consentement (CMP) ---
        ['name' => 'Axeptio', 'category' => 'cmp', 'eu_status' => 'vert', 'patterns' => ['axept.io', 'axeptio.eu']],
        ['name' => 'Tarteaucitron', 'category' => 'cmp', 'eu_status' => 'gris', 'patterns' => ['tarteaucitron.js']],
        ['name' => 'Cookiebot', 'category' => 'cmp', 'eu_status' => 'vert', 'patterns' => ['cookiebot.com']],
        ['name' => 'OneTrust', 'category' => 'cmp', 'eu_status' => 'rouge', 'patterns' => ['onetrust.com', 'cookielaw.org']],
        ['name' => 'CookieYes', 'category' => 'cmp', 'eu_status' => 'rouge', 'patterns' => ['cookieyes.com']],
        ['name' => 'Didomi', 'category' => 'cmp', 'eu_status' => 'vert', 'patterns' => ['didomi.io']],
        ['name' => 'Sourcepoint', 'category' => 'cmp', 'eu_status' => 'rouge', 'patterns' => ['sourcepointcmp.com']],
        ['name' => 'Quantcast Choice', 'category' => 'cmp', 'eu_status' => 'rouge', 'patterns' => ['quantcast.mgr.consensu.org', 'choice.quantcast.com']],
        ['name' => 'Usercentrics', 'category' => 'cmp', 'eu_status' => 'jaune', 'patterns' => ['usercentrics.eu', 'usercentrics.com']],
        ['name' => 'TrustArc', 'category' => 'cmp', 'eu_status' => 'rouge', 'patterns' => ['trustarc.com', 'truste.com']],
        ['name' => 'Iubenda', 'category' => 'cmp', 'eu_status' => 'jaune', 'patterns' => ['iubenda.com']],
        ['name' => 'Complianz', 'category' => 'cmp', 'eu_status' => 'gris', 'patterns' => ['complianz.io', 'complianz-gdpr']],
        ['name' => 'Sirdata CMP', 'category' => 'cmp', 'eu_status' => 'jaune', 'patterns' => ['sirdata.com', 'cmp.sirdata.io']],
        ['name' => 'SFBX / Commanders Act CMP', 'category' => 'cmp', 'eu_status' => 'jaune', 'patterns' => ['sfbx.io', 'commandersact.com', 'tagcommander.com']],
        ['name' => 'Cookie Information', 'category' => 'cmp', 'eu_status' => 'jaune', 'patterns' => ['cookieinformation.com']],
        ['name' => 'Osano', 'category' => 'cmp', 'eu_status' => 'rouge', 'patterns' => ['osano.com']],

        // --- Captcha / anti-bot ---
        ['name' => 'Google reCAPTCHA', 'category' => 'captcha', 'eu_status' => 'rouge', 'patterns' => ['google.com/recaptcha', 'recaptcha.net']],
        ['name' => 'Cloudflare Turnstile', 'category' => 'captcha', 'eu_status' => 'rouge', 'patterns' => ['challenges.cloudflare.com']],
        ['name' => 'hCaptcha', 'category' => 'captcha', 'eu_status' => 'rouge', 'patterns' => ['hcaptcha.com']],
        ['name' => 'Friendly Captcha', 'category' => 'captcha', 'eu_status' => 'vert', 'patterns' => ['friendlycaptcha.com']],
        ['name' => 'Altcha', 'category' => 'captcha', 'eu_status' => 'gris', 'patterns' => ['altcha.org']],

        // --- Paiement ---
        ['name' => 'Stripe', 'category' => 'payment', 'eu_status' => 'rouge', 'patterns' => ['js.stripe.com']],
        ['name' => 'PayPal', 'category' => 'payment', 'eu_status' => 'rouge', 'patterns' => ['paypal.com/sdk']],
        ['name' => 'Square', 'category' => 'payment', 'eu_status' => 'rouge', 'patterns' => ['squareup.com', 'squarecdn.com']],
        ['name' => 'Mollie', 'category' => 'payment', 'eu_status' => 'vert', 'patterns' => ['mollie.com']],
        ['name' => 'PayPlug', 'category' => 'payment', 'eu_status' => 'vert', 'patterns' => ['payplug.com']],

        // --- Cartographie / vidéo / support ---
        ['name' => 'Google Maps', 'category' => 'maps', 'eu_status' => 'rouge', 'patterns' => ['maps.googleapis.com', 'google.com/maps']],
        ['name' => 'Mapbox', 'category' => 'maps', 'eu_status' => 'rouge', 'patterns' => ['mapbox.com', 'api.mapbox.com']],
        ['name' => 'OpenStreetMap', 'category' => 'maps', 'eu_status' => 'gris', 'patterns' => ['openstreetmap.org', 'tile.openstreetmap.org']],
        ['name' => 'Leaflet', 'category' => 'maps', 'eu_status' => 'gris', 'patterns' => ['leafletjs.com', 'leaflet.js']],
        ['name' => 'Vimeo', 'category' => 'video', 'eu_status' => 'rouge', 'patterns' => ['vimeo.com', 'player.vimeo.com']],
        ['name' => 'Intercom', 'category' => 'support_chat', 'eu_status' => 'rouge', 'patterns' => ['intercom.io', 'intercomcdn.com']],
        ['name' => 'Crisp', 'category' => 'support_chat', 'eu_status' => 'vert', 'patterns' => ['crisp.chat', 'client.crisp.chat']],

        // --- CMS / frameworks (gris — la souveraineté dépend de l'hébergeur) ---
        ['name' => 'WordPress', 'category' => 'cms', 'eu_status' => 'gris', 'patterns' => ['wp-content', 'wp-includes']],
        ['name' => 'Drupal', 'category' => 'cms', 'eu_status' => 'gris', 'patterns' => ['drupal.js', 'drupal.min.js', 'x-generator: drupal']],
        ['name' => 'Joomla', 'category' => 'cms', 'eu_status' => 'gris', 'patterns' => ['joomla', '/media/jui/', '/media/system/']],
        ['name' => 'TYPO3', 'category' => 'cms', 'eu_status' => 'gris', 'patterns' => ['typo3']],
        ['name' => 'PrestaShop', 'category' => 'ecommerce', 'eu_status' => 'gris', 'patterns' => ['prestashop']],
        ['name' => 'WooCommerce', 'category' => 'ecommerce', 'eu_status' => 'gris', 'patterns' => ['woocommerce', '/wp-content/plugins/woocommerce/']],
        ['name' => 'Shopify', 'category' => 'ecommerce', 'eu_status' => 'rouge', 'patterns' => ['cdn.shopify.com', 'myshopify.com']],
        ['name' => 'Magento / Adobe Commerce', 'category' => 'ecommerce', 'eu_status' => 'jaune', 'patterns' => ['Magento_Customer', 'Magento_Ui', 'Magento_Theme']],
        ['name' => "Let's Encrypt", 'category' => 'ssl', 'eu_status' => 'gris', 'patterns' => ["Let's Encrypt", 'letsencrypt.org']],
        ['name' => 'ZeroSSL', 'category' => 'ssl', 'eu_status' => 'gris', 'patterns' => ['ZeroSSL']],
    ];

    /**
     * Réseaux sociaux et plateformes de code — détection VOLONTAIREMENT
     * distincte de DEPENDENCY_PROVIDERS : ici on cherche uniquement à
     * l'intérieur d'attributs href="", jamais dans le texte ou le code
     * technique de la page. Un lien href = très probablement le site a
     * réellement un compte/une page sur ce réseau. Un simple mot dans le
     * texte ou un script chargé (pixel de tracking) n'a rien à voir avec
     * cette présence — c'est justement la distinction qu'on avait retenue.
     *
     * 'patterns' : sous-chaînes à chercher (recherche simple, insensible à
     * la casse) — sauf pour les entrées 'generic' => true, qui utilisent une
     * vraie expression régulière (nécessaire pour Mastodon : instance
     * arbitraire, pas de domaine fixe).
     *
     * 'type_patterns' (optionnel) : sous-motifs testés dans l'ordre pour
     * distinguer profil personnel / page entreprise / groupe — seulement
     * là où la structure d'URL le permet réellement (voir notes de
     * conception : LinkedIn, Facebook, Reddit, WhatsApp, YouTube).
     */
    public const SOCIAL_PLATFORMS = [
        ['name' => 'Facebook', 'slug' => 'facebook', 'eu_status' => 'rouge', 'patterns' => ['facebook.com/'], 'type_patterns' => ['group' => '/groups/']],
        ['name' => 'Instagram', 'slug' => 'instagram', 'eu_status' => 'rouge', 'patterns' => ['instagram.com/']],
        ['name' => 'X (Twitter)', 'slug' => 'x_twitter', 'eu_status' => 'rouge', 'patterns' => ['twitter.com/', 'x.com/']],
        ['name' => 'LinkedIn', 'slug' => 'linkedin', 'eu_status' => 'rouge', 'patterns' => ['linkedin.com/'], 'type_patterns' => ['company' => '/company/', 'personal' => '/in/', 'group' => '/groups/']],
        ['name' => 'YouTube', 'slug' => 'youtube', 'eu_status' => 'rouge', 'patterns' => ['youtube.com/', 'youtu.be/'], 'type_patterns' => ['channel' => '/channel/']],
        ['name' => 'TikTok', 'slug' => 'tiktok', 'eu_status' => 'rouge', 'patterns' => ['tiktok.com/']],
        ['name' => 'Threads', 'slug' => 'threads', 'eu_status' => 'rouge', 'patterns' => ['threads.net/']],
        ['name' => 'Bluesky', 'slug' => 'bluesky', 'eu_status' => 'rouge', 'patterns' => ['bsky.app/']],
        ['name' => 'Twitch', 'slug' => 'twitch', 'eu_status' => 'rouge', 'patterns' => ['twitch.tv/']],
        ['name' => 'Discord', 'slug' => 'discord', 'eu_status' => 'rouge', 'patterns' => ['discord.gg/', 'discord.com/invite'], 'default_type' => 'group'],
        ['name' => 'Snapchat', 'slug' => 'snapchat', 'eu_status' => 'rouge', 'patterns' => ['snapchat.com/']],
        ['name' => 'Pinterest', 'slug' => 'pinterest', 'eu_status' => 'rouge', 'patterns' => ['pinterest.com/', 'pinterest.fr/']],
        ['name' => 'WhatsApp', 'slug' => 'whatsapp', 'eu_status' => 'rouge', 'patterns' => ['wa.me/', 'chat.whatsapp.com/', 'api.whatsapp.com/'], 'type_patterns' => ['group' => 'chat.whatsapp.com/']],
        ['name' => 'Telegram', 'slug' => 'telegram', 'eu_status' => 'rouge', 'patterns' => ['t.me/', 'telegram.me/']],
        ['name' => 'Reddit', 'slug' => 'reddit', 'eu_status' => 'rouge', 'patterns' => ['reddit.com/'], 'type_patterns' => ['community' => '/r/', 'personal' => '/user/']],
        ['name' => 'Xing', 'slug' => 'xing', 'eu_status' => 'vert', 'patterns' => ['xing.com/']],
        ['name' => 'GitHub', 'slug' => 'github', 'eu_status' => 'rouge', 'patterns' => ['github.com/']],
        ['name' => 'GitLab', 'slug' => 'gitlab', 'eu_status' => 'rouge', 'patterns' => ['gitlab.com/']],
        ['name' => 'Codeberg', 'slug' => 'codeberg', 'eu_status' => 'vert', 'patterns' => ['codeberg.org/']],
        ['name' => 'W Social', 'slug' => 'wsocial', 'eu_status' => 'vert', 'patterns' => ['wsocial.eu', 'wsocial.news']],
        ['name' => 'Malt', 'slug' => 'malt', 'eu_status' => 'vert', 'patterns' => ['malt.fr/', 'malt.de/', 'malt.es/', 'malt.ch/', 'malt.nl/']],
        // Matrix : matrix.to est un domaine de redirection universel, fixe
        // quel que soit le serveur d'hébergement réel — pas besoin de motif
        // générique pour celui-là.
        ['name' => 'Matrix', 'slug' => 'matrix', 'eu_status' => 'vert', 'patterns' => ['matrix.to/#/@']],
        // Mastodon : fédéré, instance arbitraire — seul cas nécessitant un
        // vrai motif générique (regex), testé UNIQUEMENT si aucun domaine
        // fixe connu n'a déjà matché (TikTok et Threads utilisent aussi
        // "/@nom" dans leurs URLs — l'ordre de vérification évite la confusion).
        ['name' => 'Mastodon (instance non identifiée)', 'slug' => 'mastodon', 'eu_status' => 'vert', 'generic' => true, 'patterns' => ['/\/@[A-Za-z0-9_.-]+/']],
    ];
}
