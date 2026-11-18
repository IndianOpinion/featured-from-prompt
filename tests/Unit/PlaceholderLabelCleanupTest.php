<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Unit;

use IndianOpinion\FeaturedFromPrompt\Cleanup;
use IndianOpinion\FeaturedFromPrompt\Eligibility;
use IndianOpinion\FeaturedFromPrompt\Labels;
use IndianOpinion\FeaturedFromPrompt\Placeholders;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageResult;
use IndianOpinion\FeaturedFromPrompt\Provider\PromptRefusedException;
use IndianOpinion\FeaturedFromPrompt\Provider\TransientException;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\Suggestions;
use IndianOpinion\FeaturedFromPrompt\Tests\Support\Img;
use IndianOpinion\FeaturedFromPrompt\Tests\TestCase;
use WP;

/**
 * Cases 66 to 79.
 */
final class PlaceholderLabelCleanupTest extends TestCase
{
    private function fail_once(int $post): void
    {
        $this->provider->push(new PromptRefusedException('no'));
        $this->generate($post);
    }

    private function suggestion(int $post): int
    {
        return $this->generate($post)->attachment_id();
    }

    public function test_case_66_policy_none_and_placeholder(): void
    {
        $fallback = $this->image_attachment();
        $post = $this->post();
        $this->settings(['fallback_placeholder' => $fallback]);
        $this->fail_once($post);
        $this->assertSame('', get_post_meta($post, '_thumbnail_id', true));

        $this->settings(['on_failure' => 'placeholder']);
        $this->fail_once($post);
        $this->assertSame($fallback, (int) get_post_meta($post, '_thumbnail_id', true));

        $with_image = $this->post();
        $real = $this->image_attachment();
        set_post_thumbnail($with_image, $real);
        $this->fail_once($with_image);
        $this->assertSame($real, (int) get_post_meta($with_image, '_thumbnail_id', true));
    }

    public function test_case_67_no_placeholder_until_the_last_attempt(): void
    {
        $fallback = $this->image_attachment();
        $this->settings(['on_failure' => 'placeholder', 'fallback_placeholder' => $fallback, 'attempts' => 2]);
        $post = $this->post();
        $this->provider->push(new TransientException('503'), new TransientException('503'));
        $id = (string) $this->queue($post)->request_id;
        $this->run_request($id);
        $this->assertSame('', get_post_meta($post, '_thumbnail_id', true));
        $this->clock->advance(60);
        $this->run_request($id);
        $this->assertSame($fallback, (int) get_post_meta($post, '_thumbnail_id', true));
    }

    public function test_case_68_placeholder_filter(): void
    {
        $fallback = $this->image_attachment();
        $other = $this->image_attachment();
        $pdf = $this->image_attachment(0, 'application/pdf');
        $this->settings(['on_failure' => 'placeholder', 'fallback_placeholder' => $fallback]);
        $choice = $other;
        add_filter('ffp_placeholder_id', static function () use (&$choice) {
            return $choice;
        });
        $a = $this->post();
        $this->fail_once($a);
        $this->assertSame($other, (int) get_post_meta($a, '_thumbnail_id', true));

        $choice = $pdf;
        $b = $this->post();
        $this->fail_once($b);
        $this->assertSame('', get_post_meta($b, '_thumbnail_id', true));
        $this->assertTrue($this->has_log('Placeholder ID is not an ordinary image'));

        $choice = 0;
        $c = $this->post();
        $this->fail_once($c);
        $this->assertSame('', get_post_meta($c, '_thumbnail_id', true));
    }

    public function test_case_69_placeholders_known_by_id(): void
    {
        $listed = $this->image_attachment();
        WP::$meta[$listed]['_wp_attachment_metadata'] = ['width' => 1600, 'height' => 900];
        $this->settings(['placeholder_ids' => [$listed]]);
        $this->assertTrue(Placeholders::is_placeholder($listed));
        $this->assertTrue(\IndianOpinion\FeaturedFromPrompt\is_placeholder($listed));
        $post = $this->post();
        set_post_thumbnail($post, $listed);
        $this->assertSame(0, Placeholders::usable_image_id($post));

        $extra = $this->image_attachment();
        $this->assertFalse(Placeholders::is_placeholder($extra));
        add_filter('ffp_is_placeholder', static fn (bool $is, int $id) => $is || $id === $extra, 10, 2);
        $this->assertTrue(Placeholders::is_placeholder($extra));
    }

    public function test_case_70_post_on_placeholder_is_eligible_and_accept_replaces(): void
    {
        $placeholder = $this->image_attachment();
        $this->settings(['placeholder_ids' => [$placeholder]]);
        $post = $this->post();
        set_post_thumbnail($post, $placeholder);
        $this->assertNull(Eligibility::check($post, true));
        $status = \IndianOpinion\FeaturedFromPrompt\status($post);
        $this->assertTrue($status['featured']['placeholder']);
        $id = $this->suggestion($post);
        $this->assertTrue(Suggestions::accept($id, self::EDITOR)->ok);
        $this->assertSame($id, (int) get_post_meta($post, '_thumbnail_id', true));
    }

    public function test_case_71_placeholder_action_once_with_record(): void
    {
        $fallback = $this->image_attachment();
        $this->settings(['on_failure' => 'placeholder', 'fallback_placeholder' => $fallback]);
        $post = $this->post();
        $this->fail_once($post);
        $this->assertSame(1, $this->fired('ffp_placeholder_set'));
        [$pid, $aid, $record] = $this->fired['ffp_placeholder_set'][0];
        $this->assertSame([$post, $fallback], [$pid, $aid]);
        $this->assertSame(Reason::PROMPT_REFUSED, $record['reason']);
    }

    private function accepted(int $post): int
    {
        $id = $this->suggestion($post);
        Suggestions::accept($id, self::EDITOR);
        return $id;
    }

    public function test_case_72_label_modes(): void
    {
        $post = $this->post();
        $id = $this->accepted($post);
        WP::$context = ['admin' => false, 'feed' => false, 'singular' => true, 'queried' => $post, 'sitemap' => ''];
        $html = '<img src="x.png" />';

        $this->assertSame('AI-generated illustration', get_post($id)?->post_excerpt, 'caption mode sets the caption');
        $this->assertSame($html, Labels::thumbnail_html($html, $post, $id));

        $this->settings(['label_mode' => 'append']);
        $this->assertSame($html . '<p class="ffp-label">AI-generated illustration</p>', Labels::thumbnail_html($html, $post, $id));
        $this->settings(['label_mode' => 'both']);
        $this->assertStringContainsString('ffp-label', Labels::thumbnail_html($html, $post, $id));
        $this->settings(['label_mode' => 'off']);
        $this->assertSame($html, Labels::thumbnail_html($html, $post, $id));

        $this->settings(['label_mode' => 'append']);
        add_filter('ffp_label_html', static fn () => '');
        $this->assertSame($html, Labels::thumbnail_html($html, $post, $id));
    }

    public function test_case_73_no_double_label(): void
    {
        $this->settings(['label_mode' => 'append']);
        $post = $this->post();
        $id = $this->accepted($post);
        WP::$context = ['admin' => false, 'feed' => false, 'singular' => true, 'queried' => $post, 'sitemap' => ''];
        $html = '<figure><img src="x.png" /><figcaption>AI-generated illustration</figcaption></figure>';
        $this->assertSame($html, Labels::thumbnail_html($html, $post, $id));
        $html2 = '<img src="x.png" /><p class="ffp-label">Made by a model</p>';
        $this->assertSame($html2, Labels::thumbnail_html($html2, $post, $id));
    }

    public function test_case_74_label_context(): void
    {
        $this->settings(['label_mode' => 'append']);
        $post = $this->post();
        $id = $this->accepted($post);
        $html = '<img />';
        $contexts = [
            ['admin' => true, 'feed' => false, 'singular' => true, 'queried' => $post, 'sitemap' => ''],
            ['admin' => false, 'feed' => true, 'singular' => true, 'queried' => $post, 'sitemap' => ''],
            ['admin' => false, 'feed' => false, 'singular' => true, 'queried' => $post, 'sitemap' => 'posts'],
            ['admin' => false, 'feed' => false, 'singular' => true, 'queried' => $post + 1000, 'sitemap' => ''],
            ['admin' => false, 'feed' => false, 'singular' => false, 'queried' => 0, 'sitemap' => ''],
        ];
        foreach ($contexts as $i => $context) {
            WP::$context = $context;
            $this->assertSame($html, Labels::thumbnail_html($html, $post, $id), 'context ' . $i);
        }
        add_filter('ffp_label_context', static fn () => true);
        $this->assertStringContainsString('ffp-label', Labels::thumbnail_html($html, $post, $id), 'the filter widens it');
        WP::$context['admin'] = true;
        $this->assertSame($html, Labels::thumbnail_html($html, $post, $id), 'never in admin');
    }

    public function test_case_75_is_generated_and_rest_field(): void
    {
        $id = $this->suggestion($this->post());
        $plain = $this->image_attachment();
        $this->assertTrue(\IndianOpinion\FeaturedFromPrompt\is_generated($id));
        $this->assertFalse(\IndianOpinion\FeaturedFromPrompt\is_generated($plain));
        $this->assertTrue(\ffp_is_generated($id));
        Labels::rest_fields();
        $cb = WP::$rest_fields['attachment:ffp_generated']['get_callback'];
        $this->assertTrue($cb(['id' => $id]));
        $this->assertFalse($cb(['id' => $plain]));
        $state = WP::$rest_fields['attachment:ffp_review_state']['get_callback'];
        $this->assertSame('pending', $state(['id' => $id]));
    }

    public function test_case_76_pending_expires(): void
    {
        $id = $this->suggestion($this->post());
        $this->clock->advance(31 * 86400);
        Cleanup::run();
        $this->assertSame('expired', Suggestions::state($id));
    }

    public function test_case_77_retention_deletes_but_keeps_featured_and_filtered(): void
    {
        $post = $this->post();
        $rejected = $this->suggestion($post);
        $featured = $this->suggestion($post);
        $kept = $this->suggestion($post);
        foreach ([$rejected, $featured, $kept] as $id) {
            Suggestions::reject($id, self::EDITOR);
        }
        $file = (string) get_post_meta($rejected, '_wp_attached_file', true);
        update_post_meta($this->post(), '_thumbnail_id', $featured);
        add_filter('ffp_keep_attachment', static fn (bool $keep, int $id) => $keep || $id === $kept, 10, 2);

        $this->clock->advance(15 * 86400);
        $result = Cleanup::run();
        $this->assertNull(get_post($rejected));
        $this->assertFileDoesNotExist($file);
        $this->assertSame('accepted', Suggestions::state($featured));
        $this->assertTrue($this->has_log('set back to accepted'));
        $this->assertNotNull(get_post($kept));
        $this->assertSame([1, 1, 1], [$result['deleted'], $result['restored'], $result['kept']]);
    }

    public function test_case_78_accepted_images_survive_any_age(): void
    {
        $post = $this->post();
        $id = $this->accepted($post);
        $this->clock->advance(3650 * 86400);
        Cleanup::run();
        $this->assertNotNull(get_post($id));
        $this->assertSame('accepted', Suggestions::state($id));
    }

    public function test_case_79_cleanup_respects_batch_and_budget(): void
    {
        $this->settings(['post_cap' => 20, 'day_cap' => 100]);
        $post = $this->post();
        for ($i = 0; $i < 6; $i++) {
            $this->provider->push(new ImageResult(Img::png(1600, 900)));
            $this->suggestion($post);
        }
        $this->clock->advance(31 * 86400);
        $result = Cleanup::run(false, 4);
        $this->assertSame(4, $result['expired']);
        $this->assertSame('budget', $result['stopped']);

        $slow = new class (0) extends \IndianOpinion\FeaturedFromPrompt\Tests\Support\FrozenClock {
            public function now(): int
            {
                $this->time += 30;
                return $this->time;
            }
        };
        $slow->time = $this->clock->time;
        \IndianOpinion\FeaturedFromPrompt\Plugin::set_instance(new \IndianOpinion\FeaturedFromPrompt\Plugin($this->storage, $slow, $this->registry, new \IndianOpinion\FeaturedFromPrompt\Logger()));
        $result = Cleanup::run(false, 50, 20);
        $this->assertSame('budget', $result['stopped']);
        $this->assertLessThan(2, $result['expired']);
    }
}
