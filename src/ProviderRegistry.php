<?php

declare(strict_types=1);

namespace OgpnBot;

final class ProviderRegistry
{
    private const DEFAULT_PATH = __DIR__ . '/../data/providers.json';

    /** @var array<int, array{name: string, category: string, eu_status: string, patterns: string[]}> */
    private array $providers;
    private string $version;
    private string $sha256;

    public function __construct(?string $path = null)
    {
        $path ??= self::DEFAULT_PATH;

        if (is_file($path)) {
            $content = (string) file_get_contents($path);
            $decoded = json_decode($content, associative: true);
            if (is_array($decoded) && is_array($decoded['providers'] ?? null)) {
                $this->providers = $this->normalizeProviders($decoded['providers']);
                $this->version = is_string($decoded['version'] ?? null) ? $decoded['version'] : 'unknown';
                $this->sha256 = hash('sha256', $content);
                return;
            }
        }

        $this->providers = $this->normalizeProviders(Config::DEPENDENCY_PROVIDERS);
        $this->version = 'legacy-config-fallback';
        $this->sha256 = hash('sha256', json_encode($this->providers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** @return array<int, array{name: string, category: string, eu_status: string, patterns: string[]}> */
    public function providers(): array
    {
        return $this->providers;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    /** @param array<int, mixed> $providers */
    private function normalizeProviders(array $providers): array
    {
        $normalized = [];

        foreach ($providers as $provider) {
            if (!is_array($provider)) {
                continue;
            }

            $name = trim((string) ($provider['name'] ?? ''));
            $category = trim((string) ($provider['category'] ?? ''));
            $euStatus = trim((string) ($provider['eu_status'] ?? ''));
            $patterns = is_array($provider['patterns'] ?? null) ? $provider['patterns'] : [];
            $patterns = array_values(array_filter(array_map(
                static fn (mixed $pattern): string => trim((string) $pattern),
                $patterns,
            )));

            if ($name === '' || $category === '' || $euStatus === '' || $patterns === []) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'category' => $category,
                'eu_status' => $euStatus,
                'patterns' => $patterns,
            ];
        }

        return $normalized;
    }
}
