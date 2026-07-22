<?php

declare(strict_types=1);

namespace OgpnBot;

/**
 * Parseur volontairement simple de robots.txt : suffisant pour répondre à
 * "ce user-agent a-t-il le droit de lire ce chemin ?", pas une implémentation
 * complète de la spec (pas de gestion de wildcards avancés dans Disallow,
 * pas de Crawl-delay/Sitemap — hors scope pour notre usage).
 */
final class RobotsTxt
{
    /** @var array<string, array{allow: string[], disallow: string[]}> Groupes par user-agent (en minuscules). */
    private array $groups = [];

    public function __construct(string $content)
    {
        $this->parse($content);
    }

    private function parse(string $content): void
    {
        $currentAgents = [];
        $groupOpenForDirectives = false; // vrai dès qu'on a lu au moins une directive Allow/Disallow pour le groupe courant
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                // Une ligne User-agent qui suit une directive Allow/Disallow démarre
                // un nouveau groupe. Des User-agent consécutifs (sans directive entre
                // les deux) partagent au contraire le même groupe.
                if ($groupOpenForDirectives) {
                    $currentAgents = [];
                    $groupOpenForDirectives = false;
                }
                $agent = strtolower($value);
                $currentAgents[] = $agent;
                $this->groups[$agent] ??= ['allow' => [], 'disallow' => []];
            } elseif (($field === 'allow' || $field === 'disallow') && $currentAgents !== []) {
                foreach ($currentAgents as $agent) {
                    $this->groups[$agent][$field][] = $value;
                }
                $groupOpenForDirectives = true;
            }
        }
    }

    /**
     * Détermine si $userAgent peut accéder à $path, selon la règle du chemin
     * le plus spécifique qui l'emporte (comportement standard robots.txt).
     * Retourne 'allowed', 'disallowed', ou 'not_mentioned' si le user-agent
     * n'a aucun groupe dédié (on retombe alors sur '*').
     */
    public function check(string $userAgent, string $path = '/'): string
    {
        $agent = strtolower($userAgent);

        if (isset($this->groups[$agent])) {
            return $this->evaluate($this->groups[$agent], $path);
        }

        if (isset($this->groups['*'])) {
            // Pas de groupe dédié à ce bot, mais une règle générale existe :
            // elle s'applique telle quelle (autorisée ou non).
            return $this->evaluate($this->groups['*'], $path);
        }

        return 'not_mentioned';
    }

    /** @param array{allow: string[], disallow: string[]} $group */
    private function evaluate(array $group, string $path): string
    {
        $bestMatch = null;
        $bestLength = -1;
        $bestType = 'allowed';

        foreach ($group['disallow'] as $rule) {
            if ($rule === '') {
                continue; // "Disallow:" vide = tout autorisé, pas une règle de blocage
            }
            if (str_starts_with($path, $rule) && strlen($rule) > $bestLength) {
                $bestLength = strlen($rule);
                $bestType = 'disallowed';
            }
        }

        foreach ($group['allow'] as $rule) {
            if (str_starts_with($path, $rule) && strlen($rule) > $bestLength) {
                $bestLength = strlen($rule);
                $bestType = 'allowed';
            }
        }

        return $bestType;
    }
}
