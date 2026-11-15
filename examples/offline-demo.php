<?php

/**
 * Runnable without WordPress: php examples/offline-demo.php
 *
 * Uses the test stub layer and the fixture provider, so it makes no network
 * call. It queues a request for a post, runs it, shows the suggestion, and
 * accepts it as an editor. Run `composer install` first.
 */

// phpcs:disable PSR1.Files.SideEffects

declare(strict_types=1);

require dirname(__DIR__) . '/tests/bootstrap.php';

use IndianOpinion\FeaturedFromPrompt\Logger;
use IndianOpinion\FeaturedFromPrompt\Plugin;
use IndianOpinion\FeaturedFromPrompt\Registry;
use IndianOpinion\FeaturedFromPrompt\Settings;
use IndianOpinion\FeaturedFromPrompt\Tests\Support\FrozenClock;
use IndianOpinion\FeaturedFromPrompt\Tests\Support\MemoryStorage;

use function IndianOpinion\FeaturedFromPrompt\accept;
use function IndianOpinion\FeaturedFromPrompt\preview_prompt;
use function IndianOpinion\FeaturedFromPrompt\request;
use function IndianOpinion\FeaturedFromPrompt\run_request;
use function IndianOpinion\FeaturedFromPrompt\status;

Plugin::set_instance(new Plugin(new MemoryStorage(), new FrozenClock(time()), new Registry(), new Logger()));
Plugin::boot();
update_option(Settings::OPTION, Settings::sanitize(['enabled' => true, 'provider' => 'fixture']));
WP::add_user(1, ['edit_post', 'upload_files', 'edit_others_posts']);
WP::$current_user = 1;
$post = WP::add_post(['post_title' => 'Night trains return to the coast', 'post_excerpt' => 'Two overnight services restart next month.']);

echo "Prompt:\n  " . preview_prompt($post)->prompt . "\n\n";

$queued = request($post);
echo 'Queued: ' . ($queued->request_id ?? $queued->reason) . "\n";

$record = run_request((string) $queued->request_id);
echo 'Run: ' . $record->state() . ' / ' . $record->reason . ' / attachment ' . $record->attachment_id() . "\n";

$status = status($post);
foreach ($status['suggestions'] as $s) {
    echo sprintf("Suggestion %d: %dx%d from %s\n", $s['id'], $s['width'], $s['height'], $s['provider']);
}
echo 'Featured image before Accept: ' . ((int) get_post_meta($post, '_thumbnail_id', true) ?: 'none') . "\n";

$accepted = accept($record->attachment_id(), 1);
echo 'Accept: ' . ($accepted->ok ? 'ok' : $accepted->reason) . "\n";
echo 'Featured image after Accept: ' . (int) get_post_meta($post, '_thumbnail_id', true) . "\n";
echo 'Requests left today: ' . status($post)['caps']['day_left'] . "\n";
