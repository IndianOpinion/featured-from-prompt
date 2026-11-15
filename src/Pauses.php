<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Provider pauses after account-level errors (cases 27, 39).
 */
final class Pauses
{
    public static function option(string $provider): string
    {
        return 'ffp_pause_' . $provider;
    }

    /** @return array{reason: string, until: int, set: int}|null */
    public static function get(string $provider): ?array
    {
        $pause = Plugin::storage()->fresh_option(self::option($provider), null);
        if (!is_array($pause) || !isset($pause['reason'])) {
            return null;
        }
        $until = (int) ($pause['until'] ?? 0);
        if ($until > 0 && $until <= Plugin::clock()->now()) {
            return null;
        }
        return ['reason' => (string) $pause['reason'], 'until' => $until, 'set' => (int) ($pause['set'] ?? 0)];
    }

    public static function is_paused(string $provider): bool
    {
        return self::get($provider) !== null;
    }

    /** Pause a provider. Until 0 means until an administrator resumes it. */
    public static function pause(string $provider, string $reason, int $until): void
    {
        if (self::is_paused($provider)) {
            return;
        }
        $value = ['reason' => $reason, 'until' => $until, 'set' => Plugin::clock()->now()];
        $name = self::option($provider);
        if (get_option($name, null) === null) {
            add_option($name, $value, '', false);
        } else {
            update_option($name, $value, false);
        }
        Plugin::logger()->notice('Provider paused.', ['provider' => $provider, 'reason' => $reason, 'until' => $until]);
        do_action('ffp_provider_paused', $provider, $reason, $until);
    }

    public static function resume(string $provider): void
    {
        delete_option(self::option($provider));
    }
}
