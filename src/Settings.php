<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Settings: defaults, bounds and fresh reads.
 *
 * Settings live in one autoloaded option. Guards never read them through
 * get_option(): a persistent object cache could hand back a stale "enabled"
 * value, so every read goes to the database through the storage layer.
 */
final class Settings
{
    public const OPTION = 'ffp_settings';

    public const DEFAULT_TEMPLATE = 'An editorial illustration for an article titled "{title}". It is about: {summary}. Topic: {category}. Style: {style}. Do not include any text, letters, numbers, logos, watermarks, signatures or captions. Do not depict any real, identifiable person.';

    public const DEFAULT_STYLE = 'clean editorial illustration, muted colours, wide composition';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'provider' => '',
            'provider_settings' => [],
            'post_types' => ['post'],
            'include_private' => false,
            'auto_mode' => 'off',
            'suggest_on_save' => false,
            'suggest_on_publish' => false,
            'template' => self::DEFAULT_TEMPLATE,
            'style' => self::DEFAULT_STYLE,
            'negative_prompt' => '',
            'summary_words' => 60,
            'tags_in_prompt' => 5,
            'prompt_limit' => 1000,
            'target_aspect' => '16:9',
            'target_width' => 1536,
            'target_height' => 864,
            'min_width' => 1024,
            'min_height' => 576,
            'min_ratio' => 1.3,
            'max_ratio' => 2.1,
            'crop' => false,
            'output_format' => 'keep',
            'quality' => 82,
            'max_mb' => 15,
            'max_mp' => 40,
            'timeout' => 90,
            'attempts' => 3,
            'quota_pause' => 21600,
            'day_cap' => 20,
            'post_cap' => 3,
            'batch_size' => 3,
            'batch_budget' => 120,
            'queue_age' => 172800,
            'on_failure' => 'none',
            'fallback_placeholder' => 0,
            'placeholder_ids' => [],
            'label_mode' => 'caption',
            'label_text' => 'AI-generated illustration',
            'alt_template' => 'Illustration: {title}',
            'pending_days' => 30,
            'retention_days' => 14,
            'row_retention_days' => 90,
            'hide_pending_in_modal' => true,
            'keep_on_uninstall' => false,
            'log_requests' => false,
        ];
    }

    /**
     * Current settings, read fresh from the database.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = Plugin::storage()->fresh_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        return array_merge(self::defaults(), $stored);
    }

    public static function get(string $key): mixed
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    /**
     * A setting read through get_option(), for front-end display code only
     * (labels). Never for a guard: the object cache may be stale.
     */
    public static function cached(string $key): mixed
    {
        $stored = get_option(self::OPTION, []);
        $all = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        return $all[$key] ?? null;
    }

    /**
     * Replace stored settings with a sanitised copy of the input.
     *
     * @param array<string, mixed> $input
     */
    public static function save(array $input): void
    {
        update_option(self::OPTION, self::sanitize($input), true);
    }

    /** True when every switch allows provider calls. Read fresh each time. */
    public static function enabled(): bool
    {
        if (Constants::get('FFP_DISABLED') === true) {
            return false;
        }
        $on = (bool) self::get('enabled');
        return (bool) apply_filters('ffp_enabled', $on);
    }

    /** Effective provider ID: the FFP_PROVIDER constant wins. */
    public static function provider_id(): string
    {
        $constant = Constants::get('FFP_PROVIDER');
        if (is_string($constant) && $constant !== '') {
            return $constant;
        }
        return (string) self::get('provider');
    }

    /** Effective per-day cap: the constant and filter can only lower it. */
    public static function day_cap(): int
    {
        $cap = (int) self::get('day_cap');
        $constant = Constants::get('FFP_DAY_CAP');
        if (is_int($constant) || (is_string($constant) && ctype_digit($constant))) {
            $cap = min($cap, max(0, (int) $constant));
        }
        $filtered = apply_filters('ffp_day_cap', $cap);
        if (is_int($filtered) && $filtered >= 0) {
            $cap = min($cap, $filtered);
        }
        return $cap;
    }

    /** Effective per-post cap: the filter can only lower it. */
    public static function post_cap(): int
    {
        $cap = (int) self::get('post_cap');
        $filtered = apply_filters('ffp_post_cap', $cap);
        if (is_int($filtered) && $filtered >= 1) {
            $cap = min($cap, $filtered);
        }
        return $cap;
    }

    /** @return array{0: int, 1: int} */
    public static function aspect_parts(?string $aspect = null): array
    {
        $aspect ??= (string) self::get('target_aspect');
        if (preg_match('/^(\d{1,5}):(\d{1,5})$/', $aspect, $m) === 1 && (int) $m[1] > 0 && (int) $m[2] > 0) {
            return [(int) $m[1], (int) $m[2]];
        }
        return [16, 9];
    }

    public static function target_ratio(): float
    {
        [$w, $h] = self::aspect_parts();
        return $w / $h;
    }

    /**
     * Validate and bound every field. Unknown keys are dropped.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function sanitize(array $in): array
    {
        $d = self::defaults();
        $out = [];

        $out['provider'] = isset($in['provider']) && is_string($in['provider'])
            && preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $in['provider']) === 1 ? $in['provider'] : '';
        // Enabled stays off until a provider is configured and saved.
        $out['enabled'] = !empty($in['enabled']) && $out['provider'] !== '';

        $out['provider_settings'] = [];
        if (isset($in['provider_settings']) && is_array($in['provider_settings'])) {
            foreach ($in['provider_settings'] as $pid => $fields) {
                if (!is_string($pid) || preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $pid) !== 1 || !is_array($fields)) {
                    continue;
                }
                foreach ($fields as $key => $value) {
                    if (is_string($key) && preg_match('/^[a-z0-9_]{1,40}$/', $key) === 1 && is_scalar($value)) {
                        $out['provider_settings'][$pid][$key] = self::clean_multiline((string) $value, 8000);
                    }
                }
            }
        }

        $types = isset($in['post_types']) && is_array($in['post_types']) ? $in['post_types'] : $d['post_types'];
        $out['post_types'] = array_values(array_unique(array_filter(
            array_map(static fn ($t): string => is_string($t) ? sanitize_key($t) : '', $types),
            static fn (string $t): bool => $t !== ''
        )));

        foreach (['include_private', 'suggest_on_save', 'suggest_on_publish', 'crop', 'hide_pending_in_modal', 'keep_on_uninstall', 'log_requests'] as $bool) {
            $out[$bool] = !empty($in[$bool]);
        }

        $out['auto_mode'] = self::choice($in, 'auto_mode', ['off', 'log', 'on'], 'off');
        $out['output_format'] = self::choice($in, 'output_format', ['keep', 'jpeg', 'webp'], 'keep');
        $out['on_failure'] = self::choice($in, 'on_failure', ['none', 'placeholder'], 'none');
        $out['label_mode'] = self::choice($in, 'label_mode', ['caption', 'append', 'both', 'off'], 'caption');

        $template = isset($in['template']) && is_string($in['template']) ? self::clean_multiline($in['template'], 4000) : '';
        $out['template'] = mb_strlen($template) >= 20 ? $template : $d['template'];
        $out['style'] = self::text($in, 'style', 300, $d['style']);
        $out['negative_prompt'] = isset($in['negative_prompt']) && is_string($in['negative_prompt'])
            ? self::clean_multiline($in['negative_prompt'], 1000) : '';
        $out['label_text'] = self::text($in, 'label_text', 100, $d['label_text']);
        $out['alt_template'] = self::text($in, 'alt_template', 250, $d['alt_template']);

        $aspect = isset($in['target_aspect']) && is_string($in['target_aspect']) ? trim($in['target_aspect']) : '';
        $parts = preg_match('/^(\d{1,5}):(\d{1,5})$/', $aspect, $m) === 1 && (int) $m[1] > 0 && (int) $m[2] > 0
            ? [(int) $m[1], (int) $m[2]] : null;
        $out['target_aspect'] = $parts !== null && $parts[0] / $parts[1] >= 0.25 && $parts[0] / $parts[1] <= 8.0
            ? $parts[0] . ':' . $parts[1] : $d['target_aspect'];

        $ints = [
            'summary_words' => [0, 200], 'tags_in_prompt' => [0, 15], 'prompt_limit' => [100, 4000],
            'target_width' => [256, 4096], 'target_height' => [256, 4096],
            'min_width' => [100, 8000], 'min_height' => [100, 8000],
            'quality' => [40, 100], 'max_mb' => [1, 50], 'max_mp' => [1, 100], 'timeout' => [10, 300],
            'attempts' => [1, 5], 'quota_pause' => [900, 604800], 'day_cap' => [0, 10000],
            'post_cap' => [1, 20], 'batch_size' => [1, 20], 'batch_budget' => [30, 600],
            'queue_age' => [3600, 2592000], 'pending_days' => [1, 365], 'retention_days' => [-1, 365],
            'row_retention_days' => [7, 3650],
        ];
        foreach ($ints as $key => [$min, $max]) {
            $out[$key] = self::bounded_int($in, $key, $min, $max, (int) $d[$key]);
        }

        $min_ratio = self::bounded_float($in, 'min_ratio', 0.25, 8.0, (float) $d['min_ratio']);
        $max_ratio = self::bounded_float($in, 'max_ratio', 0.25, 8.0, (float) $d['max_ratio']);
        if ($min_ratio >= $max_ratio) {
            $min_ratio = (float) $d['min_ratio'];
            $max_ratio = (float) $d['max_ratio'];
        }
        $out['min_ratio'] = $min_ratio;
        $out['max_ratio'] = $max_ratio;

        $out['fallback_placeholder'] = isset($in['fallback_placeholder']) && is_numeric($in['fallback_placeholder'])
            ? max(0, (int) $in['fallback_placeholder']) : 0;
        $ids = $in['placeholder_ids'] ?? [];
        if (is_string($ids)) {
            $ids = preg_split('/[\s,]+/', $ids) ?: [];
        }
        $ids = is_array($ids) ? $ids : [];
        $clean = [];
        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $clean[] = (int) $id;
            }
        }
        $out['placeholder_ids'] = array_values(array_unique($clean));

        return $out;
    }

    /**
     * @param array<string, mixed> $in
     * @param list<string> $allowed
     */
    private static function choice(array $in, string $key, array $allowed, string $default): string
    {
        $value = $in[$key] ?? null;
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /** @param array<string, mixed> $in */
    private static function text(array $in, string $key, int $max, string $default): string
    {
        if (!isset($in[$key]) || !is_string($in[$key])) {
            return $default;
        }
        $value = trim(sanitize_text_field($in[$key]));
        return mb_substr($value, 0, $max);
    }

    /** @param array<string, mixed> $in */
    private static function bounded_int(array $in, string $key, int $min, int $max, int $default): int
    {
        if (!isset($in[$key]) || !is_numeric($in[$key])) {
            return $default;
        }
        return max($min, min($max, (int) $in[$key]));
    }

    /** @param array<string, mixed> $in */
    private static function bounded_float(array $in, string $key, float $min, float $max, float $default): float
    {
        if (!isset($in[$key]) || !is_numeric($in[$key])) {
            return $default;
        }
        return max($min, min($max, (float) $in[$key]));
    }

    private static function clean_multiline(string $value, int $max): string
    {
        $value = str_replace("\0", '', $value);
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        return mb_substr(trim($value), 0, $max);
    }
}
