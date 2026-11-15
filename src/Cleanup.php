<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Retention and clean-up (cases 76 to 79).
 */
final class Cleanup
{
    public const CRON_HOOK = 'ffp_cleanup';
    public const BATCH = 50;
    public const BUDGET = 20;

    public static function ensure_scheduled(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(Plugin::clock()->now() + 3600, 'daily', self::CRON_HOOK);
        }
    }

    public static function run_cron(): void
    {
        self::run();
    }

    /**
     * @return array{expired: int, deleted: int, restored: int, kept: int, rows: int, counters: int, lost: int, stopped: string}
     */
    public static function run(bool $dry_run = false, int $batch = self::BATCH, int $budget = self::BUDGET): array
    {
        $clock = Plugin::clock();
        $start = $clock->now();
        $settings = Settings::all();
        $out = ['expired' => 0, 'deleted' => 0, 'restored' => 0, 'kept' => 0, 'rows' => 0, 'counters' => 0, 'lost' => 0, 'stopped' => ''];
        $handled = 0;
        $over = static function () use (&$handled, $batch, $budget, $start, $clock): bool {
            return $handled >= $batch || $clock->now() - $start >= $budget;
        };

        // 76: pending past the limit become expired.
        $limit = $clock->now() - (int) $settings['pending_days'] * DAY_IN_SECONDS;
        foreach (self::attachments('pending', $batch) as $id) {
            if ($over()) {
                $out['stopped'] = 'budget';
                return $out;
            }
            if ((int) get_post_meta($id, Suggestions::META_STATE_TIME, true) < $limit) {
                $handled++;
                $out['expired']++;
                if (!$dry_run) {
                    Suggestions::set_state($id, 'expired', 0, 'pending limit');
                }
            }
        }

        // 77: delete rejected, superseded and expired after retention.
        $retention = (int) $settings['retention_days'];
        if ($retention >= 0) {
            $cutoff = $clock->now() - $retention * DAY_IN_SECONDS;
            foreach (['rejected', 'superseded', 'expired'] as $state) {
                foreach (self::attachments($state, $batch) as $id) {
                    if ($over()) {
                        $out['stopped'] = 'budget';
                        return $out;
                    }
                    if ((int) get_post_meta($id, Suggestions::META_STATE_TIME, true) > $cutoff) {
                        continue;
                    }
                    $handled++;
                    if (self::is_featured_anywhere($id)) {
                        $out['restored']++;
                        if (!$dry_run) {
                            Suggestions::set_state($id, 'accepted', 0, 'featured image found during clean-up');
                            Plugin::logger()->notice('Clean-up found a featured image in state ' . $state . '; set back to accepted.', ['attachment_id' => $id]);
                        }
                        continue;
                    }
                    if ((bool) apply_filters('ffp_keep_attachment', false, $id)) {
                        $out['kept']++;
                        continue;
                    }
                    $out['deleted']++;
                    if (!$dry_run) {
                        wp_delete_attachment($id, true);
                    }
                }
            }
        }

        if (!$dry_run) {
            $out['lost'] = Worker::sweep_lost();
            $out['counters'] = Plugin::storage()->purge_counters_before($clock->day($clock->now() - 7 * DAY_IN_SECONDS));
            $out['rows'] = Plugin::storage()->delete_final_before($clock->now() - (int) $settings['row_retention_days'] * DAY_IN_SECONDS, 500);
        }
        return $out;
    }

    /** @return list<int> */
    private static function attachments(string $state, int $limit): array
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'meta_key' => Suggestions::META_STATE,
            'meta_value' => $state,
            'fields' => 'ids',
            'numberposts' => $limit,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);
        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    public static function is_featured_anywhere(int $attachment_id): bool
    {
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'meta_key' => '_thumbnail_id',
            'meta_value' => (string) $attachment_id,
            'fields' => 'ids',
            'numberposts' => 1,
            'suppress_filters' => true,
        ]);
        return is_array($ids) && $ids !== [];
    }
}
