<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Visible labels on generated images (cases 72 to 75).
 */
final class Labels
{
    public const CSS_CLASS = 'ffp-label';

    public static function register(): void
    {
        add_filter('post_thumbnail_html', [self::class, 'thumbnail_html'], 20, 5);
        add_action('rest_api_init', [self::class, 'rest_fields']);
    }

    /**
     * @param mixed $html
     * @param mixed $size
     * @param mixed $attr
     */
    public static function thumbnail_html($html, int $post_id, int $thumbnail_id, $size = null, $attr = null): string
    {
        $html = is_string($html) ? $html : '';
        if ($html === '' || !Suggestions::is_generated($thumbnail_id)) {
            return $html;
        }
        $mode = (string) Settings::cached('label_mode');
        if (!in_array($mode, ['append', 'both'], true)) {
            return $html;
        }
        if (!self::context_allows($post_id)) {
            return $html;
        }
        $text = (string) Settings::cached('label_text');
        if (
            $text === '' || str_contains($html, self::CSS_CLASS) || str_contains(wp_strip_all_tags($html), $text)
            || str_contains($html, esc_html($text))
        ) {
            return $html;
        }
        $label = '<p class="' . self::CSS_CLASS . '">' . esc_html($text) . '</p>';
        $label = apply_filters('ffp_label_html', $label, $thumbnail_id, $post_id);
        return $html . (is_string($label) ? $label : '');
    }

    /** Front-end single view of this post only, unless the filter widens it. */
    private static function context_allows(int $post_id): bool
    {
        $never = is_admin() || is_feed() || (function_exists('get_query_var') && get_query_var('sitemap') !== '' && get_query_var('sitemap') !== null);
        if ($never) {
            return false;
        }
        $default = is_singular() && (int) get_queried_object_id() === $post_id;
        return (bool) apply_filters('ffp_label_context', $default, $post_id);
    }

    public static function rest_fields(): void
    {
        register_rest_field('attachment', 'ffp_generated', [
            'get_callback' => static fn (array $item): bool => Suggestions::is_generated((int) ($item['id'] ?? 0)),
            'schema' => ['type' => 'boolean', 'context' => ['view', 'edit', 'embed'], 'readonly' => true],
        ]);
        register_rest_field('attachment', 'ffp_review_state', [
            'get_callback' => static fn (array $item): string => Suggestions::state((int) ($item['id'] ?? 0)),
            'schema' => ['type' => 'string', 'context' => ['view', 'edit', 'embed'], 'readonly' => true],
        ]);
    }
}
