<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Provider secrets.
 *
 * Constants FFP_SECRET_<PROVIDER>_<FIELD> win. Stored secrets live in
 * non-autoloaded options and are never printed back, logged or returned.
 */
final class Secrets
{
    public static function constant_name(string $provider, string $field): string
    {
        $norm = static fn (string $s): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '_', $s));
        return 'FFP_SECRET_' . $norm($provider) . '_' . $norm($field);
    }

    public static function option_name(string $provider, string $field): string
    {
        return 'ffp_secret_' . $provider . '_' . $field;
    }

    public static function get(string $provider, string $field): string
    {
        $constant = Constants::get(self::constant_name($provider, $field));
        if (is_string($constant) && $constant !== '') {
            return $constant;
        }
        $stored = Plugin::storage()->fresh_option(self::option_name($provider, $field), '');
        return is_string($stored) ? $stored : '';
    }

    public static function from_constant(string $provider, string $field): bool
    {
        $constant = Constants::get(self::constant_name($provider, $field));
        return is_string($constant) && $constant !== '';
    }

    public static function is_set(string $provider, string $field): bool
    {
        return self::get($provider, $field) !== '';
    }

    public static function set(string $provider, string $field, string $value): void
    {
        $name = self::option_name($provider, $field);
        if ($value === '') {
            delete_option($name);
            return;
        }
        if (get_option($name, null) === null) {
            add_option($name, $value, '', false);
            return;
        }
        update_option($name, $value, false);
    }
}
