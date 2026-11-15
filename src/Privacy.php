<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Personal data exporter and eraser for the user IDs the plugin stores.
 */
final class Privacy
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function exporters(array $exporters): array
    {
        $exporters['featured-from-prompt'] = ['exporter_friendly_name' => 'Featured from prompt', 'callback' => [self::class, 'export']];
        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function erasers(array $erasers): array
    {
        $erasers['featured-from-prompt'] = ['eraser_friendly_name' => 'Featured from prompt', 'callback' => [self::class, 'erase']];
        return $erasers;
    }

    /** @return array{data: list<array<string, mixed>>, done: bool} */
    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User) {
            return ['data' => [], 'done' => true];
        }
        $data = [];
        foreach (Plugin::storage()->requests_by_user((int) $user->ID, 500) as $row) {
            $data[] = [
                'group_id' => 'ffp-requests',
                'group_label' => 'Featured image requests',
                'item_id' => 'ffp-request-' . $row['id'],
                'data' => [
                    ['name' => 'Request', 'value' => (string) $row['id']],
                    ['name' => 'Post', 'value' => (string) $row['post_id']],
                    ['name' => 'Outcome', 'value' => $row['state'] . ' ' . $row['reason']],
                    ['name' => 'Time', 'value' => gmdate('c', (int) $row['created_at'])],
                ],
            ];
        }
        foreach (self::attachments_of((int) $user->ID) as $id) {
            $data[] = [
                'group_id' => 'ffp-suggestions',
                'group_label' => 'Generated image suggestions',
                'item_id' => 'ffp-suggestion-' . $id,
                'data' => [
                    ['name' => 'Attachment', 'value' => (string) $id],
                    ['name' => 'State', 'value' => Suggestions::state($id)],
                ],
            ];
        }
        return ['data' => $data, 'done' => true];
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} */
    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $uid = (int) $user->ID;
        $changed = Plugin::storage()->anonymise_user($uid) > 0;
        foreach (self::attachments_of($uid) as $id) {
            $record = Suggestions::record($id);
            if ((int) ($record['user_id'] ?? 0) === $uid) {
                $record['user_id'] = 0;
                update_post_meta($id, Suggestions::META_RECORD, $record);
                $changed = true;
            }
            $history = get_post_meta($id, Suggestions::META_HISTORY, true);
            if (is_array($history)) {
                foreach ($history as $i => $entry) {
                    if (is_array($entry) && (int) ($entry['user'] ?? 0) === $uid) {
                        $history[$i]['user'] = 0;
                        $changed = true;
                    }
                }
                update_post_meta($id, Suggestions::META_HISTORY, $history);
            }
        }
        return ['items_removed' => $changed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    /** @return list<int> */
    private static function attachments_of(int $user_id): array
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'meta_key' => Suggestions::META_GENERATED,
            'meta_value' => '1',
            'fields' => 'ids',
            'numberposts' => -1,
            'suppress_filters' => true,
        ]);
        $out = [];
        foreach (is_array($ids) ? $ids : [] as $id) {
            $id = (int) $id;
            $record = Suggestions::record($id);
            $history = get_post_meta($id, Suggestions::META_HISTORY, true);
            $in_history = false;
            foreach (is_array($history) ? $history : [] as $entry) {
                if (is_array($entry) && (int) ($entry['user'] ?? 0) === $user_id) {
                    $in_history = true;
                }
            }
            if ((int) ($record['user_id'] ?? 0) === $user_id || $in_history) {
                $out[] = $id;
            }
        }
        return $out;
    }
}
