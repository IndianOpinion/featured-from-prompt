<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

use IndianOpinion\FeaturedFromPrompt\Provider\AuthException;
use IndianOpinion\FeaturedFromPrompt\Provider\BadResponseException;
use IndianOpinion\FeaturedFromPrompt\Provider\ConfigException;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageProvider;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageRequest;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageResult;
use IndianOpinion\FeaturedFromPrompt\Provider\PromptRefusedException;
use IndianOpinion\FeaturedFromPrompt\Provider\QuotaException;
use IndianOpinion\FeaturedFromPrompt\Provider\RateLimitException;
use IndianOpinion\FeaturedFromPrompt\Provider\TransientException;

/**
 * Runs one request: guards, reservation, call, checks, store (4.4 to 4.7).
 *
 * This is the only place that calls ImageProvider::generate() for a post.
 */
final class Worker
{
    private const BACKOFF = [60, 300, 900, 900, 900];

    /**
     * @param array<string, mixed> $args dry_run (bool).
     */
    public static function run(string $request_id, array $args = []): RequestRecord
    {
        $dry = !empty($args['dry_run']);
        $storage = Plugin::storage();
        $row = $storage->get_request($request_id);
        if ($row === null) {
            return new RequestRecord(Reason::NOT_FOUND);
        }
        if (in_array($row['state'], Queue::FINAL_STATES, true)) {
            return new RequestRecord(Reason::ALREADY_DONE, $row);
        }
        if ($row['state'] === 'running') {
            return new RequestRecord(Reason::LOCKED, $row);
        }
        if (Plugin::schema_missing() !== []) {
            return new RequestRecord(Reason::SCHEMA_MISSING, $row);
        }

        $post_id = (int) $row['post_id'];
        $auto = !in_array($row['trigger_type'], Queue::MANUAL_TRIGGERS, true);
        $clock = Plugin::clock();
        $now = $clock->now();
        $settings = Settings::all();

        // 25: kill switch. Queue left unchanged.
        if (!Settings::enabled()) {
            return $dry ? new RequestRecord(Reason::DISABLED, $row) : self::park($row, Reason::DISABLED, $now + 60);
        }

        // 26: provider.
        $provider_id = Settings::provider_id();
        if ($provider_id === '') {
            return $dry ? new RequestRecord(Reason::PROVIDER_NOT_CONFIGURED, $row) : self::finish($row, 'skipped', Reason::PROVIDER_NOT_CONFIGURED);
        }
        $provider = Plugin::providers()->get($provider_id);
        if ($provider === null) {
            return $dry ? new RequestRecord(Reason::NO_PROVIDER, $row) : self::finish($row, 'skipped', Reason::NO_PROVIDER);
        }
        $provider_settings = Registry::settings_for($provider);
        if (!$provider->is_configured($provider_settings)) {
            return $dry ? new RequestRecord(Reason::PROVIDER_NOT_CONFIGURED, $row) : self::finish($row, 'skipped', Reason::PROVIDER_NOT_CONFIGURED);
        }

        // 27: provider pause. Not counted; waits.
        $pause = Pauses::get($provider_id);
        if ($pause !== null) {
            $next = $pause['until'] > 0 ? min($pause['until'], $now + 300) : $now + 300;
            return $dry ? new RequestRecord(Reason::PROVIDER_PAUSED, $row) : self::park($row, Reason::PROVIDER_PAUSED, $next);
        }

        // 28: post still eligible.
        $eligible = Eligibility::check($post_id, $auto, 'worker');
        if ($eligible !== null) {
            return $dry ? new RequestRecord($eligible, $row) : self::finish($row, 'skipped', $eligible);
        }

        // 82: queue age limit for auto requests.
        if ($auto && (int) $row['created_at'] < $now - (int) $settings['queue_age']) {
            return $dry ? new RequestRecord(Reason::EXPIRED_IN_QUEUE, $row) : self::finish($row, 'skipped', Reason::EXPIRED_IN_QUEUE);
        }

        $caps = $provider->capabilities();
        $max_chars = min((int) $settings['prompt_limit'], $caps->max_prompt_chars > 0 ? $caps->max_prompt_chars : PHP_INT_MAX);
        [$prompt, $cut] = PromptBuilder::cut(trim((string) $row['prompt']), $max_chars);
        if ($prompt === '') {
            return $dry ? new RequestRecord(Reason::PROMPT_EMPTY, $row) : self::finish($row, 'skipped', Reason::PROMPT_EMPTY);
        }

        if ($dry) {
            return new RequestRecord(self::dry_caps($post_id) ?? Reason::WOULD_REQUEST, $row);
        }

        // 34: per-post lock, taken before any counter is touched.
        $timeout = (int) $settings['timeout'];
        if (!$storage->acquire_lock('post_' . $post_id, $timeout + 60, $now)) {
            return self::park($row, Reason::LOCKED, $now + 60);
        }

        try {
            return self::reserve_and_call($row, $provider, $provider_settings, $prompt, $cut, $settings);
        } finally {
            $storage->release_lock('post_' . $post_id);
        }
    }

    /**
     * Guards without reservation, for log-only mode and dry runs.
     *
     * @return string|null The first failing reason, or null when a request would run.
     */
    public static function dry_guards(int $post_id, bool $auto): ?string
    {
        if (!Settings::enabled()) {
            return Reason::DISABLED;
        }
        $provider_id = Settings::provider_id();
        if ($provider_id === '') {
            return Reason::PROVIDER_NOT_CONFIGURED;
        }
        $provider = Plugin::providers()->get($provider_id);
        if ($provider === null) {
            return Reason::NO_PROVIDER;
        }
        if (!$provider->is_configured(Registry::settings_for($provider))) {
            return Reason::PROVIDER_NOT_CONFIGURED;
        }
        if (Pauses::is_paused($provider_id)) {
            return Reason::PROVIDER_PAUSED;
        }
        $eligible = Eligibility::check($post_id, $auto, 'dry_run');
        if ($eligible !== null) {
            return $eligible;
        }
        return self::dry_caps($post_id);
    }

    private static function dry_caps(int $post_id): ?string
    {
        $caps = Caps::remaining($post_id);
        if ($caps['post_used'] >= $caps['post_cap']) {
            return Reason::CAP_POST;
        }
        if ($caps['day_used'] >= $caps['day_cap']) {
            return Reason::CAP_DAY;
        }
        return null;
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<string, string> $provider_settings
     * @param array<string, mixed>  $settings
     */
    private static function reserve_and_call(array $row, ImageProvider $provider, array $provider_settings, string $prompt, bool $cut, array $settings): RequestRecord
    {
        $storage = Plugin::storage();
        $clock = Plugin::clock();
        $post_id = (int) $row['post_id'];
        $day = $clock->day();

        // 29 to 31: atomic reservation.
        $cap = Caps::reserve($post_id, $day);
        if ($cap === Reason::CAP_POST) {
            return self::finish($row, 'skipped', Reason::CAP_POST);
        }
        if ($cap === Reason::CAP_DAY) {
            if (Settings::day_cap() === 0) {
                return self::park($row, Reason::CAP_DAY, $clock->now() + 3600);
            }
            return self::park($row, Reason::CAP_DAY, $clock->next_day_start());
        }

        // 33: spend-meter, a second gate and never a bypass.
        $caps = $provider->capabilities();
        $intent = [
            'purpose' => 'featured_image',
            'caller' => 'featured-from-prompt',
            'provider' => $provider->id(),
            'model' => (string) ($provider_settings['model'] ?? ''),
            'est_units' => 1,
            'post_id' => $post_id,
            'request_id' => (string) $row['id'],
        ];
        $spend = Integrations::spend_check($intent);
        if ($spend['meter'] && !$spend['allowed']) {
            Caps::refund($post_id, $day);
            $row['record']['spend_reason'] = $spend['reason'];
            return self::finish($row, 'skipped', Reason::SPEND_DENIED);
        }
        $settle = static function (bool $billed, ?ImageResult $result = null) use ($spend, $intent): void {
            if (!$spend['meter']) {
                return;
            }
            if ($billed) {
                Integrations::spend_record($intent, $spend['decision'], $result !== null ? $result->units : 1.0, $result?->provider_cost);
            } else {
                Integrations::spend_release($intent, $spend['decision']);
            }
        };

        // Claim the row. Another worker that got here first wins.
        $now = $clock->now();
        $attempts = (int) $row['attempts'] + 1;
        $claimed = $storage->update_request((string) $row['id'], [
            'state' => 'running',
            'heartbeat' => $now,
            'attempts' => $attempts,
            'provider_id' => $provider->id(),
            'updated_at' => $now,
        ], 'queued');
        if (!$claimed) {
            Caps::refund($post_id, $day);
            $settle(false);
            $fresh = $storage->get_request((string) $row['id']);
            return new RequestRecord(Reason::LOCKED, $fresh ?? $row);
        }
        $row['state'] = 'running';
        $row['attempts'] = $attempts;
        $row['provider_id'] = $provider->id();

        [$aspect_w, $aspect_h] = Settings::aspect_parts();
        $size = SizeChooser::choose(
            $caps,
            $aspect_w,
            $aspect_h,
            (int) $settings['target_width'],
            (int) $settings['target_height'],
            (int) $settings['min_width'],
            (int) $settings['min_height']
        );
        $format = (string) $settings['output_format'];
        $format = $format === 'keep' ? ($caps->formats[0] ?? 'png') : $format;
        $request = new ImageRequest(
            $prompt,
            $caps->negative_prompt ? (string) $row['negative_prompt'] : '',
            $size['width'],
            $size['height'],
            $size['aspect_ratio'],
            $format,
            (int) $settings['timeout'],
            (string) $row['id'],
            $post_id,
            1
        );
        $row['record'] = array_merge($row['record'], [
            'requested_width' => $size['width'],
            'requested_height' => $size['height'],
            'size_choice' => $size['how'],
            'aspect_ratio' => $size['aspect_ratio'],
            'prompt_sent' => $prompt,
            'prompt_truncated' => !empty($row['record']['prompt_truncated']) || $cut,
            'day' => $day,
        ]);

        $start = microtime(true);
        try {
            $result = $provider->generate($request, $provider_settings);
        } catch (ConfigException $e) {
            // Thrown before any HTTP call: refund (case 32).
            Caps::refund($post_id, $day);
            $settle(false);
            $row['record']['error'] = Logger::short($e->getMessage(), 300);
            return self::finish($row, 'skipped', Reason::CONFIG_ERROR, 0, $start);
        } catch (AuthException $e) {
            $settle(true);
            Pauses::pause($provider->id(), Reason::AUTH_FAILED, 0);
            $row['record']['error'] = Logger::short($e->getMessage(), 300);
            return self::finish($row, 'failed', Reason::AUTH_FAILED, 0, $start);
        } catch (QuotaException $e) {
            $settle(true);
            Pauses::pause($provider->id(), Reason::QUOTA_EXHAUSTED, $clock->now() + (int) $settings['quota_pause']);
            $row['record']['error'] = Logger::short($e->getMessage(), 300);
            return self::finish($row, 'failed', Reason::QUOTA_EXHAUSTED, 0, $start);
        } catch (RateLimitException $e) {
            $settle(true);
            $wait = $e->retryAfter();
            $wait = $wait === null || $wait <= 0 ? 300 : min(3600, $wait);
            return self::requeue($row, Reason::RATE_LIMITED, $clock->now() + $wait);
        } catch (PromptRefusedException $e) {
            $settle(true);
            $row['record']['provider_message'] = mb_substr(wp_strip_all_tags($e->providerMessage()), 0, 500);
            return self::finish($row, 'failed', Reason::PROMPT_REFUSED, 0, $start);
        } catch (TransientException $e) {
            $settle(true);
            return self::retry_or_fail($row, $e->isTimeout() ? Reason::TIMEOUT : Reason::TRANSIENT, $e->getMessage(), (int) $settings['attempts'], $start);
        } catch (BadResponseException $e) {
            $settle(true);
            return self::retry_or_fail($row, Reason::BAD_RESPONSE, $e->getMessage(), (int) $settings['attempts'], $start);
        } catch (\Throwable $e) {
            // Case 45: anything else is mapped to transient and logged.
            $settle(true);
            Plugin::logger()->notice('Adapter threw an unexpected ' . get_class($e) . ': ' . Logger::short($e->getMessage(), 200), [
                'request_id' => $row['id'],
            ]);
            return self::retry_or_fail($row, Reason::TRANSIENT, $e->getMessage(), (int) $settings['attempts'], $start);
        }
        $settle(true, $result);

        $row['record'] = array_merge($row['record'], [
            'model' => $result->model,
            'revised_prompt' => mb_substr($result->revised_prompt, 0, 2000),
            'provider_request_id' => mb_substr($result->provider_request_id, 0, 200),
            'mime_hint' => mb_substr($result->mime_hint, 0, 100),
            'units' => $result->units,
            'provider_cost' => $result->provider_cost,
        ]);

        $rules = ImageChecker::rules($post_id);
        if ($result->bytes === null || $result->bytes === '') {
            if ($result->url === null || $result->url === '') {
                return self::finish($row, 'failed', Reason::BAD_RESPONSE, 0, $start);
            }
            try {
                $bytes = Downloader::fetch($result->url, $rules['max_bytes'], (int) $settings['timeout'], $caps->local_hosts);
            } catch (CheckFailed $e) {
                $row['record']['error'] = Logger::short($e->getMessage(), 300);
                if ($e->reason === Reason::DOWNLOAD_FAILED) {
                    return self::retry_or_fail($row, Reason::DOWNLOAD_FAILED, $e->getMessage(), (int) $settings['attempts'], $start);
                }
                return self::finish($row, 'failed', $e->reason, 0, $start);
            }
        } else {
            $bytes = $result->bytes;
        }

        try {
            $image = ImageChecker::check($bytes, $rules, [
                'crop' => (bool) $settings['crop'],
                'target_ratio' => Settings::target_ratio(),
                'output_format' => (string) $settings['output_format'],
                'quality' => (int) $settings['quality'],
            ]);
        } catch (CheckFailed $e) {
            $row['record']['error'] = Logger::short($e->getMessage(), 300);
            return self::finish($row, 'failed', $e->reason, 0, $start);
        }

        $row['record'] = array_merge($row['record'], [
            'real_width' => $image->width,
            'real_height' => $image->height,
            'original_width' => $image->original_width,
            'original_height' => $image->original_height,
            'cropped' => $image->cropped,
            'converted' => $image->converted,
            'convert_skipped' => $image->convert_skipped,
            'mime' => $image->mime,
        ]);
        $record = [
            'generated' => true,
            'prompt' => $prompt,
            'negative_prompt' => (string) $row['negative_prompt'],
            'revised_prompt' => $row['record']['revised_prompt'],
            'provider_id' => $provider->id(),
            'model' => $result->model,
            'request_id' => (string) $row['id'],
            'provider_request_id' => $row['record']['provider_request_id'],
            'requested_width' => $size['width'],
            'requested_height' => $size['height'],
            'real_width' => $image->width,
            'real_height' => $image->height,
            'cropped' => $image->cropped,
            'converted' => $image->converted,
            'user_id' => (int) $row['user_id'],
            'trigger' => (string) $row['trigger_type'],
        ];
        try {
            $attachment_id = Suggestions::store($post_id, $image, $record);
        } catch (CheckFailed $e) {
            ImageChecker::remove($image->path);
            return self::finish($row, 'failed', $e->reason, 0, $start);
        }
        ImageChecker::remove($image->path);

        $done = self::finish($row, 'done', Reason::SUGGESTION_READY, $attachment_id, $start);
        do_action('ffp_suggestion_ready', $post_id, $attachment_id, $done->row);
        return $done;
    }

    /**
     * Settings page "Test provider" and "Test with fixture": one counted
     * request with a fixed generic prompt. The image is checked and thrown
     * away; nothing is stored or attached.
     *
     * @return array{ok: bool, reason: string, width: int, height: int, message: string}
     */
    public static function test_provider(string $provider_id): array
    {
        $fail = static fn (string $reason, string $message = ''): array => [
            'ok' => false, 'reason' => $reason, 'width' => 0, 'height' => 0,
            'message' => $message !== '' ? $message : Reason::describe($reason),
        ];
        $provider = Plugin::providers()->get($provider_id);
        if ($provider === null) {
            return $fail(Reason::NO_PROVIDER);
        }
        // The fixture makes no call, so it may run while the switch is off.
        if ($provider_id !== Provider\FixtureProvider::ID && !Settings::enabled()) {
            return $fail(Reason::DISABLED);
        }
        $settings = Registry::settings_for($provider);
        if (!$provider->is_configured($settings)) {
            return $fail(Reason::PROVIDER_NOT_CONFIGURED);
        }
        if (Pauses::is_paused($provider_id)) {
            return $fail(Reason::PROVIDER_PAUSED);
        }
        $day = Plugin::clock()->day();
        if (!Caps::reserve_day($day)) {
            return $fail(Reason::CAP_DAY);
        }
        $all = Settings::all();
        [$aw, $ah] = Settings::aspect_parts();
        $size = SizeChooser::choose($provider->capabilities(), $aw, $ah, (int) $all['target_width'], (int) $all['target_height'], (int) $all['min_width'], (int) $all['min_height']);
        $request = new ImageRequest(
            'A calm landscape with soft morning light over hills. Do not include any text, letters, logos or people.',
            '',
            $size['width'],
            $size['height'],
            $size['aspect_ratio'],
            'png',
            (int) $all['timeout'],
            wp_generate_uuid4(),
            0
        );
        try {
            $result = $provider->generate($request, $settings);
            $bytes = $result->bytes;
            if (($bytes === null || $bytes === '') && $result->url !== null && $result->url !== '') {
                $bytes = Downloader::fetch($result->url, (int) $all['max_mb'] * 1048576, (int) $all['timeout'], $provider->capabilities()->local_hosts);
            }
            if ($bytes === null || $bytes === '') {
                return $fail(Reason::BAD_RESPONSE);
            }
            $image = ImageChecker::check($bytes, ImageChecker::rules(0), [
                'crop' => (bool) $all['crop'],
                'target_ratio' => Settings::target_ratio(),
                'output_format' => 'keep',
                'quality' => (int) $all['quality'],
            ]);
            ImageChecker::remove($image->path);
            return ['ok' => true, 'reason' => '', 'width' => $image->width, 'height' => $image->height, 'message' => 'The provider returned a usable image.'];
        } catch (ConfigException $e) {
            Plugin::storage()->refund('day', $day);
            return $fail(Reason::CONFIG_ERROR, $e->getMessage());
        } catch (AuthException $e) {
            return $fail(Reason::AUTH_FAILED, $e->getMessage());
        } catch (QuotaException $e) {
            return $fail(Reason::QUOTA_EXHAUSTED, $e->getMessage());
        } catch (RateLimitException $e) {
            return $fail(Reason::RATE_LIMITED, $e->getMessage());
        } catch (PromptRefusedException $e) {
            return $fail(Reason::PROMPT_REFUSED, $e->providerMessage());
        } catch (CheckFailed $e) {
            return $fail($e->reason, $e->getMessage());
        } catch (\Throwable $e) {
            return $fail(Reason::TRANSIENT, Logger::short($e->getMessage(), 200));
        }
    }

    /**
     * Case 87: a running row whose heartbeat is too old is lost. Counted, not retried.
     */
    public static function sweep_lost(): int
    {
        $storage = Plugin::storage();
        $now = Plugin::clock()->now();
        $limit = (int) Settings::get('timeout') + 300;
        $count = 0;
        foreach ($storage->list_requests(['running'], 100) as $row) {
            if ((int) $row['heartbeat'] < $now - $limit) {
                self::finish($row, 'failed', Reason::WORKER_LOST);
                $count++;
            }
        }
        return $count;
    }

    /** @param array<string, mixed> $row */
    private static function retry_or_fail(array $row, string $reason, string $message, int $max_attempts, float $start): RequestRecord
    {
        $row['record']['error'] = Logger::short($message, 300);
        $attempts = (int) $row['attempts'];
        if ($attempts >= $max_attempts) {
            return self::finish($row, 'failed', $reason, 0, $start);
        }
        $wait = self::BACKOFF[min(count(self::BACKOFF) - 1, max(0, $attempts - 1))];
        return self::requeue($row, $reason, Plugin::clock()->now() + $wait);
    }

    /**
     * Back to queued after a sent call (counted). Leaves gen-queue: retries
     * run on the own queue.
     *
     * @param array<string, mixed> $row
     */
    private static function requeue(array $row, string $reason, int $next_run): RequestRecord
    {
        $now = Plugin::clock()->now();
        $fields = [
            'state' => 'queued',
            'reason' => $reason,
            'next_run' => $next_run,
            'updated_at' => $now,
            'runner' => 'own',
            'record' => $row['record'],
        ];
        Plugin::storage()->update_request((string) $row['id'], $fields);
        Queue::ensure_scheduled();
        Queue::note((int) $row['post_id'], (string) $row['trigger_type'], 'waiting', $reason, (string) $row['id']);
        $row = array_merge($row, $fields);
        Plugin::logger()->request($row, 'retry');
        return new RequestRecord($reason, $row);
    }

    /**
     * Leave a queued row queued (not counted): disabled, paused, locked, cap_day.
     *
     * @param array<string, mixed> $row
     */
    private static function park(array $row, string $reason, int $next_run): RequestRecord
    {
        $fields = ['reason' => $reason, 'next_run' => $next_run, 'updated_at' => Plugin::clock()->now()];
        Plugin::storage()->update_request((string) $row['id'], $fields, 'queued');
        Queue::note((int) $row['post_id'], (string) $row['trigger_type'], 'waiting', $reason, (string) $row['id']);
        return new RequestRecord($reason, array_merge($row, $fields));
    }

    /**
     * End a request in a final state, with every side effect that goes with it.
     *
     * @param array<string, mixed> $row
     */
    private static function finish(array $row, string $state, string $reason, int $attachment_id = 0, ?float $start = null): RequestRecord
    {
        $now = Plugin::clock()->now();
        $post_id = (int) $row['post_id'];
        $fields = [
            'state' => $state,
            'reason' => $reason,
            'attachment_id' => $attachment_id,
            'updated_at' => $now,
            'record' => $row['record'],
        ];
        Plugin::storage()->update_request((string) $row['id'], $fields);
        $row = array_merge($row, $fields);
        $auto = !in_array($row['trigger_type'], Queue::MANUAL_TRIGGERS, true);

        Queue::note($post_id, (string) $row['trigger_type'], $state, $reason, (string) $row['id'], $attachment_id);
        if ($attachment_id > 0) {
            Guard::run(static fn () => update_post_meta($post_id, Queue::SUGGESTION_META, $attachment_id));
        }
        // Case 9: one auto attempt per post.
        if ($auto && $state !== 'skipped' && get_post($post_id) instanceof \WP_Post) {
            Guard::run(static fn () => update_post_meta($post_id, Queue::MARKER_META, [
                'time' => $now,
                'outcome' => $state,
                'reason' => $reason,
                'request_id' => (string) $row['id'],
            ]));
        }
        // Cases 66, 67: placeholder policy only after a final failure.
        if ($state !== 'done' && !in_array($reason, [Reason::POST_GONE, Reason::HAS_IMAGE, Reason::CANCELLED], true)) {
            $placeholder = Placeholders::apply_policy($post_id, $row);
            if ($placeholder > 0) {
                $row['record']['placeholder_set'] = $placeholder;
                Plugin::storage()->update_request((string) $row['id'], ['record' => $row['record']]);
            }
        }
        Plugin::logger()->request($row, $state, $start !== null ? microtime(true) - $start : 0.0);
        do_action('ffp_request_finished', $row);
        return new RequestRecord($reason, $row);
    }
}
