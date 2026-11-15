<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Soft integrations with gen-queue, spend-meter and prompt-library.
 *
 * Each is detected at runtime through its public constant or hook and is
 * never required. The plugin's own caps and kill switch always apply.
 */
final class Integrations
{
    public const JOB_TYPE = 'featured_from_prompt.generate';

    public static function gen_queue_active(): bool
    {
        return (bool) apply_filters('ffp_use_gen_queue', defined('GEN_QUEUE_VERSION'));
    }

    public static function prompt_library_active(): bool
    {
        return defined('PROMPT_LIBRARY_VERSION') || has_filter('prompt_library_get_template') !== false;
    }

    /**
     * Enqueue a job with gen-queue. Returns the job id, or null when the
     * caller should fall back to the plugin's own queue.
     */
    public static function enqueue_with_gen_queue(int $post_id, string $request_id, int $priority): ?string
    {
        $job = apply_filters('gen_queue_enqueue_job', null, [
            'type' => self::JOB_TYPE,
            'idempotency_key' => 'ffp:' . $post_id . ':' . $request_id,
            'payload' => ['request_id' => $request_id, 'post_id' => $post_id],
            'priority' => $priority,
            'caller' => 'featured-from-prompt',
        ]);
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
     * Ask spend-meter before a call.
     *
     * @param array<string, mixed> $intent
     * @return array{meter: bool, allowed: bool, reason: string, decision: mixed}
     */
    public static function spend_check(array $intent): array
    {
        $decision = apply_filters('spend_meter_pre_spend', null, $intent);
        if ($decision === null) {
            return ['meter' => false, 'allowed' => true, 'reason' => '', 'decision' => null];
        }
        $allowed = is_array($decision) && array_key_exists('allowed', $decision) && $decision['allowed'] === true;
        $reason = is_array($decision) && isset($decision['reason']) && is_scalar($decision['reason'])
            ? (string) $decision['reason'] : ($allowed ? '' : 'denied');
        return ['meter' => true, 'allowed' => $allowed, 'reason' => $reason, 'decision' => $decision];
    }

    /**
     * Tell spend-meter the call was sent and counts.
     *
     * @param array<string, mixed> $intent
     */
    public static function spend_record(array $intent, mixed $decision, float $units, ?int $cost): void
    {
        do_action('spend_meter_record', $intent, $decision, ['units' => $units, 'provider_cost' => $cost]);
    }

    /**
     * Tell spend-meter the reservation was not used.
     *
     * @param array<string, mixed> $intent
     */
    public static function spend_release(array $intent, mixed $decision): void
    {
        do_action('spend_meter_release', $intent, $decision);
    }
}
