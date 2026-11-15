<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Unit;

use IndianOpinion\FeaturedFromPrompt\Eligibility;
use IndianOpinion\FeaturedFromPrompt\Queue;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\Rest;
use IndianOpinion\FeaturedFromPrompt\Tests\TestCase;
use WP;

/**
 * Cases 1 to 11.
 */
final class EligibilityTriggersTest extends TestCase
{
    public function test_case_01_eligible_post_and_each_condition_broken_alone(): void
    {
        $post = $this->post();
        $this->assertNull(Eligibility::check($post, false));

        $this->settings(['enabled' => false]);
        $this->assertSame(Reason::DISABLED, Eligibility::check($post, false));
        $this->settings(['enabled' => true]);

        $page = $this->post(['post_type' => 'page']);
        $this->assertSame(Reason::POST_TYPE_NOT_ENABLED, Eligibility::check($page, false));

        $this->settings(['post_types' => ['post', 'note']]);
        $note = $this->post(['post_type' => 'note']);
        $this->assertSame(Reason::NO_THUMBNAIL_SUPPORT, Eligibility::check($note, false));

        $this->assertSame(Reason::POST_GONE, Eligibility::check($this->post(['post_status' => 'trash']), false));
        $this->assertSame(Reason::POST_GONE, Eligibility::check($this->post(['post_status' => 'auto-draft']), false));

        $this->assertSame(Reason::POST_PROTECTED, Eligibility::check($this->post(['post_password' => 'x']), false));
        $private = $this->post(['post_status' => 'private']);
        $this->assertSame(Reason::POST_PROTECTED, Eligibility::check($private, false));
        $this->settings(['include_private' => true]);
        $this->assertNull(Eligibility::check($private, false));

        update_post_meta($post, Eligibility::OPT_OUT_META, '1');
        $this->assertSame(Reason::POST_OPTED_OUT, Eligibility::check($post, false));
        delete_post_meta($post, Eligibility::OPT_OUT_META);

        add_filter('ffp_should_process', static fn (bool $ok, int $id): bool => $id !== $post, 10, 2);
        $this->assertSame(Reason::FILTERED_OUT, Eligibility::check($post, false));
    }

    public function test_case_02_auto_refused_with_real_image_allowed_with_placeholder(): void
    {
        $post = $this->post();
        $image = $this->image_attachment();
        set_post_thumbnail($post, $image);
        $this->assertSame(Reason::HAS_IMAGE, Eligibility::check($post, true));
        $this->assertNull(Eligibility::check($post, false), 'manual allowed with a real image');

        $this->settings(['placeholder_ids' => [$image]]);
        $this->assertNull(Eligibility::check($post, true), 'auto allowed on a placeholder');
        $this->assertNull(Eligibility::check($post, false));
    }

    public function test_case_03_missing_or_non_image_featured_counts_as_none(): void
    {
        $post = $this->post();
        update_post_meta($post, '_thumbnail_id', 99999);
        $this->assertNull(Eligibility::check($post, true));

        $pdf = $this->image_attachment(0, 'application/pdf');
        update_post_meta($post, '_thumbnail_id', $pdf);
        $this->assertNull(Eligibility::check($post, true));
    }

    public function test_case_04_each_ineligibility_reason_is_returned_by_request(): void
    {
        $post = $this->post(['post_password' => 'secret']);
        $this->assertSame(Reason::POST_PROTECTED, $this->queue($post)->reason);
        $this->assertSame(Reason::POST_GONE, $this->queue(424242)->reason);
        $with_image = $this->post();
        set_post_thumbnail($with_image, $this->image_attachment());
        $this->settings(['auto_mode' => 'on']);
        $this->assertSame(Reason::HAS_IMAGE, $this->queue($with_image, ['manual' => false])->reason);
    }

    public function test_case_05_generate_route_creates_one_manual_request(): void
    {
        $post = $this->post();
        $request = new \WP_REST_Request('POST', '/featured-from-prompt/v1/posts/' . $post . '/generate', ['id' => $post]);
        $this->assertTrue(Rest::can_generate($request));
        $response = Rest::generate($request);
        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(202, $response->get_status());
        $rows = $this->storage->list_requests(['queued'], 10);
        $this->assertCount(1, $rows);
        $this->assertSame('manual', $rows[0]['trigger_type']);
        $this->assertSame(self::EDITOR, $rows[0]['user_id']);
        $this->assertSame(1, WP::$spawned, 'spawn_cron after a manual request');
    }

    public function test_case_06_suggest_on_save(): void
    {
        $this->settings(['suggest_on_save' => true, 'auto_mode' => 'on']);
        $post = $this->post();
        do_action('save_post', $post, get_post($post), true);
        $this->assertSame(1, $this->storage->count_requests(['queued']));

        $autosave = WP::add_post(['post_type' => 'revision', 'post_name' => $post . '-autosave-v1', 'post_parent' => $post, 'post_title' => 'x']);
        do_action('save_post', $autosave, get_post($autosave), true);
        $revision = WP::add_post(['post_type' => 'revision', 'post_name' => $post . '-revision-v1', 'post_parent' => $post, 'post_title' => 'x']);
        do_action('save_post', $revision, get_post($revision), true);
        do_action('save_post', $post, get_post($post), true);
        $this->assertSame(1, $this->storage->count_requests(['queued']));

        $untitled = $this->post(['post_title' => '']);
        do_action('save_post', $untitled, get_post($untitled), true);
        $this->assertSame(1, $this->storage->count_requests(['queued']));
    }

    public function test_case_07_suggest_on_publish_queues_and_never_blocks(): void
    {
        $this->settings(['suggest_on_publish' => true, 'auto_mode' => 'on']);
        $post = $this->post(['post_status' => 'publish']);
        do_action('transition_post_status', 'publish', 'draft', get_post($post));
        $this->assertSame(1, $this->storage->count_requests(['queued']));
        $this->assertSame('publish', get_post($post)?->post_status);
        $this->assertSame([], $this->provider->requests, 'no provider call on publish');
        do_action('transition_post_status', 'publish', 'publish', get_post($post));
        $this->assertSame(1, $this->storage->count_requests(['queued']));
    }

    public function test_case_08_ffp_request_action_queues_without_calling(): void
    {
        $this->settings(['auto_mode' => 'on']);
        $post = $this->post();
        do_action('ffp_request', $post, []);
        $rows = $this->storage->list_requests(['queued'], 10);
        $this->assertCount(1, $rows);
        $this->assertSame('external', $rows[0]['trigger_type']);
        $this->assertSame([], $this->provider->requests);
        $this->assertSame(0, WP::$spawned);
    }

    public function test_case_09_one_auto_attempt_per_post(): void
    {
        $this->settings(['auto_mode' => 'on']);
        $post = $this->post();
        $this->provider->push(new \IndianOpinion\FeaturedFromPrompt\Provider\PromptRefusedException('no'));
        $first = $this->queue($post, ['manual' => false]);
        $this->run_request((string) $first->request_id);
        $this->assertTrue(Queue::has_marker($post));

        $this->assertSame(Reason::AUTO_ATTEMPTED, $this->queue($post, ['manual' => false])->reason);
        $this->assertTrue($this->queue($post, ['manual' => false, 'force' => true, 'trigger' => 'backfill'])->queued);
        Queue::cancel((string) $this->storage->active_request_for_post($post)['id']);
        $this->assertTrue($this->queue($post, ['manual' => true])->queued, 'a person may always ask');
        Queue::cancel((string) $this->storage->active_request_for_post($post)['id']);

        Queue::clear_marker($post);
        $this->assertTrue($this->queue($post, ['manual' => false])->queued);
    }

    public function test_case_10_log_mode_records_without_reserving_or_calling(): void
    {
        $post = $this->post();
        $this->settings(['auto_mode' => 'log']);
        $result = $this->queue($post, ['manual' => false]);
        $this->assertFalse($result->queued);
        $this->assertSame(Reason::WOULD_REQUEST, $result->reason);
        $last = get_post_meta($post, Queue::LAST_META, true);
        $this->assertSame(Reason::WOULD_REQUEST, $last['reason']);
        $this->assertSame(0, $this->storage->count_requests([]));
        $this->assertSame([], $this->provider->requests);
        $this->assertSame(0, $this->storage->count('day', $this->clock->day()));

        $this->settings(['auto_mode' => 'off']);
        $other = $this->post();
        $this->assertSame(Reason::AUTO_OFF, $this->queue($other, ['manual' => false])->reason);
        $this->assertSame('', get_post_meta($other, Queue::LAST_META, true));
    }

    public function test_case_11_duplicate_guard_returns_existing_id(): void
    {
        $post = $this->post();
        $first = $this->queue($post);
        $second = $this->queue($post);
        $this->assertFalse($second->queued);
        $this->assertSame(Reason::ALREADY_QUEUED, $second->reason);
        $this->assertSame($first->request_id, $second->request_id);
    }
}
