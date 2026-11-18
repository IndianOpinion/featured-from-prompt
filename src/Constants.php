<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Reads the FFP_* constants. Tests can override values without defining
 * real constants, which cannot be undefined.
 */
final class Constants
{
    /** @var array<string, mixed> */
    private static array $overrides = [];

    public static function get(string $name): mixed
    {
        if (array_key_exists($name, self::$overrides)) {
            return self::$overrides[$name];
        }
        return defined($name) ? constant($name) : null;
    }

    public static function has(string $name): bool
    {
        if (array_key_exists($name, self::$overrides)) {
            return self::$overrides[$name] !== null;
        }
        return defined($name);
    }

    /** Test helper. Pass null to remove an override. */
    public static function override(string $name, mixed $value): void
    {
        if ($value === null) {
            unset(self::$overrides[$name]);
            return;
        }
        self::$overrides[$name] = $value;
    }

    public static function reset(): void
    {
        self::$overrides = [];
    }
}
