<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * The one entry point that queues requests (cases 5 to 11, 80 to 84).
 *
 * Every trigger, the REST route, WP-CLI and other plugins come through
 * request(). The provider is reachable only through Worker::run().
 */
final class Queue
{
    public const CRON_HOOK = 'ffp_run_queue';
    public const CRON_SCHEDULE = 'ffp_minute';
    public const LAST_META = '_ffp_last';
    public const MARKER_META = '_ffp_auto_marker';
    public const SUGGESTION_META = '_ffp_suggestion';
    public const LAST_BATCH_OPTION = 'ffp_last_batch';
    public const TRIGGERS = ['manual', 'save', 'publish', 'external', 'backfill', 'cli'];
    public const MANUAL_TRIGGERS = ['manual', 'cli'];
    public const FINAL_STATES = ['done', 'failed', 'skipped', 'cancelled'];

    /**
     * Queue a request.
     *
     * @param array<string, mixed> $args prompt, manual, trigger, source, priority, force, user_id.
     */
    public static function request(int $post_id, array $args = []): RequestResult
    {
        $manual = array_key_exists('manual', $args) ? (bool) $args['manual'] : true;
        $trigger = isset($args['trigger']) && in_array($args['trigger'], self::TRIGGERS, true)
            ? (string) $args['trigger'] : ($manual ? 'manual' : 'external');
        if (!$manual && in_array($trigger, self::MANUAL_TRIGGERS, true)) {
            $trigger = 'external';
        }
        $force = !empty($args['force']);
        $user_id = isset($args['user_id']) ? (int) $args['user_id'] : ($manual ? get_current_user_id() : 0);
        $override = isset($args['prompt']) && is_string($args['prompt']) && trim($args['prompt']) !== '' ? $args['prompt'] : null;
        $priority = isset($args['priority']) && is_numeric($args['priority']) ? (int) $args['priority'] : ($manual ? 10 : 0);
        $source = isset($args['source']) && is_string($args['source']) ? sanitize_key($args['source']) : $trigger;
        $respect_mode = !$manual && in_array($trigger, ['save', 'publish', 'external'], true);

        if (Plugin::schema_missing() !== []) {
            return new RequestResult(false, null, Reason::SCHEMA_MISSING);
        }

        $reason = Eligibility::check($post_id, !$manual, 'request');
        if ($reason !== null) {
            if (!$manual) {
                self::note($post_id, $trigger, 'skipped', $reason);
            }
            return new RequestResult(false, null, $reason);
        }

        $mode = (string) Settings::get('auto_mode');
        if ($respect_mode && $mode === 'off') {
            return new RequestResult(false, null, Reason::AUTO_OFF);
        }
        if (!$manual && !$force && self::has_marker($post_id)) {
            return new RequestResult(false, null, Reason::AUTO_ATTEMPTED);
        }

        $active = Plugin::storage()->active_request_for_post($post_id);
        if ($active !== null) {
            return new RequestResult(false, (string) $active['id'], Reason::ALREADY_QUEUED);
        }

        $provider = Plugin::providers()->get(Settings::provider_id());
        $preview = PromptBuilder::preview($post_id, $override, $provider?->capabilities());
        if ($preview->prompt === '') {
            self::note($post_id, $trigger, 'skipped', Reason::PROMPT_EMPTY);
            return new RequestResult(false, null, Reason::PROMPT_EMPTY);
        }

        if ($respect_mode && $mode === 'log') {
            $would = Worker::dry_guards($post_id, true);
            $decision = $would ?? Reason::WOULD_REQUEST;
            self::note($post_id, $trigger, 'log', $decision);
            Plugin::logger()->request([
                'id' => '-',
                'post_id' => $post_id,
                'trigger_type' => $trigger,
                'reason' => $decision,
                'provider_id' => Settings::provider_id(),
                'prompt' => $preview->prompt,
            ], 'log');
            return new RequestResult(false, null, $decision);
        }

        $now = Plugin::clock()->now();
        $row = [
            'id' => wp_generate_uuid4(),
            'post_id' => $post_id,
            'state' => 'queued',
            'trigger_type' => $trigger,
            'user_id' => $user_id,
            'prompt' => $preview->prompt,
            'negative_prompt' => $preview->negative_prompt,
            'provider_id' => Settings::provider_id(),
            'priority' => $priority,
            'attempts' => 0,
            'next_run' => $now,
            'heartbeat' => 0,
            'reason' => Reason::QUEUED,
            'attachment_id' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'runner' => 'own',
            'record' => [
                'source' => $source,
                'manual' => $manual,
                'override' => $preview->override,
                'prompt_truncated' => $preview->truncated,
            ],
        ];
        if (!Plugin::storage()->insert_request($row)) {
            return new RequestResult(false, null, Reason::STORE_FAILED);
        }

        if (Integrations::gen_queue_active()) {
            $job = Integrations::enqueue_with_gen_queue($post_id, (string) $row['id'], $priority);
            if ($job !== null) {
                $row['runner'] = 'gen-queue';
                $row['record']['job_id'] = $job;
                Plugin::storage()->update_request((string) $row['id'], ['runner' => 'gen-queue', 'record' => $row['record']]);
            }
        }
        if ($row['runner'] === 'own') {
            self::ensure_scheduled();
        }

        self::note($post_id, $trigger, 'queued', $preview->truncated ? Reason::PROMPT_TRUNCATED : Reason::QUEUED, (string) $row['id']);
        do_action('ffp_request_queued', $row);
        if ($manual && $row['runner'] === 'own' && function_exists('spawn_cron')) {
            spawn_cron();
        }
        return new RequestResult(true, (string) $row['id'], Reason::QUEUED);
    }

    /** Cancel a queued request (panel, REST, CLI). */
    public static function cancel(string $request_id): bool
    {
        $row = Plugin::storage()->get_request($request_id);
        if ($row === null) {
            return false;
        }
        $ok = Plugin::storage()->update_request($request_id, [
            'state' => 'cancelled',
            'reason' => Reason::CANCELLED,
            'updated_at' => Plugin::clock()->now(),
        ], 'queued');
        if ($ok) {
            self::note((int) $row['post_id'], (string) $row['trigger_type'], 'cancelled', Reason::CANCELLED, $request_id);
        }
        return $ok;
    }

    /**
     * Run one batch of due requests (case 80).
     *
     * @return array{status: string, ran: int, results: list<array{id: string, reason: string}>}
     */
    public static function run_batch(): array
    {
        $storage = Plugin::storage();
        $clock = Plugin::clock();
        $budget = (int) Settings::get('batch_budget');
        if (!$storage->acquire_lock('batch', $budget + 60, $clock->now())) {
            return ['status' => Reason::LOCKED, 'ran' => 0, 'results' => []];
        }
        $results = [];
        try {
            Worker::sweep_lost();
            $start = $clock->now();
            $size = (int) Settings::get('batch_size');
            $own_only = !Integrations::gen_queue_active();
            foreach ($storage->due_requests($clock->now(), $size * 3) as $row) {
                if (count($results) >= $size || $clock->now() - $start >= $budget) {
                    break;
                }
                if ($row['runner'] === 'gen-queue' && !$own_only) {
                    continue;
                }
                $record = Worker::run((string) $row['id']);
                $results[] = ['id' => (string) $row['id'], 'reason' => $record->reason];
            }
            update_option(self::LAST_BATCH_OPTION, $clock->now(), false);
        } finally {
            $storage->release_lock('batch');
        }
        if ($storage->count_requests(['queued', 'running']) === 0) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
        return ['status' => 'ran', 'ran' => count($results), 'results' => $results];
    }

    /**
     * gen-queue job handler (case 81). gen-queue passes its Job (payload in
     * `$job->payload`) and a JobContext. The guards run inside Worker::run;
     * retries go back to the own queue, so the job always ends ok.
     *
     * @param mixed $job
     * @param mixed $context
     * @return array<string, mixed>
     */
    public static function handle_job($job, $context = null): array
    {
        $payload = is_object($job) && isset($job->payload) ? $job->payload : $job;
        $payload = is_array($payload) ? $payload : [];
        $id = isset($payload['request_id']) && is_string($payload['request_id']) ? $payload['request_id'] : '';
        $record = Worker::run($id)->to_array();
        return ['request_id' => $id, 'reason' => (string) ($record['reason'] ?? '')];
    }

    public static function ensure_scheduled(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(Plugin::clock()->now(), self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function has_marker(int $post_id): bool
    {
        $marker = get_post_meta($post_id, self::MARKER_META, true);
        return is_array($marker) && $marker !== [];
    }

    public static function clear_marker(int $post_id): void
    {
        delete_post_meta($post_id, self::MARKER_META);
    }

    /** Record the last decision on the post (lesson 13). */
    public static function note(int $post_id, string $trigger, string $outcome, string $reason, string $request_id = '', int $attachment_id = 0): void
    {
        if ($post_id <= 0 || !get_post($post_id) instanceof \WP_Post) {
            return;
        }
        Guard::run(static fn () => update_post_meta($post_id, self::LAST_META, [
            'request_id' => $request_id,
            'trigger' => $trigger,
            'outcome' => $outcome,
            'reason' => $reason,
            'attachment_id' => $attachment_id,
            'time' => Plugin::clock()->now(),
        ]));
    }

    /**
     * Backfill (cases 83, 84).
     *
     * @param array<string, mixed> $args post_type, status, after, before, limit, spread, force, dry_run.
     * @return array{candidates: list<array{post_id: int, reason: string}>, queued: int, cap_left: int}
     */
    public static function backfill(array $args): array
    {
        $types = isset($args['post_type']) && is_string($args['post_type']) && $args['post_type'] !== ''
            ? array_map('sanitize_key', explode(',', $args['post_type'])) : (array) Settings::get('post_types');
        $status = isset($args['status']) && is_string($args['status']) && $args['status'] !== ''
            ? array_map('sanitize_key', explode(',', $args['status'])) : ['publish'];
        $limit = isset($args['limit']) && is_numeric($args['limit']) ? max(1, (int) $args['limit']) : 100;
        $query = [
            'post_type' => $types,
            'post_status' => $status,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'numberposts' => $limit * 5,
            'suppress_filters' => true,
        ];
        $date = [];
        if (!empty($args['after']) && is_string($args['after'])) {
            $date['after'] = $args['after'];
        }
        if (!empty($args['before']) && is_string($args['before'])) {
            $date['before'] = $args['before'];
        }
        if ($date !== []) {
            $date['inclusive'] = true;
            $query['date_query'] = [$date];
        }
        $ids = get_posts($query);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];

        $force = !empty($args['force']);
        $dry = !empty($args['dry_run']);
        $spread = !empty($args['spread']);
        $caps = Caps::remaining();
        $allow = $spread ? PHP_INT_MAX : $caps['day_left'] - Plugin::storage()->count_requests(['queued']);
        $allow = max(0, $allow);

        $candidates = [];
        $queued = 0;
        foreach ($ids as $post_id) {
            if (count($candidates) >= $limit) {
                break;
            }
            $reason = Eligibility::check($post_id, true, 'backfill');
            if ($reason === null && !$force && self::has_marker($post_id)) {
                $reason = Reason::AUTO_ATTEMPTED;
            }
            if ($reason === null && Plugin::storage()->active_request_for_post($post_id) !== null) {
                $reason = Reason::ALREADY_QUEUED;
            }
            if ($reason === null && $queued >= $allow) {
                $reason = Reason::CAP_DAY;
            }
            if ($reason === null && !$dry) {
                $result = self::request($post_id, ['manual' => false, 'trigger' => 'backfill', 'force' => $force, 'source' => 'backfill']);
                $reason = $result->reason;
                if ($result->queued) {
                    $queued++;
                }
            } elseif ($reason === null) {
                $reason = Reason::WOULD_REQUEST;
                $queued++;
            }
            $candidates[] = ['post_id' => $post_id, 'reason' => $reason];
        }
        return ['candidates' => $candidates, 'queued' => $dry ? 0 : $queued, 'cap_left' => $caps['day_left']];
    }
}
