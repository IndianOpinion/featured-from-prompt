<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Unit;

use IndianOpinion\FeaturedFromPrompt\CheckFailed;
use IndianOpinion\FeaturedFromPrompt\ImageChecker;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageResult;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\Suggestions;
use IndianOpinion\FeaturedFromPrompt\Tests\Support\Img;
use IndianOpinion\FeaturedFromPrompt\Tests\TestCase;
use WP;

/**
 * Cases 46 to 55.
 */
final class ImageChecksTest extends TestCase
{
    private function result_for(string $bytes, string $mime = 'image/png'): \IndianOpinion\FeaturedFromPrompt\RequestRecord
    {
        $this->provider->push(new ImageResult($bytes, null, $mime));
        return $this->generate($this->post());
    }

    private function reason_for(string $bytes, string $mime = 'image/png'): string
    {
        return $this->result_for($bytes, $mime)->reason;
    }

    public function test_case_46_non_images_fail_whatever_the_hint(): void
    {
        $this->settings(['post_cap' => 20]);
        $html = '<!DOCTYPE html><html><body>' . str_repeat('<p>error</p>', 200) . '</body></html>';
        $gif = "GIF89a" . str_repeat("\0", 2000);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="900">' . str_repeat('<rect width="1" height="1"/>', 100) . '</svg>';
        foreach ([$html, $gif, $svg] as $bytes) {
            $this->assertSame(Reason::NOT_IMAGE, $this->reason_for($bytes, 'image/png'));
        }
    }

    public function test_case_47_byte_limits(): void
    {
        $this->settings(['max_mb' => 15]);
        $sent = [];
        WP::$http = static function (string $url, array $args) use (&$sent): array {
            $sent = $args;
            // The HTTP layer stops at limit_response_size, as WordPress does.
            $body = str_repeat('x', min(16 * 1048576, (int) $args['limit_response_size']));
            return ['response' => ['code' => 200], 'body' => $body];
        };
        $this->provider->push(new ImageResult(null, 'https://images.example.com/huge.png'));
        $record = $this->generate($this->post());
        $this->assertSame(Reason::FILE_TOO_LARGE, $record->reason);
        $this->assertSame(15 * 1048576 + 1, $sent['limit_response_size']);

        $this->assertSame(Reason::NOT_IMAGE, $this->reason_for(substr(Img::png(1600, 900), 0, 500)));
    }

    public function test_case_48_truncated_png_fails_and_jpeg_hinted_as_png_is_jpeg(): void
    {
        $png = Img::png(1600, 900);
        $this->assertSame(Reason::DECODE_FAILED, $this->reason_for(substr($png, 0, (int) (strlen($png) * 0.6))));
        $record = $this->result_for(Img::jpeg(1600, 900), 'image/png');
        $this->assertSame(Reason::SUGGESTION_READY, $record->reason);
        $this->assertSame('image/jpeg', get_post_mime_type($record->attachment_id()));
        $this->assertStringEndsWith('.jpg', (string) get_post_meta($record->attachment_id(), '_wp_attached_file', true));
    }

    public function test_case_49_pixel_bomb_fails_before_any_editor(): void
    {
        WP::$editor_calls = 0;
        $bytes = Img::png_header(20000, 20000);
        try {
            ImageChecker::check($bytes, ImageChecker::rules(0), ['crop' => true, 'target_ratio' => 16 / 9, 'output_format' => 'jpeg', 'quality' => 80]);
            $this->fail('expected a check failure');
        } catch (CheckFailed $e) {
            $this->assertSame(Reason::TOO_MANY_PIXELS, $e->reason);
        }
        $this->assertSame(0, WP::$editor_calls);
        $this->assertSame(Reason::TOO_MANY_PIXELS, $this->reason_for($bytes));
    }

    public function test_case_50_too_small(): void
    {
        $this->assertSame(Reason::TOO_SMALL, $this->reason_for(Img::png(512, 512)));
    }

    public function test_case_51_aspect_window(): void
    {
        $this->assertSame(Reason::BAD_ASPECT, $this->reason_for(Img::png(1024, 1024)));
        $this->assertSame(Reason::SUGGESTION_READY, $this->reason_for(Img::png(1792, 1024)));
    }

    public function test_case_52_crop_to_target_aspect(): void
    {
        $this->settings(['crop' => true]);
        $record = $this->result_for(Img::png(1024, 1024));
        $this->assertSame(Reason::SUGGESTION_READY, $record->reason);
        $stored = Suggestions::record($record->attachment_id());
        $this->assertSame([1024, 576, true], [$stored['real_width'], $stored['real_height'], $stored['cropped']]);
        $this->assertSame([1024, 1024], [$record->row['record']['original_width'], $record->row['record']['original_height']]);

        $this->settings(['min_width' => 700, 'min_height' => 500]);
        $this->assertSame(Reason::TOO_SMALL, $this->reason_for(Img::png(800, 800)));
    }

    public function test_case_53_conversion_and_skip(): void
    {
        $this->settings(['output_format' => 'jpeg']);
        $record = $this->result_for(Img::png(1600, 900));
        $this->assertSame('image/jpeg', get_post_mime_type($record->attachment_id()));
        $this->assertTrue($record->row['record']['converted']);

        WP::$editor_available = false;
        $record = $this->result_for(Img::png(1600, 900));
        $this->assertSame(Reason::SUGGESTION_READY, $record->reason);
        $this->assertSame('image/png', get_post_mime_type($record->attachment_id()));
        $this->assertTrue($record->row['record']['convert_skipped']);
    }

    public function test_case_54_rules_filter_per_post_type(): void
    {
        $this->settings(['post_types' => ['post', 'page']]);
        add_filter('ffp_image_rules', static function (array $rules, int $post_id): array {
            if (get_post($post_id)?->post_type === 'page') {
                $rules['min_width'] = 2000;
            }
            return $rules;
        }, 10, 2);
        $this->provider->push(new ImageResult(Img::png(1600, 900)), new ImageResult(Img::png(1600, 900)));
        $this->assertSame(Reason::SUGGESTION_READY, $this->generate($this->post())->reason);
        $this->assertSame(Reason::TOO_SMALL, $this->generate($this->post(['post_type' => 'page']))->reason);
    }

    public function test_case_55_check_failure_is_final_counted_not_retried(): void
    {
        $this->provider->push(new ImageResult(Img::png(512, 512)));
        $record = $this->generate($this->post());
        $this->assertSame(['failed', Reason::TOO_SMALL], [$record->state(), $record->reason]);
        $this->assertSame(1, $this->storage->count('day', $this->clock->day()));
        $this->assertSame(0, $this->storage->count_requests(['queued']));
    }
}
