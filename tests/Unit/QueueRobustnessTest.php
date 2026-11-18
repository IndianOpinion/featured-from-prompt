<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Unit;

use IndianOpinion\FeaturedFromPrompt\Integrations;
use IndianOpinion\FeaturedFromPrompt\Queue;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\Settings;
use IndianOpinion\FeaturedFromPrompt\Suggestions;
use IndianOpinion\FeaturedFromPrompt\Tests\TestCase;
use IndianOpinion\FeaturedFromPrompt\Worker;
use WP;

/**
 * Cases 80 to 89.
 */
final class QueueRobustnessTest extends TestCase
{
    public function test_case_80_batch_size_and_site_lock(): void
    {
        $this->settings(['batch_size' => 3]);
        for ($i = 0; $i < 5; $i++) {
            $this->queue($this->post());
        }
        $this->assertNotFalse(wp_next_scheduled(Queue::CRON_HOOK));

        $this->assertTrue($this->storage->acquire_lock('batch', 600, $this->clock->now()));
        $locked = Queue::run_batch();
        $this->assertSame(Reason::LOCKED, $locked['status']);
        $this->assertSame(5, $this->storage->count_requests(['queued']));
        $this->storage->release_lock('batch');

        $first = Queue::run_batch();
        $this->assertSame(3, $first['ran']);
        $this->assertSame(2, $this->storage->count_requests(['queued']));
        Queue::run_batch();
        $this->assertSame(0, $this->storage->count_requests(['queued']));
        $this->assertFalse(wp_next_scheduled(Queue::CRON_HOOK), 'the minute event stops when the queue is empty');
    }

    public function test_case_81_gen_queue_integration(): void
    {
        add_filter('ffp_use_gen_queue', static fn () => true);
        $jobs = [];
        add_filter('gen_queue_enqueue_job', function ($result, $type, $payload, $args) use (&$jobs) {
            $jobs[] = ['type' => $type, 'payload' => $payload, 'args' => $args];
            return count($jobs);
        }, 10, 4);
        $post = $this->post();
        $result = $this->queue($post);
        $this->assertTrue($result->queued);
        $this->assertSame(Integrations::JOB_TYPE, $jobs[0]['type']);
        $this->assertSame(1, preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $jobs[0]['type']), 'a valid gen-queue type name');
        $this->assertSame(['request_id' => $result->request_id, 'post_id' => $post], $jobs[0]['payload']);
        $this->assertSame('ffp:' . $post . ':' . $result->request_id, $jobs[0]['args']['idempotency_key']);
        $row = $this->storage->get_request((string) $result->request_id);
        $this->assertSame('gen-queue', $row['runner']);
        $this->assertSame('1', $row['record']['job_id']);
        $this->assertSame(0, WP::$spawned);

        Queue::run_batch();
        $this->assertSame('queued', $this->storage->get_request((string) $result->request_id)['state'], 'the own runner leaves gen-queue jobs alone');

        // gen-queue calls the handler with its Job object and a context.
        $job = (object) ['payload' => ['request_id' => $result->request_id, 'post_id' => $post]];
        $this->settings(['enabled' => false]);
        $handled = Queue::handle_job($job, new \stdClass());
        $this->assertSame(Reason::DISABLED, $handled['reason'], 'guards run inside the handler');
        $this->assertSame('own', $this->storage->get_request((string) $result->request_id)['runner'], 'a parked job goes back to the own queue, never orphaned');
        $this->settings(['enabled' => true]);
        $handled = Queue::handle_job($job, new \stdClass());
        $this->assertSame(Reason::SUGGESTION_READY, $handled['reason']);

        remove_all_filters('gen_queue_enqueue_job');
        add_filter('gen_queue_enqueue_job', static fn () => null);
        $fallback = $this->queue($this->post());
        $this->assertSame('own', $this->storage->get_request((string) $fallback->request_id)['runner']);
        $this->assertTrue($this->has_log('gen-queue did not take the job'));

        // The type is registered on gen_queue_register_types.
        $this->assertNotFalse(has_filter('gen_queue_register_types'));
    }

    public function test_case_82_queue_age_limit_for_auto_only(): void
    {
        $this->settings(['auto_mode' => 'on', 'queue_age' => 7200]);
        $auto = (string) $this->queue($this->post(), ['manual' => false])->request_id;
        $manual = (string) $this->queue($this->post())->request_id;
        $this->clock->advance(7201);
        $this->assertSame(Reason::EXPIRED_IN_QUEUE, $this->run_request($auto)->reason);
        $this->assertSame(Reason::SUGGESTION_READY, $this->run_request($manual)->reason);
    }

    public function test_case_83_backfill_dry_run_and_markers(): void
    {
        $old = $this->post(['post_date' => '2026-10-01 10:00:00']);
        $new = $this->post(['post_date' => '2026-11-10 10:00:00']);
        $has_image = $this->post(['post_date' => '2026-11-05 10:00:00']);
        set_post_thumbnail($has_image, $this->image_attachment());
        $marked = $this->post(['post_date' => '2026-11-01 10:00:00']);
        update_post_meta($marked, Queue::MARKER_META, ['time' => 1, 'outcome' => 'failed']);

        $dry = Queue::backfill(['dry_run' => true]);
        $this->assertSame([$new, $has_image, $marked, $old], array_column($dry['candidates'], 'post_id'));
        $this->assertSame([Reason::WOULD_REQUEST, Reason::HAS_IMAGE, Reason::AUTO_ATTEMPTED, Reason::WOULD_REQUEST], array_column($dry['candidates'], 'reason'));
        $this->assertSame(0, $this->storage->count_requests([]));

        $forced = Queue::backfill(['dry_run' => true, 'force' => true]);
        $this->assertSame(Reason::WOULD_REQUEST, $forced['candidates'][2]['reason']);

        $ranged = Queue::backfill(['dry_run' => true, 'after' => '2026-11-01', 'before' => '2026-11-06']);
        $this->assertSame([$has_image, $marked], array_column($ranged['candidates'], 'post_id'));
    }

    public function test_case_84_backfill_respects_todays_cap_unless_spread(): void
    {
        $this->settings(['day_cap' => 2]);
        for ($i = 0; $i < 5; $i++) {
            $this->post();
        }
        $result = Queue::backfill([]);
        $this->assertSame(2, $result['queued']);
        $this->assertSame(2, $this->storage->count_requests(['queued']));
        $this->assertSame(Reason::CAP_DAY, $result['candidates'][4]['reason']);

        $spread = Queue::backfill(['spread' => true]);
        $this->assertSame(3, $spread['queued']);
        $this->assertSame(5, $this->storage->count_requests(['queued']));
    }

    public function test_case_85_accept_does_not_re_enter(): void
    {
        $this->settings(['suggest_on_save' => true, 'auto_mode' => 'on']);
        $post = $this->post();
        $id = $this->generate($post)->attachment_id();
        add_action('ffp_accepted', static function (int $post_id): void {
            // A cache plugin that re-saves the post on accept.
            do_action('save_post', $post_id, get_post($post_id), true);
        });
        $this->assertTrue(Suggestions::accept($id, self::EDITOR)->ok);
        $this->assertSame(1, $this->fired('ffp_accepted'));
        $this->assertSame(0, $this->storage->count_requests(['queued']));
    }

    public function test_case_86_idempotent_worker(): void
    {
        $record = $this->generate($this->post());
        $again = $this->run_request($record->id());
        $this->assertSame(Reason::ALREADY_DONE, $again->reason);
        $this->assertCount(1, $this->provider->requests);
    }

    public function test_case_87_lost_worker(): void
    {
        $id = (string) $this->queue($this->post())->request_id;
        $this->storage->reserve('day', $this->clock->day(), 20);
        $this->storage->update_request($id, ['state' => 'running', 'heartbeat' => $this->clock->now(), 'attempts' => 1]);
        $this->clock->advance(90 + 299);
        $this->assertSame(0, Worker::sweep_lost());
        $this->clock->advance(2);
        $this->assertSame(1, Worker::sweep_lost());
        $row = $this->storage->get_request($id);
        $this->assertSame(['failed', Reason::WORKER_LOST], [$row['state'], $row['reason']]);
        $this->assertSame(1, $this->storage->count('day', $this->clock->day()));
        Queue::run_batch();
        $this->assertSame([], $this->provider->requests, 'not retried');
    }

    public function test_case_88_stale_object_cache_does_not_hide_the_switch(): void
    {
        $id = (string) $this->queue($this->post())->request_id;
        $stale = Settings::all();
        $this->settings(['enabled' => false]);
        WP::$option_cache['1:' . Settings::OPTION] = $stale;
        $this->assertTrue((bool) get_option(Settings::OPTION)['enabled'], 'the cache is stale');
        $this->assertFalse(Settings::enabled());
        $this->assertSame(Reason::DISABLED, $this->run_request($id)->reason);
        $this->assertSame([], $this->provider->requests);
    }

    public function test_case_89_multisite_sites_are_independent(): void
    {
        WP::$multisite = true;
        $this->settings(['day_cap' => 1]);
        $this->generate($this->post());
        $this->assertSame(Reason::CAP_DAY, $this->generate($this->post())->reason);

        switch_to_blog(2);
        $this->settings(['day_cap' => 1]);
        $this->assertSame(0, $this->storage->count_requests([]));
        $this->assertSame(Reason::SUGGESTION_READY, $this->generate($this->post())->reason);
        restore_current_blog();
        $this->assertSame(1, $this->storage->count('day', $this->clock->day()));
    }
}
