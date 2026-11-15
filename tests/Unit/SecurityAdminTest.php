<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Unit;

use IndianOpinion\FeaturedFromPrompt\Admin\SettingsPage;
use IndianOpinion\FeaturedFromPrompt\Cleanup;
use IndianOpinion\FeaturedFromPrompt\Cli\Command;
use IndianOpinion\FeaturedFromPrompt\Paths;
use IndianOpinion\FeaturedFromPrompt\Placeholders;
use IndianOpinion\FeaturedFromPrompt\Privacy;
use IndianOpinion\FeaturedFromPrompt\Provider\GenericHttpProvider;
use IndianOpinion\FeaturedFromPrompt\Provider\PromptRefusedException;
use IndianOpinion\FeaturedFromPrompt\Queue;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\Rest;
use IndianOpinion\FeaturedFromPrompt\Secrets;
use IndianOpinion\FeaturedFromPrompt\Settings;
use IndianOpinion\FeaturedFromPrompt\Suggestions;
use IndianOpinion\FeaturedFromPrompt\Tests\Support\Img;
use IndianOpinion\FeaturedFromPrompt\Tests\TestCase;
use IndianOpinion\FeaturedFromPrompt\Uninstall;
use WP;

/**
 * The cross-cutting acceptance tests after section 12's numbered cases.
 */
final class SecurityAdminTest extends TestCase
{
    public function test_only_a_person_makes_a_generated_image_featured(): void
    {
        $illegal = [];
        $allowed = false;
        $watch = function ($meta_id, $object_id, $key, $value) use (&$illegal, &$allowed): void {
            if ($key === '_thumbnail_id' && Suggestions::is_generated((int) $value) && !$allowed) {
                $illegal[] = [$object_id, $value];
            }
        };
        add_action('added_post_meta', $watch, 1, 4);
        add_action('updated_post_meta', $watch, 1, 4);

        $this->settings(['auto_mode' => 'on', 'suggest_on_save' => true, 'suggest_on_publish' => true, 'post_cap' => 20, 'day_cap' => 100]);
        $posts = [];
        for ($i = 0; $i < 4; $i++) {
            $posts[] = $post = $this->post();
            do_action('save_post', $post, get_post($post), true);
            do_action('transition_post_status', 'publish', 'draft', get_post($post));
        }
        do_action('ffp_request', $this->post(), []);
        Queue::backfill(['force' => true, 'spread' => true]);
        Queue::run_batch();
        Queue::run_batch();
        Queue::run_batch();

        // The placeholder policy refuses a generated image even if configured.
        $generated = Suggestions::pending_for_post($posts[0])[0];
        $this->settings(['on_failure' => 'placeholder', 'fallback_placeholder' => $generated]);
        $this->provider->push(new PromptRefusedException('no'));
        $this->generate($this->post());

        $this->clock->advance(40 * 86400);
        Cleanup::run();

        $this->assertSame([], $illegal);
        foreach (WP::$posts as $post) {
            if ($post->post_type === 'post') {
                $this->assertFalse(Suggestions::is_generated((int) get_post_meta($post->ID, '_thumbnail_id', true)));
            }
        }

        $allowed = true;
        $fresh = $this->post();
        $id = $this->generate($fresh)->attachment_id();
        $this->assertTrue(Suggestions::accept($id, self::EDITOR)->ok);
        $this->assertSame($id, (int) get_post_meta($fresh, '_thumbnail_id', true));
    }

    public function test_uninstall_removes_what_it_created_and_keeps_accepted(): void
    {
        $post = $this->post();
        $accepted = $this->generate($post)->attachment_id();
        Suggestions::accept($accepted, self::EDITOR);
        $pending = $this->generate($post)->attachment_id();
        $rejected = $this->generate($this->post())->attachment_id();
        Suggestions::reject($rejected, self::EDITOR);
        update_post_meta($post, '_ffp_opt_out', '1');
        Secrets::set('fake', 'api_key', 'top-secret-value');
        Secrets::set('generic-http', 'auth_value', 'another-secret');
        wp_schedule_event(1, 'daily', Cleanup::CRON_HOOK);

        Uninstall::run();

        $this->assertNull(get_post($pending));
        $this->assertNull(get_post($rejected));
        $this->assertNotNull(get_post($accepted));
        $this->assertTrue(Suggestions::is_generated($accepted), 'accepted images keep their record');
        $this->assertSame('', get_post_meta($post, '_ffp_opt_out', true));
        $this->assertSame('', get_post_meta($post, Queue::LAST_META, true));
        $this->assertFalse(get_option(Settings::OPTION));
        $this->assertFalse(get_option(Secrets::option_name('fake', 'api_key')));
        $this->assertFalse(get_option(Secrets::option_name('generic-http', 'auth_value')));
        $this->assertFalse(wp_next_scheduled(Cleanup::CRON_HOOK));
        $this->assertSame([], $this->storage->requests[1] ?? []);
        $this->assertDirectoryDoesNotExist(Paths::private_dir());
    }

    public function test_keep_everything_on_uninstall_only_clears_events(): void
    {
        $this->settings(['keep_on_uninstall' => true]);
        $pending = $this->generate($this->post())->attachment_id();
        wp_schedule_event(1, 'daily', Cleanup::CRON_HOOK);
        Uninstall::run();
        $this->assertNotNull(get_post($pending));
        $this->assertNotFalse(get_option(Settings::OPTION));
        $this->assertFalse(wp_next_scheduled(Cleanup::CRON_HOOK));
    }

    public function test_secrets_never_leak(): void
    {
        $secret = 'Bearer sk-live-0123456789abcdef';
        $this->settings(['provider' => 'generic-http', 'log_requests' => true, 'provider_settings' => ['generic-http' => [
            'endpoint' => 'https://api.images.example.com/v1', 'image_path' => 'data.0.b64', 'refusal_marker' => 'policy',
        ]]]);
        Secrets::set('generic-http', 'auth_value', $secret);
        $log_file = WP::$root . '/error.log';
        $previous_log = (string) ini_get('error_log');
        ini_set('error_log', $log_file);
        WP::$http = static fn () => ['response' => ['code' => 500], 'body' => 'echo: ' . $secret];
        $this->settings(['attempts' => 1]);
        $record = $this->generate($post = $this->post());
        WP::$http = static fn () => ['response' => ['code' => 200], 'body' => json_encode(['data' => [['b64' => base64_encode(Img::png(1600, 900))]]])];
        $ok = $this->generate($post);
        ini_set('error_log', $previous_log);
        $written = (string) file_get_contents($log_file);
        $this->assertStringContainsString('[featured-from-prompt]', $written);

        $haystacks = [
            $written,
            json_encode($this->logs),
            json_encode($this->storage->requests),
            json_encode(\IndianOpinion\FeaturedFromPrompt\status($post)),
            json_encode(get_option('ffp_recent')),
            json_encode(Suggestions::record($ok->attachment_id())),
            json_encode(WP::$meta),
            json_encode(get_option(Settings::OPTION)),
        ];
        foreach ($haystacks as $i => $text) {
            $this->assertStringNotContainsString('sk-live-0123456789abcdef', (string) $text, 'haystack ' . $i);
        }
        $this->assertSame(Reason::TRANSIENT, $record->reason);

        \IndianOpinion\FeaturedFromPrompt\Constants::override(Secrets::constant_name('generic-http', 'auth_value'), 'from-constant');
        $this->assertSame('from-constant', Secrets::get('generic-http', 'auth_value'));
        $this->assertSame('FFP_SECRET_GENERIC_HTTP_AUTH_VALUE', Secrets::constant_name('generic-http', 'auth_value'));
    }

    public function test_settings_page_rejects_bad_nonce_and_missing_capability(): void
    {
        $this->assertSame(Reason::BAD_NONCE, SettingsPage::process_save(['_ffp_nonce' => 'forged', 'ffp' => []])['reason']);
        $this->assertSame(Reason::BAD_NONCE, SettingsPage::process_test(['_ffp_nonce' => 'forged', 'provider' => 'fixture'])['reason']);
        WP::$current_user = 0;
        $this->assertSame(Reason::FORBIDDEN, SettingsPage::process_save(['_ffp_nonce' => 'valid-ffp_settings_save'])['reason']);
        WP::$current_user = self::EDITOR;

        $ok = SettingsPage::process_save([
            '_ffp_nonce' => 'valid-ffp_settings_save',
            'ffp' => ['enabled' => '1', 'provider' => 'fake', 'day_cap' => '99999', 'min_ratio' => '3', 'max_ratio' => '2', 'auto_mode' => 'evil', 'post_types' => ['post', '<script>']],
            'ffp_secret' => ['fake' => ['api_key' => 'new-key']],
        ]);
        $this->assertTrue($ok['ok']);
        $saved = get_option(Settings::OPTION);
        $this->assertSame(10000, $saved['day_cap']);
        $this->assertSame([1.3, 2.1], [$saved['min_ratio'], $saved['max_ratio']]);
        $this->assertSame('off', $saved['auto_mode']);
        $this->assertSame(['post', 'script'], $saved['post_types']);
        $this->assertSame('new-key', Secrets::get('fake', 'api_key'));
        $this->assertArrayNotHasKey('api_key', $saved['provider_settings']['fake'] ?? []);

        SettingsPage::process_save(['_ffp_nonce' => 'valid-ffp_settings_save', 'ffp' => ['enabled' => '1', 'provider' => '']]);
        $this->assertFalse(get_option(Settings::OPTION)['enabled'], 'enabled stays off without a provider');

        SettingsPage::process_save(['_ffp_nonce' => 'valid-ffp_settings_save', 'ffp' => ['provider' => 'fake'], 'ffp_secret_clear' => ['fake' => ['api_key' => '1']]]);
        $this->assertSame('', Secrets::get('fake', 'api_key'));

        $test = SettingsPage::process_test(['_ffp_nonce' => 'valid-ffp_settings_test', 'provider' => 'fixture']);
        $this->assertTrue($test['ok'], $test['message']);
        $this->assertSame(1, $this->storage->count('day', $this->clock->day()));
    }

    public function test_every_rest_route_rejects_a_user_without_capability(): void
    {
        Rest::routes();
        $post = $this->post();
        $attachment = $this->generate($post)->attachment_id();
        WP::add_user(5, ['read']);
        WP::$current_user = 5;
        $this->assertCount(9, WP::$routes);
        foreach (WP::$routes as $route => $args) {
            $id = str_contains($route, '/suggestions/') ? $attachment : $post;
            $result = ($args['permission_callback'])(new \WP_REST_Request($args['methods'], $route, ['id' => $id]));
            $this->assertInstanceOf(\WP_Error::class, $result, $route);
            $this->assertSame(403, $result->get_error_data()['status'], $route);
        }

        // Generate needs the generate capability on top of edit_post.
        WP::add_user(6, ['edit_post', 'upload_files']);
        WP::$current_user = 6;
        $this->assertInstanceOf(\WP_Error::class, Rest::can_generate(new \WP_REST_Request('POST', '', ['id' => $post])));
        $this->assertTrue(Rest::can_edit_post(new \WP_REST_Request('GET', '', ['id' => $post])));
        add_filter('ffp_capability_generate', static fn () => 'upload_files');
        $this->assertTrue(Rest::can_generate(new \WP_REST_Request('POST', '', ['id' => $post])));
    }

    public function test_rest_errors_and_preview_route(): void
    {
        $post = $this->post();
        $this->queue($post);
        $dup = Rest::generate(new \WP_REST_Request('POST', '', ['id' => $post]));
        $this->assertInstanceOf(\WP_Error::class, $dup);
        $this->assertSame([Reason::ALREADY_QUEUED, 409], [$dup->get_error_code(), $dup->get_error_data()['status']]);

        $this->settings(['day_cap' => 0]);
        $other = $this->post();
        $id = (string) $this->queue($other)->request_id;
        $this->run_request($id);
        $this->assertSame(Reason::CAP_DAY, Rest::error(Reason::CAP_DAY)->get_error_code());
        $this->assertSame(429, Rest::error(Reason::CAP_DAY)->get_error_data()['status']);
        $this->settings(['day_cap' => 20]);

        $cancel = Rest::cancel(new \WP_REST_Request('POST', '', ['id' => $post]));
        $this->assertInstanceOf(\WP_REST_Response::class, $cancel);

        $attachment = $this->generate($this->post())->attachment_id();
        $response = Rest::preview(new \WP_REST_Request('GET', '', ['id' => $attachment]));
        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame('image/png', $response->headers['Content-Type']);
        $this->assertSame('private, no-store', $response->headers['Cache-Control']);
        ob_start();
        $served = Rest::serve_raw(false, $response, new \WP_REST_Request('GET', '/featured-from-prompt/v1/suggestions/' . $attachment . '/preview'), null);
        $bytes = (string) ob_get_clean();
        $this->assertTrue($served);
        $this->assertSame("\x89PNG", substr($bytes, 0, 4));
        $this->assertFalse(Rest::serve_raw(false, $response, new \WP_REST_Request('GET', '/wp/v2/posts'), null));
    }

    public function test_privacy_exporter_and_eraser(): void
    {
        $post = $this->post();
        $id = $this->generate($post)->attachment_id();
        Suggestions::reject($id, self::EDITOR, 'no');
        $export = Privacy::export('editor@example.org');
        $groups = array_column($export['data'], 'group_id');
        $this->assertContains('ffp-requests', $groups);
        $this->assertContains('ffp-suggestions', $groups);
        $this->assertSame([], Privacy::export('nobody@example.org')['data']);

        $erase = Privacy::erase('editor@example.org');
        $this->assertTrue($erase['items_removed']);
        $this->assertSame(0, Suggestions::record($id)['user_id']);
        foreach (get_post_meta($id, Suggestions::META_HISTORY, true) as $entry) {
            $this->assertSame(0, $entry['user']);
        }
        foreach ($this->storage->requests[1] as $row) {
            $this->assertSame(0, $row['user_id']);
        }
        $this->assertSame([], Privacy::export('editor@example.org')['data']);
    }

    public function test_schema_missing_refuses_to_run(): void
    {
        $id = (string) $this->queue($post = $this->post())->request_id;
        $this->storage->missing = ['wp_ffp_requests.runner'];
        \IndianOpinion\FeaturedFromPrompt\Plugin::schema_missing(true);
        $this->assertSame(Reason::SCHEMA_MISSING, $this->queue($this->post())->reason);
        $this->assertSame(Reason::SCHEMA_MISSING, $this->run_request($id)->reason);
        $this->assertSame([], $this->provider->requests);
        $this->assertFalse(\IndianOpinion\FeaturedFromPrompt\status($post)['schema_ok']);
    }

    public function test_cli_exit_codes_and_commands(): void
    {
        $cli = new Command();
        $post = $this->post();

        $cli->generate([(string) $post], ['dry-run' => true]);
        $this->assertNull(\WP_CLI::$halted);
        $this->assertStringContainsString(Reason::WOULD_REQUEST, \WP_CLI::$log[0]);

        \WP_CLI::reset();
        $cli->generate(['abc'], []);
        $this->assertSame(Command::EXIT_BAD_ARGS, \WP_CLI::$halted);

        \WP_CLI::reset();
        $cli->generate([(string) $post], ['now' => true]);
        $this->assertNull(\WP_CLI::$halted);
        $this->assertStringContainsString(Reason::SUGGESTION_READY, \WP_CLI::$log[0]);

        \WP_CLI::reset();
        $this->provider->push(new PromptRefusedException('no'));
        $cli->generate([(string) $this->post()], ['now' => true]);
        $this->assertSame(Command::EXIT_FAILURE, \WP_CLI::$halted);

        \WP_CLI::reset();
        $attachment = Suggestions::pending_for_post($post)[0];
        $cli->accept([(string) $attachment], ['user' => (string) self::EDITOR]);
        $this->assertStringStartsWith('Success', \WP_CLI::$log[0]);
        \WP_CLI::reset();
        $cli->accept([(string) $attachment], ['user' => '999']);
        $this->assertSame(Command::EXIT_BAD_ARGS, \WP_CLI::$halted);

        \WP_CLI::reset();
        $cli->caps([], []);
        $this->assertStringContainsString('2 of 20 used', \WP_CLI::$log[0]);

        \WP_CLI::reset();
        $cli->backfill([], ['dry-run' => true]);
        $this->assertNull(\WP_CLI::$halted);

        \WP_CLI::reset();
        $this->settings(['enabled' => false]);
        $cli->generate([(string) $post], []);
        $this->assertSame(Command::EXIT_DISABLED, \WP_CLI::$halted);
        \WP_CLI::reset();
        $cli->queue(['run'], []);
        $this->assertSame(Command::EXIT_DISABLED, \WP_CLI::$halted);
        \WP_CLI::reset();
        $cli->queue(['bogus'], []);
        $this->assertSame(Command::EXIT_BAD_ARGS, \WP_CLI::$halted);
    }

    public function test_placeholder_marker_is_cleared_on_accept(): void
    {
        $fallback = $this->image_attachment();
        $this->settings(['on_failure' => 'placeholder', 'fallback_placeholder' => $fallback]);
        $post = $this->post();
        $this->provider->push(new PromptRefusedException('no'));
        $this->generate($post);
        $this->assertIsArray(get_post_meta($post, Placeholders::SET_META, true));
        $id = $this->generate($post)->attachment_id();
        Suggestions::accept($id, self::EDITOR);
        $this->assertSame('', get_post_meta($post, Placeholders::SET_META, true));
    }
}
