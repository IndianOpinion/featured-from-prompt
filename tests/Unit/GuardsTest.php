<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Unit;

use IndianOpinion\FeaturedFromPrompt\Constants;
use IndianOpinion\FeaturedFromPrompt\Pauses;
use IndianOpinion\FeaturedFromPrompt\Provider\ConfigException;
use IndianOpinion\FeaturedFromPrompt\Provider\TransientException;
use IndianOpinion\FeaturedFromPrompt\Queue;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\Settings;
use IndianOpinion\FeaturedFromPrompt\Tests\TestCase;
use IndianOpinion\FeaturedFromPrompt\Worker;
use WP;

/**
 * Cases 25 to 34.
 */
final class GuardsTest extends TestCase
{
    public function test_case_25_kill_switch_three_ways(): void
    {
        $this->settings(['auto_mode' => 'on']);
        $post = $this->post();
        $id = (string) $this->queue($post)->request_id;

        $switches = [
            fn () => $this->settings(['enabled' => false]),
            fn () => Constants::override('FFP_DISABLED', true),
            fn () => add_filter('ffp_enabled', static fn (): bool => false),
        ];
        foreach ($switches as $i => $switch_off) {
            $this->settings(['enabled' => true]);
            Constants::reset();
            remove_all_filters('ffp_enabled');
            $switch_off();
            $this->assertFalse(Settings::enabled(), 'switch ' . $i);
            $record = $this->run_request($id);
            $this->assertSame(Reason::DISABLED, $record->reason);
            $this->assertSame('queued', $this->storage->get_request($id)['state']);
            $this->assertSame([], $this->provider->requests);

            $other = $this->post();
            $trigger = $this->queue($other, ['manual' => false]);
            $this->assertSame(Reason::DISABLED, $trigger->reason);
            $this->assertSame(Reason::DISABLED, get_post_meta($other, Queue::LAST_META, true)['reason']);
        }
    }

    public function test_case_26_provider_not_configured_and_unknown(): void
    {
        $post = $this->post();
        $this->provider->configured = false;
        $this->assertSame(Reason::PROVIDER_NOT_CONFIGURED, $this->generate($post)->reason);

        $this->settings(['provider' => 'nobody']);
        $this->assertSame(Reason::NO_PROVIDER, $this->generate($this->post())->reason);
        $this->assertSame([], $this->provider->requests);
    }

    public function test_case_27_paused_provider_leaves_requests_queued_uncounted(): void
    {
        Pauses::pause('fake', Reason::AUTH_FAILED, 0);
        $post = $this->post();
        $record = $this->generate($post);
        $this->assertSame(Reason::PROVIDER_PAUSED, $record->reason);
        $this->assertSame('queued', $record->state());
        $this->assertSame(0, $this->storage->count('day', $this->clock->day()));
        Pauses::resume('fake');
        $this->clock->advance(600);
        $this->assertSame(Reason::SUGGESTION_READY, $this->run_request($record->id())->reason);
    }

    public function test_case_28_post_trashed_or_got_image_after_queuing(): void
    {
        $post = $this->post();
        $id = (string) $this->queue($post)->request_id;
        WP::$posts[$post]->post_status = 'trash';
        $this->assertSame(Reason::POST_GONE, $this->run_request($id)->reason);

        $this->settings(['auto_mode' => 'on']);
        $other = $this->post();
        $auto = (string) $this->queue($other, ['manual' => false])->request_id;
        set_post_thumbnail($other, $this->image_attachment());
        $this->assertSame(Reason::HAS_IMAGE, $this->run_request($auto)->reason);
        $this->assertSame([], $this->provider->requests);
    }

    public function test_case_29_per_post_cap(): void
    {
        $this->settings(['post_cap' => 3, 'day_cap' => 100]);
        $post = $this->post();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(Reason::SUGGESTION_READY, $this->generate($post)->reason);
        }
        $fourth = $this->generate($post);
        $this->assertSame(Reason::CAP_POST, $fourth->reason);
        $this->assertSame('skipped', $fourth->state());
        $this->assertCount(3, $this->provider->requests);
        $this->assertSame(1, $this->fired('ffp_cap_reached'));
    }

    public function test_case_30_day_cap_waits_for_the_next_site_day_across_dst(): void
    {
        WP::$tz = 'Europe/London';
        // 2026-10-24 23:30 BST; the clocks go back at 02:00 on 25 October.
        $this->clock->time = (new \DateTimeImmutable('2026-10-24 23:30:00', new \DateTimeZone('Europe/London')))->getTimestamp();
        $this->settings(['day_cap' => 2]);
        $ids = [];
        foreach ([1, 2, 3] as $n) {
            $ids[] = (string) $this->queue($this->post())->request_id;
        }
        $this->run_request($ids[0]);
        $this->run_request($ids[1]);
        $third = $this->run_request($ids[2]);
        $this->assertSame(Reason::CAP_DAY, $third->reason);
        $this->assertSame('queued', $third->state());
        $midnight = (new \DateTimeImmutable('2026-10-25 00:00:00', new \DateTimeZone('Europe/London')))->getTimestamp();
        $this->assertSame($midnight, (int) $this->storage->get_request($ids[2])['next_run']);

        $this->clock->time = $midnight + 30;
        $this->assertSame('2026-10-25', $this->clock->day());
        $batch = Queue::run_batch();
        $this->assertSame(1, $batch['ran']);
        $this->assertSame('done', $this->storage->get_request($ids[2])['state']);

        // The next midnight is 25 hours later because of the change.
        $this->assertSame($midnight + 25 * 3600, $this->clock->next_day_start($midnight + 60));

        $this->settings(['day_cap' => 0]);
        $none = $this->generate($this->post());
        $this->assertSame(Reason::CAP_DAY, $none->reason);
        $this->assertCount(3, $this->provider->requests);
    }

    public function test_case_31_reservation_never_overshoots(): void
    {
        $this->settings(['day_cap' => 5]);
        $day = $this->clock->day();
        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($this->storage->reserve('day', $day, 5));
        }
        $results = [$this->storage->reserve('day', $day, 5), $this->storage->reserve('day', $day, 5)];
        $this->assertSame([true, false], $results);
        $this->assertSame(5, $this->storage->count('day', $day));
        // The concurrent version runs against MySQL in tests/Integration.
    }

    public function test_case_32_refund_only_when_nothing_was_sent(): void
    {
        $post = $this->post();
        $this->provider->push(new ConfigException('bad template'));
        $record = $this->generate($post);
        $this->assertSame(Reason::CONFIG_ERROR, $record->reason);
        $this->assertSame(0, $this->storage->count('day', $this->clock->day()));

        $this->settings(['attempts' => 1]);
        $this->provider->push(TransientException::timeout());
        $record = $this->generate($this->post());
        $this->assertSame(Reason::TIMEOUT, $record->reason);
        $this->assertSame(1, $this->storage->count('day', $this->clock->day()));
    }

    public function test_case_33_spend_meter_is_a_second_gate(): void
    {
        $calls = ['record' => 0, 'release' => 0, 'intent' => null, 'call' => null, 'released' => null];
        add_action('spend_meter_record', function ($call) use (&$calls): void {
            $calls['record']++;
            $calls['call'] = $call;
        });
        add_action('spend_meter_release', function ($reservation_id) use (&$calls): void {
            $calls['release']++;
            $calls['released'] = $reservation_id;
        });

        // No meter: own caps decide.
        $this->assertSame(Reason::SUGGESTION_READY, $this->generate($this->post())->reason);
        $this->assertSame(0, $calls['record'] + $calls['release']);

        // Deny: ends spend_denied with the meter reason, refunds.
        add_filter('spend_meter_pre_spend', function ($decision, array $intent) use (&$calls) {
            $calls['intent'] = $intent;
            return ['allowed' => false, 'reason' => 'budget_month'];
        }, 10, 2);
        $used = $this->storage->count('day', $this->clock->day());
        $denied = $this->generate($this->post());
        $this->assertSame(Reason::SPEND_DENIED, $denied->reason);
        $this->assertSame('budget_month', $denied->row['record']['spend_reason']);
        $this->assertSame($used, $this->storage->count('day', $this->clock->day()));
        $this->assertSame('featured_image', $calls['intent']['purpose']);
        $this->assertSame('featured-from-prompt', $calls['intent']['caller']);
        $this->assertSame(1, $calls['intent']['est_units']);
        $this->assertSame('fake', $calls['intent']['provider']);
        $this->assertNotSame('', $calls['intent']['model'], 'spend-meter requires a model');
        $this->assertSame('fake', \IndianOpinion\FeaturedFromPrompt\Integrations::spend_intent('fake', '')['model'], 'no model setting sends the provider id');
        $this->assertSame(1, preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $calls['intent']['purpose']));

        // Allow, but the own cap is reached: cap_day, and the meter is never asked.
        remove_all_filters('spend_meter_pre_spend');
        add_filter('spend_meter_pre_spend', static fn () => ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'res-1']);
        $this->settings(['day_cap' => $used]);
        $this->assertSame(Reason::CAP_DAY, $this->generate($this->post())->reason);

        // Allowed calls: record once on a sent call, release once on a refund.
        $this->settings(['day_cap' => 100]);
        $this->generate($this->post());
        $this->assertSame(1, $calls['record']);
        $this->assertSame('res-1', $calls['call']['reservation_id']);
        $this->assertSame('ok', $calls['call']['status']);
        $this->assertSame('featured_image', $calls['call']['purpose']);
        $this->assertSame('featured-from-prompt', $calls['call']['caller']);
        $this->assertSame(0, $calls['release']);
        $this->provider->push(new ConfigException('x'));
        $this->generate($this->post());
        $this->assertSame(1, $calls['release']);
        $this->assertSame('res-1', $calls['released']);
        $this->assertSame(1, $calls['record']);
        // A sent call that failed is still recorded, as billed or not.
        $this->provider->push(TransientException::timeout());
        $this->generate($this->post());
        $this->assertSame(2, $calls['record']);
        $this->assertSame('error_billed', $calls['call']['status']);
        $this->assertSame(1, $calls['release']);

        // A non-true "allowed" is a deny.
        remove_all_filters('spend_meter_pre_spend');
        add_filter('spend_meter_pre_spend', static fn () => ['allowed' => 1]);
        $this->assertSame(Reason::SPEND_DENIED, $this->generate($this->post())->reason);
    }

    public function test_case_34_locked_post_stays_queued_uncounted(): void
    {
        $post = $this->post();
        $id = (string) $this->queue($post)->request_id;
        $this->assertTrue($this->storage->acquire_lock('post_' . $post, 600, $this->clock->now()));
        $record = $this->run_request($id);
        $this->assertSame(Reason::LOCKED, $record->reason);
        $this->assertSame('queued', $this->storage->get_request($id)['state']);
        $this->assertSame(0, $this->storage->count('day', $this->clock->day()));
        $this->assertSame([], $this->provider->requests);
        $this->assertNull(Worker::dry_guards($post, false));
    }
}
