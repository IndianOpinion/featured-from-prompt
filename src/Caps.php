<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Per-day and per-post caps (cases 29 to 32).
 *
 * Both counters are reserved in the database before the HTTP call, each
 * with one atomic "increment while below the cap" statement.
 */
final class Caps
{
    public const POST_META = '_ffp_day_count';

    public static function post_scope(int $post_id): string
    {
        return 'post:' . $post_id;
    }

    /**
     * @return string|null Null when reserved, else cap_post or cap_day.
     */
    public static function reserve(int $post_id, string $day): ?string
    {
        $storage = Plugin::storage();
        if (!$storage->reserve(self::post_scope($post_id), $day, Settings::post_cap())) {
            do_action('ffp_cap_reached', 'post', $post_id, $day);
            return Reason::CAP_POST;
        }
        if (!$storage->reserve('day', $day, Settings::day_cap())) {
            $storage->refund(self::post_scope($post_id), $day);
            do_action('ffp_cap_reached', 'day', 0, $day);
            return Reason::CAP_DAY;
        }
        self::mirror($post_id, $day);
        return null;
    }

    public static function refund(int $post_id, string $day): void
    {
        $storage = Plugin::storage();
        $storage->refund(self::post_scope($post_id), $day);
        $storage->refund('day', $day);
        self::mirror($post_id, $day);
    }

    /** Reserve a site-day slot only (provider tests, not tied to a post). */
    public static function reserve_day(string $day): bool
    {
        return Plugin::storage()->reserve('day', $day, Settings::day_cap());
    }

    /** @return array{day: string, day_used: int, day_cap: int, day_left: int, post_used: int, post_cap: int, post_left: int} */
    public static function remaining(int $post_id = 0): array
    {
        $day = Plugin::clock()->day();
        $storage = Plugin::storage();
        $day_used = $storage->count('day', $day);
        $day_cap = Settings::day_cap();
        $post_used = $post_id > 0 ? $storage->count(self::post_scope($post_id), $day) : 0;
        $post_cap = Settings::post_cap();
        return [
            'day' => $day,
            'day_used' => $day_used,
            'day_cap' => $day_cap,
            'day_left' => max(0, $day_cap - $day_used),
            'post_used' => $post_used,
            'post_cap' => $post_cap,
            'post_left' => max(0, min($post_cap - $post_used, $day_cap - $day_used)),
        ];
    }

    /** Copy the per-post count into post meta for display. Not authoritative. */
    private static function mirror(int $post_id, string $day): void
    {
        $count = Plugin::storage()->count(self::post_scope($post_id), $day);
        Guard::run(static fn () => update_post_meta($post_id, self::POST_META, ['day' => $day, 'count' => $count]));
    }
}
