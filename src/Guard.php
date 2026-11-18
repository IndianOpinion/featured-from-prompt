<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * In-process re-entry guard (case 85).
 *
 * The plugin's own writes (setting a thumbnail, saving meta) fire hooks the
 * plugin listens to. While a guarded write runs, those listeners return early.
 */
final class Guard
{
    private static int $depth = 0;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(callable $fn): mixed
    {
        self::$depth++;
        try {
            return $fn();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
