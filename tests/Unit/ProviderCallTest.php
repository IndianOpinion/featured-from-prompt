<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Unit;

use IndianOpinion\FeaturedFromPrompt\Pauses;
use IndianOpinion\FeaturedFromPrompt\Provider\AuthException;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageResult;
use IndianOpinion\FeaturedFromPrompt\Provider\PromptRefusedException;
use IndianOpinion\FeaturedFromPrompt\Provider\ProviderCapabilities;
use IndianOpinion\FeaturedFromPrompt\Provider\QuotaException;
use IndianOpinion\FeaturedFromPrompt\Provider\RateLimitException;
use IndianOpinion\FeaturedFromPrompt\Provider\TransientException;
use IndianOpinion\FeaturedFromPrompt\Queue;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\SizeChooser;
use IndianOpinion\FeaturedFromPrompt\Suggestions;
use IndianOpinion\FeaturedFromPrompt\Tests\Support\Img;
use IndianOpinion\FeaturedFromPrompt\Tests\TestCase;
use WP;

/**
 * Cases 35 to 45 (43 is in GenericHttpProviderTest).
 */
final class ProviderCallTest extends TestCase
{
    private function day_count(): int
    {
        return $this->storage->count('day', $this->clock->day());
    }

    public function test_case_35_provider_receives_the_full_request(): void
    {
        $this->settings(['timeout' => 45, 'output_format' => 'webp']);
        $post = $this->post();
        $record = $this->generate($post);
        $request = $this->provider->requests[0];
        $this->assertStringContainsString('Rain returns to the plains', $request->prompt);
        $this->assertSame(1536, $request->width);
        $this->assertSame(864, $request->height);
        $this->assertSame('16:9', $request->aspect_ratio);
        $this->assertSame('webp', $request->format);
        $this->assertSame(1, $request->count);
        $this->assertSame($record->id(), $request->request_id);
        $this->assertSame(45, $request->timeout);
        $this->assertSame($post, $request->post_id);
    }

    public function test_case_36_size_choice(): void
    {
        $caps = new ProviderCapabilities(sizes: [[1024, 1024], [1536, 1024], [1792, 1024]]);
        $this->assertSame(['width' => 1792, 'height' => 1024], array_intersect_key(SizeChooser::choose($caps, 16, 9, 1536, 864, 1024, 576), ['width' => 1, 'height' => 1]));
        $any = SizeChooser::choose(new ProviderCapabilities(), 16, 9, 1536, 864, 1024, 576);
        $this->assertSame([1536, 864, 'any_size'], [$any['width'], $any['height'], $any['how']]);
        $ratio = SizeChooser::choose(new ProviderCapabilities(aspect_ratios: ['1:1', '16:9', '4:3']), 16, 9, 1536, 864, 1024, 576);
        $this->assertSame('16:9', $ratio['aspect_ratio']);

        $this->provider->caps = $caps;
        $this->provider->push(new ImageResult(Img::png(1792, 1024)));
        $record = $this->generate($this->post());
        $this->assertSame(1792, $this->provider->requests[0]->width);
        $this->assertSame('listed_size', $record->row['record']['size_choice']);
    }

    public function test_case_37_bytes_and_url_results_are_stored(): void
    {
        $this->assertSame(Reason::SUGGESTION_READY, $this->generate($this->post())->reason);

        WP::$http = static fn (string $url, array $args): array => ['response' => ['code' => 200], 'body' => Img::png(1600, 900), 'headers' => []];
        $this->provider->push(new ImageResult(null, 'https://images.example.com/result.png'));
        $record = $this->generate($this->post());
        $this->assertSame(Reason::SUGGESTION_READY, $record->reason);
        $this->assertTrue(WP::$http_calls[0]['safe']);
        $this->assertTrue(WP::$http_calls[0]['args']['reject_unsafe_urls']);
        $this->assertSame(1600, Suggestions::record($record->attachment_id())['real_width']);
    }

    public function test_case_38_unsafe_downloads_are_refused_and_counted(): void
    {
        $this->settings(['attempts' => 1]);
        \IndianOpinion\FeaturedFromPrompt\Downloader::set_resolver(
            static fn (string $host): array => $host === 'rebind.example.com' ? ['169.254.169.254'] : ['93.184.216.34']
        );
        WP::$http = static function (string $url, array $args): array|\WP_Error {
            // Every hop is a redirect: hop N goes to hop N + 1.
            if (preg_match('#/hop(\d+)\.png$#', $url, $m) === 1) {
                return ['response' => ['code' => 302], 'headers' => ['location' => 'https://images.example.com/hop' . ((int) $m[1] + 1) . '.png'], 'body' => ''];
            }
            if (str_ends_with($url, '/to-metadata.png')) {
                return ['response' => ['code' => 301], 'headers' => ['location' => 'http://169.254.169.254/latest/meta-data/'], 'body' => ''];
            }
            if (str_ends_with($url, '/to-rebind.png')) {
                return ['response' => ['code' => 307], 'headers' => ['location' => 'https://rebind.example.com/a.png'], 'body' => ''];
            }
            return ['response' => ['code' => 200], 'body' => Img::png(1600, 900)];
        };
        $urls = [
            'https://10.1.2.3/a.png',
            'http://images.example.com/a.png',
            'https://localhost/a.png',
            'https://169.254.169.254/a.png',
            'https://[fd00::1]/a.png',
            'https://2130706433/a.png',
            'https://rebind.example.com/a.png',
            'https://images.example.com/hop1.png',
            'https://images.example.com/to-metadata.png',
            'https://images.example.com/to-rebind.png',
        ];
        foreach ($urls as $i => $url) {
            $this->provider->push(new ImageResult(null, $url));
            $record = $this->generate($this->post());
            $this->assertSame(Reason::DOWNLOAD_FAILED, $record->reason, $url);
            $this->assertSame($i + 1, $this->day_count());
        }
        $reached = array_map(static fn (array $call): string => $call['url'], WP::$http_calls);
        $this->assertSame([
            'https://images.example.com/hop1.png',
            'https://images.example.com/hop2.png',
            'https://images.example.com/hop3.png',
            'https://images.example.com/hop4.png',
            'https://images.example.com/to-metadata.png',
            'https://images.example.com/to-rebind.png',
        ], $reached, 'four redirects stop at the limit; unsafe hops are never requested');
        foreach (WP::$http_calls as $call) {
            $this->assertSame(0, $call['args']['redirection'], 'redirects are followed by the plugin, hop by hop');
        }

        // Three redirects are allowed.
        WP::$http_calls = [];
        $this->provider->push(new ImageResult(null, 'https://images.example.com/hop2.png'));
        WP::$http = static function (string $url, array $args): array|\WP_Error {
            if (preg_match('#/hop([2-4])\.png$#', $url, $m) === 1) {
                return ['response' => ['code' => 302], 'headers' => ['location' => 'https://images.example.com/hop' . ((int) $m[1] + 1) . '.png'], 'body' => ''];
            }
            return ['response' => ['code' => 200], 'body' => Img::png(1600, 900)];
        };
        $this->assertSame(Reason::SUGGESTION_READY, $this->generate($this->post())->reason);
    }

    public function test_case_39_account_errors_pause_the_provider(): void
    {
        $this->provider->push(new AuthException('401'));
        $first = (string) $this->queue($this->post())->request_id;
        $second = (string) $this->queue($this->post())->request_id;
        Queue::run_batch();
        $this->assertSame(Reason::AUTH_FAILED, $this->storage->get_request($first)['reason']);
        $this->assertSame('queued', $this->storage->get_request($second)['state']);
        $this->assertSame(Reason::PROVIDER_PAUSED, $this->storage->get_request($second)['reason']);
        $this->assertCount(1, $this->provider->requests);
        $this->assertSame(0, Pauses::get('fake')['until']);
        $this->assertSame(1, $this->fired('ffp_provider_paused'));
        $this->assertSame(1, count(array_filter($this->logs, static fn ($l) => $l[0] === 'Provider paused.')));

        $this->clock->advance(86400 * 3);
        $this->assertTrue(Pauses::is_paused('fake'), 'auth pauses until resumed');
        Pauses::resume('fake');

        $this->settings(['quota_pause' => 7200]);
        $this->provider->push(new QuotaException('no balance'));
        $this->assertSame(Reason::QUOTA_EXHAUSTED, $this->generate($this->post())->reason);
        $this->assertSame($this->clock->now() + 7200, Pauses::get('fake')['until']);
        $this->clock->advance(7201);
        $this->assertFalse(Pauses::is_paused('fake'));
    }

    public function test_case_40_rate_limit_requeues_with_the_hint(): void
    {
        foreach ([[120, 120], [null, 300], [7200, 3600]] as [$hint, $wait]) {
            $this->provider->push(new RateLimitException('429', $hint));
            $record = $this->generate($this->post());
            $this->assertSame(Reason::RATE_LIMITED, $record->reason);
            $this->assertSame('queued', $record->state());
            $this->assertSame($this->clock->now() + $wait, (int) $record->row['next_run']);
        }
        $this->assertSame(3, $this->day_count());
    }

    public function test_case_41_refusal_is_final_and_shown(): void
    {
        $this->settings(['auto_mode' => 'on']);
        $this->provider->push(new PromptRefusedException(str_repeat('policy ', 200)));
        $post = $this->post();
        $record = $this->run_request((string) $this->queue($post, ['manual' => false])->request_id);
        $this->assertSame(Reason::PROMPT_REFUSED, $record->reason);
        $this->assertSame('failed', $record->state());
        $this->assertSame(500, mb_strlen($record->row['record']['provider_message']));
        $this->assertSame(Reason::AUTO_ATTEMPTED, $this->queue($post, ['manual' => false])->reason);
        $this->assertSame(0, $this->storage->count_requests(['queued']));
    }

    public function test_case_42_transient_errors_back_off_then_fail(): void
    {
        $this->provider->push(TransientException::timeout(), TransientException::timeout(), TransientException::timeout());
        $id = (string) $this->queue($this->post())->request_id;
        $start = $this->clock->now();
        $first = $this->run_request($id);
        $this->assertSame([Reason::TIMEOUT, 'queued', $start + 60], [$first->reason, $first->state(), (int) $first->row['next_run']]);
        $this->clock->advance(60);
        $second = $this->run_request($id);
        $this->assertSame($this->clock->now() + 300, (int) $second->row['next_run']);
        $this->clock->advance(300);
        $third = $this->run_request($id);
        $this->assertSame(['failed', Reason::TIMEOUT, 3], [$third->state(), $third->reason, (int) $third->row['attempts']]);
        $this->assertSame(3, $this->day_count());
    }

    public function test_case_44_neither_bytes_nor_url_is_bad_response(): void
    {
        $this->provider->push(new ImageResult());
        $record = $this->generate($this->post());
        $this->assertSame([Reason::BAD_RESPONSE, 'failed'], [$record->reason, $record->state()]);
    }

    public function test_case_45_unexpected_throwable_is_transient_and_logged(): void
    {
        $this->settings(['attempts' => 1]);
        $this->provider->push(new \TypeError('wrong type'));
        $record = $this->generate($this->post());
        $this->assertSame(Reason::TRANSIENT, $record->reason);
        $this->assertTrue($this->has_log('TypeError'));
    }
}
