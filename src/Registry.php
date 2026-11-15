<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

use IndianOpinion\FeaturedFromPrompt\Provider\ImageProvider;

/**
 * Registered providers and their settings.
 */
final class Registry
{
    /** @var array<string, ImageProvider> */
    private array $providers = [];

    private bool $loaded = false;

    public function register(ImageProvider $provider): void
    {
        $id = $provider->id();
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Provider id must match ^[a-z0-9][a-z0-9_-]{0,39}$.');
        }
        $this->providers[$id] = $provider;
    }

    public function get(string $id): ?ImageProvider
    {
        $this->load();
        return $this->providers[$id] ?? null;
    }

    /** @return array<string, ImageProvider> */
    public function all(): array
    {
        $this->load();
        return $this->providers;
    }

    /** Fire ffp_register_providers once. */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        do_action('ffp_register_providers', $this);
    }

    /**
     * Field values for a provider: stored values, defaults, then secrets.
     *
     * @return array<string, string>
     */
    public static function settings_for(ImageProvider $provider): array
    {
        $stored = (array) Settings::get('provider_settings');
        $values = isset($stored[$provider->id()]) && is_array($stored[$provider->id()]) ? $stored[$provider->id()] : [];
        $out = [];
        foreach ($provider->settings_fields() as $field) {
            $key = $field['key'];
            if ($field['type'] === 'secret') {
                $out[$key] = Secrets::get($provider->id(), $key);
                continue;
            }
            $out[$key] = isset($values[$key]) && is_scalar($values[$key]) ? (string) $values[$key] : (string) ($field['default'] ?? '');
        }
        return $out;
    }
}
