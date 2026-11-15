<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Unit;

use IndianOpinion\FeaturedFromPrompt\Constants;
use IndianOpinion\FeaturedFromPrompt\Provider\AuthException;
use IndianOpinion\FeaturedFromPrompt\Provider\BadResponseException;
use IndianOpinion\FeaturedFromPrompt\Provider\ConfigException;
use IndianOpinion\FeaturedFromPrompt\Provider\FixtureProvider;
use IndianOpinion\FeaturedFromPrompt\Provider\GenericHttpProvider;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageRequest;
use IndianOpinion\FeaturedFromPrompt\Provider\PromptRefusedException;
use IndianOpinion\FeaturedFromPrompt\Provider\QuotaException;
use IndianOpinion\FeaturedFromPrompt\Provider\RateLimitException;
use IndianOpinion\FeaturedFromPrompt\Provider\TransientException;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\Secrets;
use IndianOpinion\FeaturedFromPrompt\Suggestions;
use IndianOpinion\FeaturedFromPrompt\Tests\Support\Img;
use IndianOpinion\FeaturedFromPrompt\Tests\TestCase;
use WP;

final class GenericHttpProviderTest extends TestCase
{
    private const SECRET = 'Bearer sk-test-abcdefghijklmnop';

    /** @return array<string, string> */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'endpoint' => 'https://api.images.example.com/v1/generate',
            'method' => 'POST',
            'auth_header' => 'Authorization',
            'auth_value' => self::SECRET,
            'body_template' => GenericHttpProvider::DEFAULT_BODY,
            'image_kind' => 'base64',
            'image_path' => 'data.0.b64',
            'revised_prompt_path' => 'data.0.revised',
            'model_path' => 'model',
            'refusal_marker' => 'content_policy',
        ], $overrides);
    }

    private function request(): ImageRequest
    {
        return new ImageRequest("A \"quoted\" prompt\nwith a newline", 'blurry', 1536, 864, '16:9', 'png', 30, 'req-1', 5);
    }

    private function respond(int $code, string $body, array $headers = []): void
    {
        WP::$http = static fn () => ['response' => ['code' => $code], 'body' => $body, 'headers' => $headers];
    }

    public function test_body_template_is_json_escaped_and_headers_sent(): void
    {
        $this->respond(200, (string) json_encode(['data' => [['b64' => base64_encode(Img::png(1600, 900)), 'revised' => 'better']], 'model' => 'm-2']));
        $result = (new GenericHttpProvider())->generate($this->request(), $this->config(['extra_headers' => "X-Team: art\nbad header line"]));
        $call = WP::$http_calls[0];
        $body = json_decode($call['args']['body'], true);
        $this->assertSame("A \"quoted\" prompt\nwith a newline", $body['prompt']);
        $this->assertSame(1536, $body['width']);
        $this->assertSame(self::SECRET, $call['args']['headers']['Authorization']);
        $this->assertSame('art', $call['args']['headers']['X-Team']);
        $this->assertTrue($call['safe']);
        $this->assertSame('better', $result->revised_prompt);
        $this->assertSame('m-2', $result->model);
        $this->assertSame(Img::png(1600, 900), $result->bytes);
    }

    public function test_url_response_by_dot_path(): void
    {
        $this->respond(200, '{"output":{"url":"https://cdn.example.com/a.png"}}');
        $result = (new GenericHttpProvider())->generate($this->request(), $this->config(['image_kind' => 'url', 'image_path' => 'output.url']));
        $this->assertSame('https://cdn.example.com/a.png', $result->url);
        $this->assertNull($result->bytes);
    }

    public function test_status_mapping(): void
    {
        $provider = new GenericHttpProvider();
        $cases = [
            [401, '', AuthException::class],
            [403, '', AuthException::class],
            [402, '', QuotaException::class],
            [429, '', RateLimitException::class],
            [400, '{"error":{"code":"content_policy","message":"Refused for policy."}}', PromptRefusedException::class],
            [400, '{"error":"bad size"}', BadResponseException::class],
            [404, 'nope', BadResponseException::class],
            [500, 'oops', TransientException::class],
            [503, 'later', TransientException::class],
            [200, 'not json', BadResponseException::class],
        ];
        foreach ($cases as [$code, $body, $class]) {
            $this->respond($code, $body, ['retry-after' => '120']);
            try {
                $provider->generate($this->request(), $this->config());
                $this->fail('no exception for ' . $code);
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e, (string) $code);
                if ($e instanceof RateLimitException) {
                    $this->assertSame(120, $e->retryAfter());
                }
                if ($e instanceof PromptRefusedException) {
                    $this->assertSame('Refused for policy.', $e->providerMessage());
                }
            }
        }
        WP::$http = static fn () => new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
        try {
            $provider->generate($this->request(), $this->config());
        } catch (TransientException $e) {
            $this->assertTrue($e->isTimeout());
        }
        WP::$http = static fn () => new \WP_Error('http_request_failed', 'Connection reset');
        try {
            $provider->generate($this->request(), $this->config());
        } catch (TransientException $e) {
            $this->assertFalse($e->isTimeout());
        }
        $this->assertSame(0, GenericHttpProvider::retry_after(gmdate('D, d M Y H:i:s', time() - 10) . ' GMT'));
        $this->assertNull(GenericHttpProvider::retry_after('soon'));
    }

    public function test_endpoint_must_be_https_and_public(): void
    {
        $provider = new GenericHttpProvider();
        $this->assertFalse($provider->is_configured($this->config(['endpoint' => 'http://api.example.com/x'])));
        $this->assertFalse($provider->is_configured($this->config(['endpoint' => 'https://192.168.1.10/x'])));
        $this->assertFalse($provider->is_configured($this->config(['endpoint' => 'https://localhost/x'])));
        $this->assertTrue($provider->is_configured($this->config()));
        Constants::override('FFP_ALLOW_LOCAL_ENDPOINT', true);
        $local = $this->config(['endpoint' => 'https://192.168.1.10/x']);
        $this->assertTrue($provider->is_configured($local));
        $this->assertSame(['192.168.1.10'], $provider->capabilities_from($local)->local_hosts);
        $this->respond(200, '{"data":[{"b64":"' . base64_encode(Img::png(1600, 900)) . '"}]}');
        $provider->generate($this->request(), $local);
        $this->assertFalse(WP::$http_calls[0]['safe'], 'a declared local endpoint uses the plain HTTP call');

        $this->expectException(ConfigException::class);
        $provider->generate($this->request(), $this->config(['endpoint' => 'http://api.example.com']));
    }

    public function test_bad_body_template_is_a_config_error(): void
    {
        $this->expectException(ConfigException::class);
        (new GenericHttpProvider())->generate($this->request(), $this->config(['body_template' => '{"prompt": {prompt}']));
    }

    public function test_secret_never_in_exception_messages(): void
    {
        $this->respond(500, 'Upstream echoed your header: ' . self::SECRET);
        try {
            (new GenericHttpProvider())->generate($this->request(), $this->config());
        } catch (TransientException $e) {
            $this->assertStringNotContainsString('sk-test-abcdefghijklmnop', $e->getMessage());
            $this->assertStringContainsString('[redacted]', $e->getMessage());
        }
    }

    public function test_case_43_two_images_store_only_the_first(): void
    {
        $this->settings(['provider' => 'generic-http', 'provider_settings' => ['generic-http' => array_diff_key($this->config(), ['auth_value' => 1])]]);
        Secrets::set('generic-http', 'auth_value', self::SECRET);
        $this->respond(200, (string) json_encode(['data' => [
            ['b64' => base64_encode(Img::png(1600, 900))],
            ['b64' => base64_encode(Img::png(1792, 1024))],
        ]]));
        $record = $this->generate($this->post());
        $this->assertSame(Reason::SUGGESTION_READY, $record->reason);
        $this->assertSame(1600, Suggestions::record($record->attachment_id())['real_width']);
        $this->assertCount(1, Suggestions::pending_for_post((int) $record->row['post_id']));
        $this->assertSame(1, json_decode(WP::$http_calls[0]['args']['body'], true)['n']);
    }

    public function test_fixture_provider_variants(): void
    {
        $this->settings(['provider' => 'fixture', 'post_cap' => 10]);
        $expect = ['wide' => Reason::SUGGESTION_READY, 'square' => Reason::BAD_ASPECT, 'tiny' => Reason::TOO_SMALL, 'not_image' => Reason::NOT_IMAGE];
        foreach ($expect as $variant => $reason) {
            $this->settings(['provider_settings' => ['fixture' => ['variant' => $variant]]]);
            $this->assertSame($reason, $this->generate($this->post())->reason, $variant);
        }
        $this->assertSame(4, $this->storage->count('day', $this->clock->day()), 'fixture requests count against the caps');
        $this->assertSame([], WP::$http_calls);
        $this->assertSame('Test fixture (no API calls)', (new FixtureProvider())->label());
    }

    public function test_submit_then_poll(): void
    {
        $slept = 0;
        $now = 1000;
        GenericHttpProvider::set_poll_hooks(function (int $s) use (&$slept, &$now): void {
            $slept += $s;
            $now += $s;
        }, function () use (&$now): int {
            return $now;
        });
        $polls = 0;
        WP::$http = function (string $url, array $args) use (&$polls): array {
            if ($args['method'] === 'POST') {
                return ['response' => ['code' => 201], 'body' => '{"id":"j1","urls":{"get":"https://api.images.example.com/v1/jobs/j1"}}'];
            }
            $polls++;
            if ($polls < 3) {
                return ['response' => ['code' => 200], 'body' => '{"status":"processing"}'];
            }
            return ['response' => ['code' => 200], 'body' => '{"status":"succeeded","output":{"url":"https://cdn.example.com/out.png"}}'];
        };
        $config = $this->config(['poll_url_path' => 'urls.get', 'image_kind' => 'url', 'image_path' => 'output.url', 'poll_interval' => '2']);
        $result = (new GenericHttpProvider())->generate($this->request(), $config);
        $this->assertSame('https://cdn.example.com/out.png', $result->url);
        $this->assertSame(3, $polls);
        $this->assertSame(6, $slept);
        $this->assertSame(self::SECRET, WP::$http_calls[1]['args']['headers']['Authorization'], 'same host keeps the auth header');

        // A job URL on another host never receives the key.
        WP::$http_calls = [];
        $polls = 5;
        WP::$http = function (string $url, array $args): array {
            if ($args['method'] === 'POST') {
                return ['response' => ['code' => 201], 'body' => '{"urls":{"get":"https://jobs.other.example.net/j2"}}'];
            }
            return ['response' => ['code' => 200], 'body' => '{"status":"succeeded","output":{"url":"https://cdn.example.com/out.png"}}'];
        };
        (new GenericHttpProvider())->generate($this->request(), $config);
        $this->assertArrayNotHasKey('Authorization', WP::$http_calls[1]['args']['headers']);

        // Failed jobs, refusals and timeouts.
        WP::$http = static fn (string $url, array $args): array => $args['method'] === 'POST'
            ? ['response' => ['code' => 201], 'body' => '{"urls":{"get":"https://api.images.example.com/v1/jobs/j3"}}']
            : ['response' => ['code' => 200], 'body' => '{"status":"failed","error":"content_policy: refused"}'];
        try {
            (new GenericHttpProvider())->generate($this->request(), $config);
            $this->fail('expected a refusal');
        } catch (PromptRefusedException $e) {
            $this->assertStringContainsString('content_policy', $e->providerMessage());
        }
        WP::$http = static fn (string $url, array $args): array => $args['method'] === 'POST'
            ? ['response' => ['code' => 201], 'body' => '{"urls":{"get":"https://api.images.example.com/v1/jobs/j4"}}']
            : ['response' => ['code' => 200], 'body' => '{"status":"processing"}'];
        try {
            (new GenericHttpProvider())->generate($this->request(), $config);
            $this->fail('expected a timeout');
        } catch (TransientException $e) {
            $this->assertTrue($e->isTimeout());
        }
        WP::$http = static fn (string $url, array $args): array => ['response' => ['code' => 201], 'body' => '{"urls":{"get":"http://api.images.example.com/j5"}}'];
        $this->expectException(BadResponseException::class);
        (new GenericHttpProvider())->generate($this->request(), $config);
    }

    protected function tearDown(): void
    {
        GenericHttpProvider::set_poll_hooks(null, null);
        parent::tearDown();
    }
}
