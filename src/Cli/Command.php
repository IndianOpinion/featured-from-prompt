<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Cli;

use IndianOpinion\FeaturedFromPrompt\Api;
use IndianOpinion\FeaturedFromPrompt\Caps;
use IndianOpinion\FeaturedFromPrompt\Cleanup;
use IndianOpinion\FeaturedFromPrompt\Pauses;
use IndianOpinion\FeaturedFromPrompt\Plugin;
use IndianOpinion\FeaturedFromPrompt\Queue;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\Settings;
use IndianOpinion\FeaturedFromPrompt\Worker;

/**
 * Suggest featured images from a prompt, review them and manage the queue.
 *
 * Exit codes: 0 success or dry run, 1 a request ended in a failure reason,
 * 2 bad arguments, 3 the kill switch is off.
 */
final class Command
{
    public const EXIT_FAILURE = 1;
    public const EXIT_BAD_ARGS = 2;
    public const EXIT_DISABLED = 3;

    /**
     * Queue (or run) a manual request for one or more posts.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Post IDs.
     *
     * [--prompt=<text>]
     * : Use this prompt instead of the template.
     *
     * [--now]
     * : Run the request in this process after queuing it.
     *
     * [--force]
     * : Ignore the one-auto-attempt marker.
     *
     * [--dry-run]
     * : Build the prompt and run the guards without reserving or calling.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function generate(array $args, array $assoc): void
    {
        $ids = $this->ids($args);
        $dry = !empty($assoc['dry-run']);
        if (!$dry && !Settings::enabled()) {
            $this->halt('The plugin is switched off.', self::EXIT_DISABLED);
            return;
        }
        $failed = false;
        foreach ($ids as $post_id) {
            if ($dry) {
                $preview = Api::preview_prompt($post_id, isset($assoc['prompt']) ? (string) $assoc['prompt'] : null);
                $reason = $preview->prompt === '' ? Reason::PROMPT_EMPTY : (Worker::dry_guards($post_id, false) ?? Reason::WOULD_REQUEST);
                \WP_CLI::log(sprintf('%d: %s | %s', $post_id, $reason, $preview->prompt));
                continue;
            }
            $result = Api::request($post_id, [
                'manual' => true,
                'trigger' => 'cli',
                'prompt' => isset($assoc['prompt']) ? (string) $assoc['prompt'] : null,
                'force' => !empty($assoc['force']),
                'user_id' => get_current_user_id(),
            ]);
            if (!$result->queued) {
                \WP_CLI::warning(sprintf('%d: %s', $post_id, $result->reason));
                $failed = true;
                continue;
            }
            if (!empty($assoc['now'])) {
                $record = Api::run_request((string) $result->request_id);
                \WP_CLI::log(sprintf('%d: %s %s (attachment %d)', $post_id, $record->state(), $record->reason, $record->attachment_id()));
                $failed = $failed || $record->is_failure();
                continue;
            }
            \WP_CLI::log(sprintf('%d: queued %s', $post_id, (string) $result->request_id));
        }
        $this->finish($failed);
    }

    /**
     * Queue automatic requests for posts without a usable featured image.
     *
     * ## OPTIONS
     *
     * [--post_type=<t>]
     * : Comma-separated post types. Default: the enabled types.
     *
     * [--status=<s>]
     * : Comma-separated statuses. Default: publish.
     *
     * [--after=<date>]
     * : Only posts on or after this date.
     *
     * [--before=<date>]
     * : Only posts on or before this date.
     *
     * [--limit=<n>]
     * : At most this many posts. Default 100.
     *
     * [--spread]
     * : Queue them all and let the daily cap pace them.
     *
     * [--force]
     * : Ignore the one-auto-attempt marker.
     *
     * [--dry-run]
     * : List posts and the reason each would be skipped.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function backfill(array $args, array $assoc): void
    {
        $dry = !empty($assoc['dry-run']);
        if (!$dry && !Settings::enabled()) {
            $this->halt('The plugin is switched off.', self::EXIT_DISABLED);
            return;
        }
        if (isset($assoc['limit']) && (!is_numeric($assoc['limit']) || (int) $assoc['limit'] < 1)) {
            $this->halt('--limit must be a positive number.', self::EXIT_BAD_ARGS);
            return;
        }
        $result = Queue::backfill([
            'post_type' => $assoc['post_type'] ?? '',
            'status' => $assoc['status'] ?? '',
            'after' => $assoc['after'] ?? '',
            'before' => $assoc['before'] ?? '',
            'limit' => $assoc['limit'] ?? 100,
            'spread' => !empty($assoc['spread']),
            'force' => !empty($assoc['force']),
            'dry_run' => $dry,
        ]);
        foreach ($result['candidates'] as $row) {
            \WP_CLI::log(sprintf('%d: %s', $row['post_id'], $row['reason']));
        }
        \WP_CLI::log(sprintf('Queued %d. Requests left today: %d.', $result['queued'], $result['cap_left']));
    }

    /**
     * Print the rendered prompt and its variables.
     *
     * <id>
     * : Post ID.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function prompt(array $args, array $assoc): void
    {
        $ids = $this->ids($args);
        $preview = Api::preview_prompt($ids[0]);
        \WP_CLI::log($preview->prompt);
        foreach ($preview->variables as $key => $value) {
            \WP_CLI::log(sprintf('  {%s} = %s', $key, $value));
        }
        if ($preview->truncated) {
            \WP_CLI::log('  (cut to ' . $preview->max_chars . ' characters)');
        }
    }

    /**
     * Show a post's record, pending suggestions and caps.
     *
     * <id>
     * : Post ID.
     *
     * [--format=<format>]
     * : table or json.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $ids = $this->ids($args);
        $status = Api::status($ids[0]);
        if (($assoc['format'] ?? 'table') === 'json') {
            \WP_CLI::log((string) wp_json_encode($status));
            return;
        }
        $last = is_array($status['last']) ? $status['last'] : [];
        \WP_CLI::log('Last: ' . ($last === [] ? 'none' : ($last['outcome'] ?? '') . ' ' . ($last['reason'] ?? '')));
        \WP_CLI::log('Marker: ' . ($status['marker'] === null ? 'none' : 'set'));
        $active = $status['active'];
        \WP_CLI::log('Active: ' . (is_array($active) ? $active['id'] . ' ' . $active['state'] . ' ' . $active['reason'] : 'none'));
        foreach ($status['suggestions'] as $s) {
            \WP_CLI::log(sprintf('Suggestion %d: %dx%d %s', $s['id'], $s['width'], $s['height'], $s['provider']));
        }
        $caps = $status['caps'];
        \WP_CLI::log(sprintf('Left today: %d for this post, %d for the site.', $caps['post_left'], $caps['day_left']));
    }

    /**
     * Accept a suggestion as a user.
     *
     * <attachment_id>
     * : Suggestion attachment ID.
     *
     * --user=<id>
     * : The user who accepts. Must have the capabilities.
     *
     * [--yes]
     * : Accept even when another editor holds the post lock.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function accept(array $args, array $assoc): void
    {
        $ids = $this->ids($args);
        $user = $this->user($assoc);
        if ($user === 0) {
            return;
        }
        $result = Api::accept($ids[0], $user, 0, !empty($assoc['yes']));
        if (!$result->ok) {
            \WP_CLI::warning($result->reason . ': ' . Reason::describe($result->reason));
            $this->finish(true);
            return;
        }
        \WP_CLI::success(sprintf('Accepted %d as the featured image of %d.', $result->attachment_id, $result->post_id));
    }

    /**
     * Reject a suggestion as a user.
     *
     * <attachment_id>
     * : Suggestion attachment ID.
     *
     * --user=<id>
     * : The user who rejects.
     *
     * [--reason=<text>]
     * : A short reason, up to 200 characters.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function reject(array $args, array $assoc): void
    {
        $ids = $this->ids($args);
        $user = $this->user($assoc);
        if ($user === 0) {
            return;
        }
        $result = Api::reject($ids[0], $user, isset($assoc['reason']) ? (string) $assoc['reason'] : '');
        if (!$result->ok) {
            \WP_CLI::warning($result->reason . ': ' . Reason::describe($result->reason));
            $this->finish(true);
            return;
        }
        \WP_CLI::success(sprintf('Rejected %d.', $result->attachment_id));
    }

    /**
     * Queue: list, run a batch, or cancel a request.
     *
     * <action>
     * : list, run or cancel.
     *
     * [<request_id>]
     * : For cancel.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function queue(array $args, array $assoc): void
    {
        $action = $args[0] ?? '';
        if ($action === 'list') {
            foreach (Plugin::storage()->list_requests(['queued', 'running'], 200) as $row) {
                \WP_CLI::log(sprintf('%s post=%d %s %s attempts=%d next=%s', $row['id'], $row['post_id'], $row['state'], $row['reason'], $row['attempts'], gmdate('Y-m-d H:i', (int) $row['next_run'])));
            }
            return;
        }
        if ($action === 'run') {
            if (!Settings::enabled()) {
                $this->halt('The plugin is switched off.', self::EXIT_DISABLED);
                return;
            }
            $result = Queue::run_batch();
            $failed = false;
            foreach ($result['results'] as $r) {
                \WP_CLI::log($r['id'] . ': ' . $r['reason']);
                $row = Plugin::storage()->get_request($r['id']);
                $failed = $failed || ($row !== null && in_array($row['state'], ['failed', 'skipped'], true));
            }
            \WP_CLI::log(sprintf('Batch %s, ran %d.', $result['status'], $result['ran']));
            $this->finish($failed);
            return;
        }
        if ($action === 'cancel' && isset($args[1])) {
            if (!Queue::cancel($args[1])) {
                \WP_CLI::warning('Not cancelled: no queued request with that id.');
                $this->finish(true);
                return;
            }
            \WP_CLI::success('Cancelled.');
            return;
        }
        $this->halt('Usage: queue list|run|cancel <request_id>', self::EXIT_BAD_ARGS);
    }

    /**
     * Providers: list, test, pause or resume.
     *
     * <action>
     * : list, test, pause or resume.
     *
     * [<id>]
     * : Provider ID.
     *
     * [--yes]
     * : Skip the confirmation for test.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function provider(array $args, array $assoc): void
    {
        $action = $args[0] ?? '';
        $id = $args[1] ?? '';
        if ($action === 'list') {
            foreach (Plugin::providers()->all() as $pid => $provider) {
                $pause = Pauses::get($pid);
                \WP_CLI::log(sprintf('%s%s  %s  %s', $pid, $pid === Settings::provider_id() ? ' (selected)' : '', $provider->label(), $pause === null ? 'running' : 'paused: ' . $pause['reason']));
            }
            return;
        }
        if ($id === '' || Plugin::providers()->get($id) === null) {
            $this->halt('Unknown provider.', self::EXIT_BAD_ARGS);
            return;
        }
        if ($action === 'test') {
            if (empty($assoc['yes'])) {
                \WP_CLI::confirm('Send one real, counted request to ' . $id . '?');
            }
            $result = Worker::test_provider($id);
            if (!$result['ok']) {
                \WP_CLI::warning($result['reason'] . ': ' . $result['message']);
                $this->finish(true, $result['reason'] === Reason::DISABLED);
                return;
            }
            \WP_CLI::success(sprintf('%dx%d image. One request counted.', $result['width'], $result['height']));
            return;
        }
        if ($action === 'pause') {
            Pauses::pause($id, 'manual', 0);
            \WP_CLI::success('Paused.');
            return;
        }
        if ($action === 'resume') {
            Pauses::resume($id);
            \WP_CLI::success('Resumed.');
            return;
        }
        $this->halt('Usage: provider list|test|pause|resume <id>', self::EXIT_BAD_ARGS);
    }

    /**
     * Today's counts and limits.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function caps(array $args, array $assoc): void
    {
        $caps = Caps::remaining();
        \WP_CLI::log(sprintf('Day %s: %d of %d used, %d left. Per post: %d.', $caps['day'], $caps['day_used'], $caps['day_cap'], $caps['day_left'], $caps['post_cap']));
        \WP_CLI::log('Switch: ' . (Settings::enabled() ? 'on' : 'off'));
    }

    /**
     * Expire and delete old suggestions now.
     *
     * [--dry-run]
     * : Count only.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function cleanup(array $args, array $assoc): void
    {
        $result = Cleanup::run(!empty($assoc['dry-run']));
        foreach ($result as $key => $value) {
            \WP_CLI::log($key . ': ' . (string) $value);
        }
    }

    /**
     * Clear the one-auto-attempt marker on posts.
     *
     * <id>...
     * : Post IDs.
     *
     * @subcommand clear-marker
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function clear_marker(array $args, array $assoc): void
    {
        foreach ($this->ids($args) as $post_id) {
            Queue::clear_marker($post_id);
        }
        \WP_CLI::success('Cleared.');
    }

    /**
     * @param list<string> $args
     * @return list<int>
     */
    private function ids(array $args): array
    {
        $ids = [];
        foreach ($args as $arg) {
            if (!ctype_digit($arg) || (int) $arg <= 0) {
                $this->halt('IDs must be positive numbers.', self::EXIT_BAD_ARGS);
                return [0];
            }
            $ids[] = (int) $arg;
        }
        if ($ids === []) {
            $this->halt('Give at least one ID.', self::EXIT_BAD_ARGS);
            return [0];
        }
        return $ids;
    }

    /** @param array<string, mixed> $assoc */
    private function user(array $assoc): int
    {
        $user = isset($assoc['user']) && is_numeric($assoc['user']) ? (int) $assoc['user'] : 0;
        if ($user <= 0 || !get_userdata($user) instanceof \WP_User) {
            $this->halt('--user must be an existing user ID.', self::EXIT_BAD_ARGS);
            return 0;
        }
        return $user;
    }

    private function finish(bool $failed, bool $disabled = false): void
    {
        if ($disabled) {
            \WP_CLI::halt(self::EXIT_DISABLED);
        }
        if ($failed) {
            \WP_CLI::halt(self::EXIT_FAILURE);
        }
    }

    private function halt(string $message, int $code): void
    {
        \WP_CLI::warning($message);
        \WP_CLI::halt($code);
    }
}
