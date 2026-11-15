<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Automatic triggers (cases 6 to 8). Each only inserts a queue row.
 */
final class Triggers
{
    public static function register(): void
    {
        add_action('save_post', [self::class, 'on_save'], 20, 3);
        add_action('transition_post_status', [self::class, 'on_transition'], 20, 3);
        add_action('ffp_request', [self::class, 'on_action'], 10, 2);
    }

    /** @param mixed $post */
    public static function on_save(int $post_id, $post = null, bool $update = false): void
    {
        if (Guard::active() || !(bool) Settings::get('suggest_on_save')) {
            return;
        }
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        $post = $post instanceof \WP_Post ? $post : get_post($post_id);
        if (!$post instanceof \WP_Post || trim($post->post_title) === '') {
            return;
        }
        Guard::run(static fn () => Queue::request($post_id, ['manual' => false, 'trigger' => 'save', 'source' => 'save']));
    }

    /** @param mixed $post */
    public static function on_transition(string $new_status, string $old_status, $post): void
    {
        if (Guard::active() || !$post instanceof \WP_Post || !(bool) Settings::get('suggest_on_publish')) {
            return;
        }
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        if (wp_is_post_autosave($post->ID) || wp_is_post_revision($post->ID) || trim($post->post_title) === '') {
            return;
        }
        Guard::run(static fn () => Queue::request($post->ID, ['manual' => false, 'trigger' => 'publish', 'source' => 'publish']));
    }

    /**
     * do_action('ffp_request', $post_id, $args): queue an auto request.
     *
     * @param mixed $post_id
     * @param mixed $args
     */
    public static function on_action($post_id, $args = []): void
    {
        $args = is_array($args) ? $args : [];
        $request = [
            'manual' => false,
            'trigger' => 'external',
            'source' => isset($args['source']) && is_string($args['source']) ? $args['source'] : 'external',
        ];
        if (isset($args['prompt']) && is_string($args['prompt'])) {
            $request['prompt'] = $args['prompt'];
        }
        if (isset($args['priority']) && is_numeric($args['priority'])) {
            $request['priority'] = (int) $args['priority'];
        }
        Queue::request(is_numeric($post_id) ? (int) $post_id : 0, $request);
    }
}
