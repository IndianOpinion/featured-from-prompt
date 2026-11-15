<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Admin;

use IndianOpinion\FeaturedFromPrompt\Settings;
use IndianOpinion\FeaturedFromPrompt\Suggestions;

/**
 * Media library: a "Generated, pending review" filter, a review state
 * column, and pending suggestions kept out of the media picker.
 */
final class MediaLibrary
{
    public static function register(): void
    {
        add_filter('ajax_query_attachments_args', [self::class, 'hide_pending']);
        add_action('restrict_manage_posts', [self::class, 'filter_control']);
        add_action('pre_get_posts', [self::class, 'apply_filter']);
        add_filter('manage_media_columns', [self::class, 'columns']);
        add_action('manage_media_custom_column', [self::class, 'column'], 10, 2);
    }

    /**
     * @param mixed $query
     * @return array<string, mixed>
     */
    public static function hide_pending($query): array
    {
        $query = is_array($query) ? $query : [];
        if (!(bool) Settings::get('hide_pending_in_modal')) {
            return $query;
        }
        $clause = [
            'relation' => 'OR',
            ['key' => Suggestions::META_STATE, 'compare' => 'NOT EXISTS'],
            ['key' => Suggestions::META_STATE, 'value' => 'pending', 'compare' => '!='],
        ];
        $existing = isset($query['meta_query']) && is_array($query['meta_query']) ? $query['meta_query'] : [];
        $query['meta_query'] = $existing === [] ? [$clause] : ['relation' => 'AND', $existing, $clause];
        return $query;
    }

    public static function filter_control(string $post_type = ''): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen instanceof \WP_Screen || $screen->id !== 'upload') {
            return;
        }
        $current = isset($_GET['ffp_review']) && is_string($_GET['ffp_review']) ? sanitize_key(wp_unslash($_GET['ffp_review'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
        echo '<select name="ffp_review"><option value="">' . esc_html('All images') . '</option>';
        foreach (['pending' => 'Generated, pending review', 'accepted' => 'Generated, accepted', 'rejected' => 'Generated, rejected'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    /** @param mixed $query */
    public static function apply_filter($query): void
    {
        if (!$query instanceof \WP_Query || !is_admin() || !$query->is_main_query()) {
            return;
        }
        $state = isset($_GET['ffp_review']) && is_string($_GET['ffp_review']) ? sanitize_key(wp_unslash($_GET['ffp_review'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
        if (!in_array($state, Suggestions::STATES, true) || $query->get('post_type') !== 'attachment') {
            return;
        }
        $query->set('meta_key', Suggestions::META_STATE);
        $query->set('meta_value', $state);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function columns(array $columns): array
    {
        $columns['ffp_state'] = 'Review';
        return $columns;
    }

    public static function column(string $name, int $attachment_id): void
    {
        if ($name !== 'ffp_state') {
            return;
        }
        echo esc_html(Suggestions::is_generated($attachment_id) ? 'Generated: ' . Suggestions::state($attachment_id) : '');
    }
}
