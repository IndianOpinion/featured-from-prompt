<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Soft integrations with gen-queue, spend-meter and prompt-library.
 *
 * Each is reached only through its public hooks and is never required. The
 * plugin's own caps and kill switch always apply. Contracts:
 *
 * - spend-meter (contract `SPEND_METER_API` 1): filter `spend_meter_pre_spend`
 *   with `null` and an intent array (null back means no meter); actions
 *   `spend_meter_record` (one call array) and `spend_meter_release`
 *   (the reservation id).
 * - gen-queue: filter `gen_queue_enqueue_job` (null, type, payload, args),
 *   handler registered on `gen_queue_register_types` with
 *   `gen_queue_register_type()`; the handler receives the job and context.
 * - prompt-library: filter `promptlib_render` (null, slug, values, version,
 *   options), an array with `system` and `user` back, a WP_Error, or null.
 */
final class Integrations
{
    public const JOB_TYPE = 'featured_from_prompt.generate';
    public const CALLER = 'featured-from-prompt';
    public const PURPOSE = 'featured_image';

    public static function gen_queue_active(): bool
    {
        return (bool) apply_filters('ffp_use_gen_queue', defined('GEN_QUEUE_VERSION') && function_exists('gen_queue_register_type'));
    }

    /** Register the job type with gen-queue (on gen_queue_register_types). */
    public static function register_gen_queue_type(): void
    {
        if (!function_exists('gen_queue_register_type')) {
            return;
        }
        $timeout = (int) Settings::get('timeout');
        // The worker manages its own retries on the own queue; one attempt per job.
        gen_queue_register_type(self::JOB_TYPE, [Queue::class, 'handle_job'], [
            'timeout' => 2 * $timeout + 60,
            'max_attempts' => 1,
            'group' => self::CALLER,
        ]);
    }

    /**
     * Enqueue a job with gen-queue. Returns the job id, or null when the
     * caller should fall back to the plugin's own queue.
     */
    public static function enqueue_with_gen_queue(int $post_id, string $request_id, int $priority): ?string
    {
        $job = apply_filters(
            'gen_queue_enqueue_job',
            null,
            self::JOB_TYPE,
            ['request_id' => $request_id, 'post_id' => $post_id],
            [
                'idempotency_key' => 'ffp:' . $post_id . ':' . $request_id,
                'priority' => max(-100, min(100, $priority)),
                'source' => self::CALLER,
            ]
        );
        if ($job === null || is_wp_error($job) || (!is_string($job) && !is_int($job))) {
            Plugin::logger()->notice('gen-queue did not take the job; using the own queue.', [
                'request_id' => $request_id,
                'result' => is_wp_error($job) ? $job->get_error_code() : get_debug_type($job),
            ]);
            return null;
        }
        return (string) $job;
    }

    /**
     * The intent sent to spend-meter (case 33).
     *
     * @return array<string, mixed>
     */
    public static function spend_intent(string $provider_id, string $model, int $post_id = 0): array
    {
        $intent = [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => strtolower($provider_id),
            'model' => mb_substr($model !== '' ? $model : $provider_id, 0, 128),
            'est_units' => 1,
        ];
        if ($post_id > 0) {
            $intent['meta'] = ['post_id' => (string) $post_id];
        }
        return $intent;
    }

    /**
     * Ask spend-meter before a call. A null answer means no meter.
     *
     * @param array<string, mixed> $intent
     * @return array{meter: bool, allowed: bool, reason: string, reservation_id: ?string}
     */
    public static function spend_check(array $intent): array
    {
        $decision = apply_filters('spend_meter_pre_spend', null, $intent);
        if ($decision === null) {
            return ['meter' => false, 'allowed' => true, 'reason' => '', 'reservation_id' => null];
        }
        $allowed = is_array($decision) && array_key_exists('allowed', $decision) && $decision['allowed'] === true;
        $reason = is_array($decision) && isset($decision['reason']) && is_scalar($decision['reason'])
            ? mb_substr((string) $decision['reason'], 0, 64) : ($allowed ? '' : 'denied');
        $reservation = $allowed && isset($decision['reservation_id']) && is_string($decision['reservation_id']) && $decision['reservation_id'] !== ''
            ? $decision['reservation_id'] : null;
        return ['meter' => true, 'allowed' => $allowed, 'reason' => $reason, 'reservation_id' => $reservation];
    }

    /**
     * Tell spend-meter the call was sent. Status is ok, error_billed or
     * error_unbilled. Provider cost is not forwarded: spend-meter wants
     * nanos and providers report their own units.
     *
     * @param array<string, mixed>                       $intent
     * @param array{reservation_id: ?string, meter: bool} $spend
     */
    public static function spend_record(array $intent, array $spend, string $status, float $units, int $http_status = 0, string $error_code = ''): void
    {
        if (!$spend['meter']) {
            return;
        }
        $call = [
            'reservation_id' => $spend['reservation_id'],
            'purpose' => $intent['purpose'],
            'caller' => $intent['caller'],
            'provider' => $intent['provider'],
            'model' => $intent['model'],
            'units' => $units,
            'status' => $status,
        ];
        if ($http_status > 0) {
            $call['http_status'] = $http_status;
        }
        if ($error_code !== '') {
            $call['error_code'] = mb_substr($error_code, 0, 64);
        }
        if (isset($intent['meta'])) {
            $call['meta'] = $intent['meta'];
        }
        do_action('spend_meter_record', $call);
    }

    /**
     * Tell spend-meter the reservation was not used.
     *
     * @param array{reservation_id: ?string, meter: bool} $spend
     */
    public static function spend_release(array $spend): void
    {
        if ($spend['meter'] && $spend['reservation_id'] !== null) {
            do_action('spend_meter_release', $spend['reservation_id']);
        }
    }

    /**
     * Render the prompt through prompt-library when it holds a live template
     * for our slug. Returns null to keep the plugin's own template.
     *
     * @param array<string, string> $variables
     */
    public static function prompt_library_render(int $post_id, array $variables): ?string
    {
        $slug = apply_filters('ffp_prompt_library_slug', 'featured-from-prompt', $post_id);
        if (!is_string($slug) || $slug === '') {
            return null;
        }
        $values = [];
        foreach ($variables as $name => $value) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) === 1) {
                $values[$name] = $value;
            }
        }
        $rendered = apply_filters('promptlib_render', null, $slug, $values, 'live', ['consumer' => self::CALLER]);
        if ($rendered === null) {
            return null;
        }
        if (is_wp_error($rendered)) {
            if ($rendered->get_error_code() !== 'promptlib_not_found') {
                Plugin::logger()->notice('prompt-library could not render the template; using the own template.', [
                    'slug' => $slug,
                    'code' => $rendered->get_error_code(),
                ]);
            }
            return null;
        }
        if (!is_array($rendered) || !isset($rendered['user']) || !is_string($rendered['user'])) {
            return null;
        }
        $system = isset($rendered['system']) && is_string($rendered['system']) ? trim($rendered['system']) : '';
        $text = trim($system . ' ' . trim($rendered['user']));
        return $text !== '' ? $text : null;
    }
}
