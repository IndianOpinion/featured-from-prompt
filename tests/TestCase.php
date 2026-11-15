<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests;

use IndianOpinion\FeaturedFromPrompt\Constants;
use IndianOpinion\FeaturedFromPrompt\Logger;
use IndianOpinion\FeaturedFromPrompt\Plugin;
use IndianOpinion\FeaturedFromPrompt\Queue;
use IndianOpinion\FeaturedFromPrompt\Registry;
use IndianOpinion\FeaturedFromPrompt\RequestRecord;
use IndianOpinion\FeaturedFromPrompt\RequestResult;
use IndianOpinion\FeaturedFromPrompt\Settings;
use IndianOpinion\FeaturedFromPrompt\Tests\Support\FakeProvider;
use IndianOpinion\FeaturedFromPrompt\Tests\Support\FrozenClock;
use IndianOpinion\FeaturedFromPrompt\Tests\Support\MemoryStorage;
use IndianOpinion\FeaturedFromPrompt\Worker;
use WP;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected MemoryStorage $storage;
    protected FrozenClock $clock;
    protected FakeProvider $provider;
    protected Registry $registry;
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    protected array $logs = [];
    /** @var array<string, list<list<mixed>>> */
    protected array $fired = [];

    protected const EDITOR = 1;

    protected function setUp(): void
    {
        WP::reset();
        Constants::reset();
        \WP_CLI::reset();
        self::remove_tree(WP::$root . '/wp-content');
        self::remove_tree(WP::$root . '/uploads');

        $this->storage = new MemoryStorage();
        $this->clock = new FrozenClock();
        $this->registry = new Registry();
        $this->provider = new FakeProvider();
        Plugin::set_instance(new Plugin($this->storage, $this->clock, $this->registry, new Logger()));
        Plugin::boot();
        add_action('ffp_register_providers', function (Registry $r): void {
            $r->register($this->provider);
        });

        WP::add_user(self::EDITOR, ['edit_post', 'upload_files', 'edit_others_posts', 'manage_options'], 'editor@example.org');
        WP::$current_user = self::EDITOR;
        $this->settings([]);

        $this->logs = [];
        add_action('ffp_log', function (string $message, array $context = []): void {
            $this->logs[] = [$message, $context];
        }, 10, 2);
        foreach (['ffp_accepted', 'ffp_rejected', 'ffp_suggestion_ready', 'ffp_placeholder_set', 'ffp_provider_paused', 'ffp_request_finished', 'ffp_request_queued', 'ffp_cap_reached'] as $hook) {
            add_action($hook, function (...$args) use ($hook): void {
                $this->fired[$hook][] = $args;
            }, 10, 5);
        }
    }

    protected function tearDown(): void
    {
        Plugin::set_instance(null);
        Constants::reset();
    }

    /** @param array<string, mixed> $overrides */
    protected function settings(array $overrides): void
    {
        $current = get_option(Settings::OPTION, []);
        $base = array_merge(Settings::defaults(), ['enabled' => true, 'provider' => 'fake'], is_array($current) ? $current : [], $overrides);
        update_option(Settings::OPTION, Settings::sanitize($base));
    }

    /** @param array<string, mixed> $data */
    protected function post(array $data = []): int
    {
        return WP::add_post(array_merge([
            'post_title' => 'Rain returns to the plains',
            'post_content' => '<p>Farmers welcome the early monsoon after a long dry spell.</p>',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $data));
    }

    protected function image_attachment(int $parent = 0, string $mime = 'image/png'): int
    {
        return WP::add_post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $mime, 'post_parent' => $parent]);
    }

    /** @param array<string, mixed> $args */
    protected function queue(int $post_id, array $args = []): RequestResult
    {
        return Queue::request($post_id, $args);
    }

    protected function run_request(string $id): RequestRecord
    {
        return Worker::run($id);
    }

    /** Queue a manual request and run it. */
    protected function generate(int $post_id, array $args = []): RequestRecord
    {
        $result = $this->queue($post_id, $args);
        $this->assertTrue($result->queued, 'queue failed: ' . $result->reason);
        return $this->run_request((string) $result->request_id);
    }

    protected function fired(string $hook): int
    {
        return count($this->fired[$hook] ?? []);
    }

    protected function has_log(string $needle): bool
    {
        foreach ($this->logs as [$message]) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    protected static function remove_tree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
