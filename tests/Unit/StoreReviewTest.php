<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Unit;

use IndianOpinion\FeaturedFromPrompt\Paths;
use IndianOpinion\FeaturedFromPrompt\Queue;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\Rest;
use IndianOpinion\FeaturedFromPrompt\Suggestions;
use IndianOpinion\FeaturedFromPrompt\Tests\TestCase;
use WP;

/**
 * Cases 56 to 65, and the post lock ruling.
 */
final class StoreReviewTest extends TestCase
{
    private function suggestion(int $post): int
    {
        $record = $this->generate($post);
        $this->assertSame(Reason::SUGGESTION_READY, $record->reason);
        return $record->attachment_id();
    }

    public function test_case_56_random_name_in_the_private_directory_with_parent(): void
    {
        $post = $this->post();
        $id = $this->suggestion($post);
        $file = (string) get_post_meta($id, '_wp_attached_file', true);
        $this->assertMatchesRegularExpression('/^ffp-[0-9a-f]{16}\.(png|jpe?g|webp)$/', basename($file));
        $this->assertTrue(Paths::is_private($file), 'pending files stay outside uploads');
        $this->assertFileExists($file);
        $this->assertFileExists(Paths::private_dir() . '/.htaccess');
        $this->assertSame($post, get_post($id)?->post_parent);
        $this->assertStringContainsString('/suggestions/' . $id . '/preview', (string) wp_get_attachment_url($id));
    }

    public function test_case_57_full_record_or_nothing(): void
    {
        $id = $this->suggestion($this->post());
        $record = Suggestions::record($id);
        foreach (['generated', 'prompt', 'negative_prompt', 'revised_prompt', 'provider_id', 'model', 'request_id', 'provider_request_id', 'requested_width', 'requested_height', 'real_width', 'real_height', 'created', 'user_id'] as $key) {
            $this->assertArrayHasKey($key, $record, $key);
        }
        $this->assertTrue(Suggestions::is_generated($id));
        $this->assertSame('pending', Suggestions::state($id));
        $this->assertSame(self::EDITOR, $record['user_id']);

        add_filter('update_post_metadata', static fn ($check, $object, $key) => $key === Suggestions::META_RECORD ? false : $check, 10, 3);
        $before = count(WP::$posts);
        $failed = $this->generate($this->post());
        $this->assertSame(Reason::RECORD_FAILED, $failed->reason);
        $this->assertSame($before + 1, count(WP::$posts), 'only the new post remains; the attachment was deleted');
        $this->assertCount(1, glob(Paths::private_dir() . '/ffp-*') ?: [], 'the failed file was removed');
    }

    public function test_case_58_title_alt_caption(): void
    {
        $this->settings(['alt_template' => 'Picture: {title} ' . str_repeat('long ', 80)]);
        $post = $this->post(['post_title' => 'Floods &amp; roads']);
        $id = $this->suggestion($post);
        $this->assertSame('Generated image for: Floods & roads', get_post($id)?->post_title);
        $this->assertSame('AI-generated illustration', get_post($id)?->post_excerpt);
        $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $this->assertStringStartsWith('Picture: Floods & roads', $alt);
        $this->assertLessThanOrEqual(250, mb_strlen($alt));

        add_filter('ffp_attachment_data', static fn (array $data) => ['caption' => 'Custom'] + $data);
        $this->assertSame('Custom', get_post($this->suggestion($this->post()))?->post_excerpt);
    }

    public function test_case_59_featured_image_untouched_action_once_no_temp_files(): void
    {
        $post = $this->post();
        $existing = $this->image_attachment();
        set_post_thumbnail($post, $existing);
        $id = $this->suggestion($post);
        $this->assertSame($existing, (int) get_post_meta($post, '_thumbnail_id', true));
        $this->assertSame(1, $this->fired('ffp_suggestion_ready'));
        $this->assertSame($id, (int) get_post_meta($post, Queue::SUGGESTION_META, true));
        $this->assertSame([], glob(WP::$root . '/tmp/*') ?: []);

        $this->provider->push(new \IndianOpinion\FeaturedFromPrompt\Provider\ImageResult(\IndianOpinion\FeaturedFromPrompt\Tests\Support\Img::png(512, 512)));
        $this->generate($post);
        $this->assertSame([], glob(WP::$root . '/tmp/*') ?: [], 'no temporary file on a failure path');
    }

    public function test_case_60_status_lists_pending_newest_first(): void
    {
        $post = $this->post();
        $a = $this->suggestion($post);
        $b = $this->suggestion($post);
        $status = Rest::status(new \WP_REST_Request('GET', '', ['id' => $post]))->get_data();
        $this->assertSame([$b, $a], array_column($status['suggestions'], 'id'));
        foreach (['preview_url', 'prompt', 'provider', 'model', 'width', 'height'] as $key) {
            $this->assertArrayHasKey($key, $status['suggestions'][0]);
        }
        $this->assertSame(1536, $status['suggestions'][0]['width']);
        $this->assertArrayHasKey('placeholder', $status['featured']);
    }

    public function test_case_61_accept_and_each_failed_check(): void
    {
        $post = $this->post();
        $previous = $this->image_attachment();
        set_post_thumbnail($post, $previous);
        $a = $this->suggestion($post);
        $b = $this->suggestion($post);

        WP::add_user(7, ['upload_files']);
        $forbidden = Suggestions::accept($a, 7);
        $this->assertSame(Reason::FORBIDDEN, $forbidden->reason);
        $this->assertSame('pending', Suggestions::state($a));

        $this->assertSame(Reason::NOT_GENERATED, Suggestions::accept($previous, self::EDITOR)->reason);
        $this->assertSame(Reason::WRONG_POST, Suggestions::accept($a, self::EDITOR, $this->post())->reason);

        $result = Suggestions::accept($a, self::EDITOR);
        $this->assertTrue($result->ok);
        $this->assertSame($a, (int) get_post_meta($post, '_thumbnail_id', true));
        $this->assertSame('accepted', Suggestions::state($a));
        $this->assertSame('superseded', Suggestions::state($b));
        $this->assertSame([$post, $a, $previous, self::EDITOR], $this->fired['ffp_accepted'][0]);
        $this->assertFalse(Paths::is_private((string) get_attached_file($a)), 'the file moved into uploads');
        $this->assertFileExists((string) get_attached_file($a));

        $this->assertSame(Reason::NOT_PENDING, Suggestions::accept($a, self::EDITOR)->reason);
        $this->assertSame(Reason::NOT_PENDING, Suggestions::accept($b, self::EDITOR)->reason);

        $other = $this->post();
        $c = $this->suggestion($other);
        WP::$posts[$other]->post_status = 'trash';
        $this->assertSame(Reason::POST_GONE, Suggestions::accept($c, self::EDITOR)->reason);
    }

    public function test_case_61_rest_accept_without_capability_is_refused(): void
    {
        $post = $this->post();
        $id = $this->suggestion($post);
        WP::$current_user = 0;
        $denied = Rest::can_review(new \WP_REST_Request('POST', '', ['id' => $id]));
        $this->assertInstanceOf(\WP_Error::class, $denied);
        $this->assertSame(403, $denied->get_error_data()['status']);
    }

    public function test_case_62_accept_over_a_real_image_keeps_the_old_one(): void
    {
        $post = $this->post();
        $old = $this->image_attachment();
        set_post_thumbnail($post, $old);
        $id = $this->suggestion($post);
        $this->assertTrue(Suggestions::accept($id, self::EDITOR)->ok);
        $this->assertSame($id, (int) get_post_meta($post, '_thumbnail_id', true));
        $this->assertNotNull(get_post($old));
    }

    public function test_case_63_reject_records_and_starts_nothing(): void
    {
        $post = $this->post();
        $id = $this->suggestion($post);
        $calls = count($this->provider->requests);
        $result = Suggestions::reject($id, self::EDITOR, '<b>Wrong</b> ' . str_repeat('mood ', 80));
        $this->assertTrue($result->ok);
        $this->assertSame('rejected', Suggestions::state($id));
        $history = get_post_meta($id, Suggestions::META_HISTORY, true);
        $last = end($history);
        $this->assertSame(self::EDITOR, $last['user']);
        $this->assertSame(200, mb_strlen($last['reason']));
        $this->assertStringStartsWith('Wrong mood', $last['reason']);
        $this->assertSame(1, $this->fired('ffp_rejected'));
        $this->assertSame(0, $this->storage->count_requests(['queued']));
        $this->assertCount($calls, $this->provider->requests);
    }

    public function test_case_64_generate_another_keeps_both_pending(): void
    {
        $post = $this->post();
        $a = $this->suggestion($post);
        $b = $this->suggestion($post);
        $this->assertSame(['pending', 'pending'], [Suggestions::state($a), Suggestions::state($b)]);
    }

    public function test_case_65_media_modal_pick_is_an_accept(): void
    {
        $post = $this->post();
        $a = $this->suggestion($post);
        $b = $this->suggestion($post);
        $unrelated = $this->image_attachment();
        set_post_thumbnail($post, $unrelated);
        $this->assertSame(['pending', 'pending'], [Suggestions::state($a), Suggestions::state($b)]);

        set_post_thumbnail($post, $b);
        $this->assertSame('accepted', Suggestions::state($b));
        $this->assertSame('superseded', Suggestions::state($a));
        $this->assertSame(1, $this->fired('ffp_accepted'));
        $this->assertFalse(Paths::is_private((string) get_attached_file($b)));
    }

    public function test_only_a_person_can_pick_a_pending_suggestion_outside_accept(): void
    {
        $post = $this->post();
        $other = $this->post();
        $id = $this->suggestion($post);

        // Another post's thumbnail: refused, nothing changes.
        set_post_thumbnail($other, $id);
        $this->assertSame('', get_post_meta($other, '_thumbnail_id', true));

        // Cron or code with no user: refused.
        WP::$current_user = 0;
        set_post_thumbnail($post, $id);
        add_post_meta($post, '_thumbnail_id', $id);
        $this->assertSame('', get_post_meta($post, '_thumbnail_id', true));

        // A user who cannot upload: refused.
        WP::add_user(12, ['edit_post']);
        WP::$current_user = 12;
        set_post_thumbnail($post, $id);
        $this->assertSame('', get_post_meta($post, '_thumbnail_id', true));
        $this->assertSame('pending', Suggestions::state($id));
        $this->assertTrue(Paths::is_private((string) get_post_meta($id, '_wp_attached_file', true)));
        $this->assertSame(0, $this->fired('ffp_accepted'));

        // The editor's own pick still counts as Accept.
        WP::$current_user = self::EDITOR;
        set_post_thumbnail($post, $id);
        $this->assertSame('accepted', Suggestions::state($id));
    }

    public function test_private_directory_fails_closed(): void
    {
        // Inside uploads: refused, nothing stored, request ends store_failed.
        add_filter('ffp_private_dir', static fn () => WP::$root . '/uploads/ffp-private');
        $record = $this->generate($this->post());
        $this->assertSame(Reason::STORE_FAILED, $record->reason);
        $this->assertDirectoryDoesNotExist(WP::$root . '/uploads/ffp-private');
        $this->assertSame([], get_posts(['post_type' => 'attachment', 'fields' => 'ids']));
        remove_all_filters('ffp_private_dir');

        // A path with "..": refused.
        add_filter('ffp_private_dir', static fn () => WP_CONTENT_DIR . '/ffp-private/../elsewhere');
        $this->assertSame(Reason::STORE_FAILED, $this->generate($this->post())->reason);
    }

    public function test_only_plugin_named_files_count_as_private(): void
    {
        $dir = Paths::private_dir();
        $this->assertTrue(Paths::is_private($dir . '/ffp-0123456789abcdef.png'));
        $this->assertFalse(Paths::is_private($dir . '/../../wp-config.php'));
        $this->assertFalse(Paths::is_private($dir . '/sub/ffp-0123456789abcdef.png'));
        $this->assertFalse(Paths::is_private($dir . '/ffp-0123456789abcdef.php'));
        $this->assertFalse(Paths::is_private($dir . '/ffp-0123456789abcdef.png.php'));
        $this->assertFalse(Paths::is_private($dir . '/.htaccess'));
    }

    public function test_accept_refuses_a_pending_file_whose_bytes_do_not_match(): void
    {
        $post = $this->post();
        $id = $this->suggestion($post);
        $file = (string) get_post_meta($id, '_wp_attached_file', true);
        file_put_contents($file, '<?php echo "hi"; ?>' . str_repeat(' ', 2048));
        $result = Suggestions::accept($id, self::EDITOR);
        $this->assertFalse($result->ok);
        $this->assertSame(Reason::STORE_FAILED, $result->reason);
        $this->assertSame('pending', Suggestions::state($id));
        $this->assertTrue(Paths::is_private((string) get_post_meta($id, '_wp_attached_file', true)));
        $this->assertSame('', get_post_meta($post, '_thumbnail_id', true));
    }

    public function test_post_lock_warns_then_proceeds_on_confirmation(): void
    {
        $post = $this->post();
        $id = $this->suggestion($post);
        WP::add_user(9, ['edit_post', 'upload_files']);
        update_post_meta($post, '_edit_lock', ($this->clock->now() - 10) . ':9');
        $first = Suggestions::accept($id, self::EDITOR);
        $this->assertSame([false, Reason::POST_LOCKED, 9], [$first->ok, $first->reason, $first->lock_user]);
        $this->assertSame('pending', Suggestions::state($id));
        $rest = Rest::accept(new \WP_REST_Request('POST', '', ['id' => $id]));
        $this->assertInstanceOf(\WP_Error::class, $rest);
        $this->assertSame(409, $rest->get_error_data()['status']);

        $second = Suggestions::accept($id, self::EDITOR, 0, true);
        $this->assertTrue($second->ok);
        $this->assertSame('accepted', Suggestions::state($id));

        // An old lock is not a lock.
        $other = $this->post();
        $c = $this->suggestion($other);
        update_post_meta($other, '_edit_lock', ($this->clock->now() - 1000) . ':9');
        $this->assertTrue(Suggestions::accept($c, self::EDITOR)->ok);
    }
}
