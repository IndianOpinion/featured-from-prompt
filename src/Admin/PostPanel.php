<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Admin;

use IndianOpinion\FeaturedFromPrompt\Rest;
use IndianOpinion\FeaturedFromPrompt\Settings;

/**
 * The post screen panel: a block editor document panel and a classic
 * editor meta box with the same content (5.8). Both are drawn by
 * assets/panel.js from the status REST route.
 */
final class PostPanel
{
    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'meta_box'], 10, 2);
        add_action('enqueue_block_editor_assets', [self::class, 'block_editor_assets']);
        add_action('admin_enqueue_scripts', [self::class, 'classic_assets']);
    }

    private static function enabled_for(string $post_type): bool
    {
        return in_array($post_type, (array) Settings::get('post_types'), true) && post_type_supports($post_type, 'thumbnail');
    }

    /** @param mixed $post */
    public static function meta_box(string $post_type, $post = null): void
    {
        if (!self::enabled_for($post_type)) {
            return;
        }
        add_meta_box(
            'ffp-panel',
            'Featured image suggestion',
            [self::class, 'render_box'],
            $post_type,
            'side',
            'low',
            ['__back_compat_meta_box' => true]
        );
    }

    public static function render_box(\WP_Post $post): void
    {
        echo '<div class="ffp-panel" data-ffp-post="' . esc_attr((string) $post->ID) . '"></div>';
    }

    public static function block_editor_assets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $type = $screen instanceof \WP_Screen ? (string) $screen->post_type : '';
        if ($type === '' || !self::enabled_for($type)) {
            return;
        }
        self::enqueue(['wp-plugins', 'wp-element', 'wp-data', 'wp-api-fetch', 'wp-edit-post']);
    }

    public static function classic_assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (
            !$screen instanceof \WP_Screen || !self::enabled_for((string) $screen->post_type)
            || $screen->is_block_editor()
        ) {
            return;
        }
        self::enqueue(['wp-api-fetch']);
    }

    /** @param list<string> $deps */
    private static function enqueue(array $deps): void
    {
        $base = plugins_url('assets/', FFP_FILE);
        wp_enqueue_script('ffp-panel', $base . 'panel.js', $deps, FFP_VERSION, true);
        wp_enqueue_style('ffp-panel', $base . 'panel.css', [], FFP_VERSION);
        wp_localize_script('ffp-panel', 'ffpPanel', [
            'ns' => Rest::NS,
            'pollMs' => 5000,
            'pollMax' => 300000,
            'canGenerate' => current_user_can(Rest::generate_capability()) && current_user_can('upload_files'),
        ]);
    }
}
