<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * One log line per request, plus notices.
 *
 * Lines go to error_log() when WP_DEBUG_LOG is on or the "log requests"
 * setting is on. Every line also fires the ffp_log action, so a site can
 * route it elsewhere. Never logs a secret; prompts are cut to 80 characters.
 */
class Logger
{
    public const PREFIX = '[featured-from-prompt]';
    public const RECENT_OPTION = 'ffp_recent';
    public const RECENT_MAX = 200;

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $line = self::PREFIX . ' ' . $message;
        if ($context !== []) {
            $line .= ' ' . (string) wp_json_encode($context);
        }
        $this->write($line);
        do_action('ffp_log', $message, $context);
    }

    /**
     * Log a finished (or parked) request and add it to the recent ring.
     *
     * @param array<string, mixed> $row
     */
    public function request(array $row, string $outcome, float $duration = 0.0): void
    {
        $record = is_array($row['record'] ?? null) ? $row['record'] : [];
        $size = isset($record['real_width'], $record['real_height'])
            ? $record['real_width'] . 'x' . $record['real_height'] : '-';
        $parts = [
            'id=' . (string) ($row['id'] ?? ''),
            'post=' . (int) ($row['post_id'] ?? 0),
            'trigger=' . (string) ($row['trigger_type'] ?? ''),
            'outcome=' . $outcome,
            'reason=' . (string) ($row['reason'] ?? ''),
            'provider=' . (string) ($row['provider_id'] ?? ''),
            'size=' . $size,
            'duration=' . number_format($duration, 2, '.', '') . 's',
            'prompt="' . self::short((string) ($row['prompt'] ?? ''), 80) . '"',
        ];
        $this->write(self::PREFIX . ' ' . implode(' ', $parts));
        do_action('ffp_log', 'request', ['id' => $row['id'] ?? '', 'outcome' => $outcome, 'reason' => $row['reason'] ?? '']);
        $this->remember($row, $outcome);
    }

    /** @param array<string, mixed> $row */
    private function remember(array $row, string $outcome): void
    {
        $record = is_array($row['record'] ?? null) ? $row['record'] : [];
        $entry = [
            'id' => (string) ($row['id'] ?? ''),
            'post_id' => (int) ($row['post_id'] ?? 0),
            'trigger' => (string) ($row['trigger_type'] ?? ''),
            'outcome' => $outcome,
            'reason' => (string) ($row['reason'] ?? ''),
            'provider' => (string) ($row['provider_id'] ?? ''),
            'size' => isset($record['real_width'], $record['real_height'])
                ? $record['real_width'] . 'x' . $record['real_height'] : '',
            'prompt' => self::short((string) ($row['prompt'] ?? ''), 200),
            'time' => Plugin::clock()->now(),
        ];
        $recent = get_option(self::RECENT_OPTION, []);
        $recent = is_array($recent) ? $recent : [];
        array_unshift($recent, $entry);
        $recent = array_slice($recent, 0, self::RECENT_MAX);
        if (get_option(self::RECENT_OPTION, null) === null) {
            add_option(self::RECENT_OPTION, $recent, '', false);
            return;
        }
        update_option(self::RECENT_OPTION, $recent, false);
    }

    /** @return list<array<string, mixed>> */
    public function recent(): array
    {
        $recent = get_option(self::RECENT_OPTION, []);
        return is_array($recent) ? array_values($recent) : [];
    }

    protected function write(string $line): void
    {
        $debug_log = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
        if ($debug_log || (bool) Settings::get('log_requests')) {
            error_log($line); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    public static function short(string $text, int $max): string
    {
        $text = str_replace(['"', "\n", "\r"], ["'", ' ', ' '], $text);
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
