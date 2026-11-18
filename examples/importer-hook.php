<?php

/**
 * Ask for a suggestion when another plugin creates a post without a picture.
 *
 * The action only queues an automatic request; it never calls the provider
 * in the importer's process. Automatic requests obey auto_mode, the daily
 * cap, the kill switch and the one-attempt marker, and an editor still has
 * to accept the result.
 */

// phpcs:disable PSR1.Files.SideEffects

declare(strict_types=1);

add_action('my_importer_post_created', static function (int $post_id): void {
    if (has_post_thumbnail($post_id)) {
        return;
    }
    do_action('ffp_request', $post_id, ['source' => 'my-importer', 'priority' => 0]);
});

// Purge anything derived from the old picture when an editor accepts one.
add_action('ffp_accepted', static function (int $post_id, int $attachment_id, int $previous_id): void {
    clean_post_cache($post_id);
}, 10, 3);
