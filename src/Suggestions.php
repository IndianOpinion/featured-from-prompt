<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Suggestions: storing, reviewing and the state machine (cases 56 to 65).
 *
 * A suggestion is an attachment post whose file sits in the protected
 * directory until a person accepts it. Only accept() and the media modal
 * pick (on_thumbnail_meta) ever make a generated image featured.
 */
final class Suggestions
{
    public const META_GENERATED = '_ffp_generated';
    public const META_STATE = '_ffp_state';
    public const META_STATE_TIME = '_ffp_state_time';
    public const META_RECORD = '_ffp_record';
    public const META_HISTORY = '_ffp_history';
    public const STATES = ['pending', 'accepted', 'rejected', 'superseded', 'expired'];

    public static function is_generated(int $attachment_id): bool
    {
        return $attachment_id > 0 && (string) get_post_meta($attachment_id, self::META_GENERATED, true) === '1';
    }

    public static function state(int $attachment_id): string
    {
        return (string) get_post_meta($attachment_id, self::META_STATE, true);
    }

    /** @return array<string, mixed> */
    public static function record(int $attachment_id): array
    {
        $record = get_post_meta($attachment_id, self::META_RECORD, true);
        return is_array($record) ? $record : [];
    }

    /**
     * Store a checked image as a pending suggestion (cases 56 to 59).
     *
     * @param array<string, mixed> $record Generation record.
     * @throws CheckFailed With store_failed or record_failed.
     */
    public static function store(int $post_id, CheckedImage $image, array $record): int
    {
        try {
            $dir = Paths::ensure_private_dir();
        } catch (\RuntimeException $e) {
            ImageChecker::remove($image->path);
            Plugin::logger()->notice('Private directory refused; nothing stored.', ['error' => $e->getMessage()]);
            throw new CheckFailed(Reason::STORE_FAILED, $e->getMessage());
        }
        $path = $dir . '/' . Paths::random_name($image->ext);
        if (!rename($image->path, $path)) {
            if (!copy($image->path, $path)) {
                ImageChecker::remove($image->path);
                throw new CheckFailed(Reason::STORE_FAILED, 'Cannot move the image into the private directory.');
            }
            ImageChecker::remove($image->path);
        }

        $post = get_post($post_id);
        $title = $post instanceof \WP_Post ? PromptBuilder::plain($post->post_title) : '';
        $settings = Settings::all();
        $label_caption = in_array($settings['label_mode'], ['caption', 'both'], true);
        $alt = str_replace('{title}', $title, (string) $settings['alt_template']);
        $data = [
            'title' => 'Generated image for: ' . $title,
            'alt' => $alt,
            'caption' => $label_caption ? (string) $settings['label_text'] : '',
        ];
        $filtered = apply_filters('ffp_attachment_data', $data, $post_id, $record);
        if (is_array($filtered)) {
            foreach (['title', 'alt', 'caption'] as $key) {
                if (isset($filtered[$key]) && is_string($filtered[$key])) {
                    $data[$key] = $filtered[$key];
                }
            }
        }
        $data['title'] = sanitize_text_field($data['title']);
        $data['alt'] = mb_substr(sanitize_text_field($data['alt']), 0, 250);
        $data['caption'] = sanitize_text_field($data['caption']);

        $attachment_id = Guard::run(static fn () => wp_insert_attachment([
            'post_mime_type' => $image->mime,
            'post_title' => $data['title'],
            'post_excerpt' => $data['caption'],
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $post_id,
        ], $path, $post_id, true));
        if (is_wp_error($attachment_id) || !is_int($attachment_id) || $attachment_id <= 0) {
            ImageChecker::remove($path);
            throw new CheckFailed(Reason::STORE_FAILED, 'wp_insert_attachment failed.');
        }

        $now = Plugin::clock()->now();
        $record['created'] = $now;
        $record['file'] = basename($path);
        $history = [['time' => $now, 'user' => (int) ($record['user_id'] ?? 0), 'state' => 'pending', 'reason' => '']];
        $writes = [
            self::META_GENERATED => '1',
            self::META_STATE => 'pending',
            self::META_STATE_TIME => $now,
            self::META_RECORD => $record,
            self::META_HISTORY => $history,
        ];
        $ok = true;
        foreach ($writes as $key => $value) {
            if (update_post_meta($attachment_id, $key, $value) === false || get_post_meta($attachment_id, $key, true) === '') {
                $ok = false;
                break;
            }
        }
        if (!$ok) {
            // A generated image without its record must not exist (case 57).
            Guard::run(static fn () => wp_delete_attachment($attachment_id, true));
            ImageChecker::remove($path);
            throw new CheckFailed(Reason::RECORD_FAILED);
        }
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $data['alt']);
        return $attachment_id;
    }

    /**
     * Pending suggestions of a post, newest first.
     *
     * @return list<int>
     */
    public static function pending_for_post(int $post_id): array
    {
        return self::by_state_for_post($post_id, 'pending');
    }

    /** @return list<int> */
    public static function by_state_for_post(int $post_id, string $state): array
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => $post_id,
            'meta_key' => self::META_STATE,
            'meta_value' => $state,
            'fields' => 'ids',
            'numberposts' => 100,
            'orderby' => 'ID',
            'order' => 'DESC',
            'suppress_filters' => true,
        ]);
        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    /** Record a state change with history (last 10). */
    public static function set_state(int $attachment_id, string $state, int $user_id, string $reason = ''): void
    {
        $now = Plugin::clock()->now();
        $history = get_post_meta($attachment_id, self::META_HISTORY, true);
        $history = is_array($history) ? $history : [];
        $history[] = ['time' => $now, 'user' => $user_id, 'state' => $state, 'reason' => $reason];
        $history = array_slice($history, -10);
        Guard::run(static function () use ($attachment_id, $state, $now, $history): void {
            update_post_meta($attachment_id, self::META_STATE, $state);
            update_post_meta($attachment_id, self::META_STATE_TIME, $now);
            update_post_meta($attachment_id, self::META_HISTORY, $history);
        });
    }

    /**
     * Accept a suggestion (case 61, 62). With $post_id set, the suggestion
     * must belong to that post. While another editor holds the post lock the
     * call returns post_locked unless $confirm_lock is true.
     */
    public static function accept(int $attachment_id, int $user_id, int $post_id = 0, bool $confirm_lock = false): AcceptResult
    {
        $check = self::review_checks($attachment_id, $user_id, $post_id);
        if ($check !== null) {
            return new AcceptResult(false, $check, $attachment_id, $post_id);
        }
        $parent = (int) get_post_field('post_parent', $attachment_id);

        $lock = self::lock_holder($parent, $user_id);
        if ($lock > 0 && !$confirm_lock) {
            return new AcceptResult(false, Reason::POST_LOCKED, $attachment_id, $parent, 0, $lock);
        }

        $previous = (int) get_post_meta($parent, '_thumbnail_id', true);
        $published = self::publish_file($attachment_id);
        if ($published !== null) {
            return new AcceptResult(false, $published, $attachment_id, $parent);
        }
        $set = Guard::run(static fn () => set_post_thumbnail($parent, $attachment_id));
        if ($set === false && (int) get_post_meta($parent, '_thumbnail_id', true) !== $attachment_id) {
            return new AcceptResult(false, Reason::STORE_FAILED, $attachment_id, $parent);
        }
        self::finish_accept($attachment_id, $parent, $previous, $user_id, $lock);
        return new AcceptResult(true, '', $attachment_id, $parent, $previous, $lock);
    }

    /** Reject a suggestion (case 63). Starts nothing. */
    public static function reject(int $attachment_id, int $user_id, string $reason = '', int $post_id = 0): RejectResult
    {
        $check = self::review_checks($attachment_id, $user_id, $post_id);
        if ($check !== null) {
            return new RejectResult(false, $check, $attachment_id, $post_id);
        }
        $parent = (int) get_post_field('post_parent', $attachment_id);
        $reason = mb_substr(trim(sanitize_text_field($reason)), 0, 200);
        self::set_state($attachment_id, 'rejected', $user_id, $reason);
        do_action('ffp_rejected', $parent, $attachment_id, $user_id, $reason);
        return new RejectResult(true, '', $attachment_id, $parent);
    }

    /**
     * Case 65: an editor picked a pending suggestion in the media modal.
     * Records the person's choice; never sets a thumbnail itself.
     *
     * @param mixed $meta_value
     */
    public static function on_thumbnail_meta(int $meta_id, int $object_id, string $meta_key, $meta_value): void
    {
        if ($meta_key !== '_thumbnail_id' || Guard::active()) {
            return;
        }
        $attachment_id = is_numeric($meta_value) ? (int) $meta_value : 0;
        if (!self::is_generated($attachment_id) || self::state($attachment_id) !== 'pending') {
            return;
        }
        if (!self::person_may_pick($attachment_id, $object_id)) {
            return;
        }
        Guard::run(static function () use ($attachment_id, $object_id): void {
            if (self::publish_file($attachment_id) !== null) {
                return;
            }
            self::finish_accept($attachment_id, $object_id, 0, get_current_user_id(), 0);
        });
    }

    /**
     * Case 65 gate, on the update_post_metadata and add_post_metadata
     * filters: a pending suggestion may become a featured image outside
     * Accept only when a person who could accept it picks it for its own
     * post. Any other write (code, cron, another post, a user without the
     * capabilities) is refused before it happens.
     *
     * @param mixed $check
     * @param mixed $object_id
     * @param mixed $meta_key
     * @param mixed $meta_value
     * @return mixed
     */
    public static function guard_thumbnail_write($check, $object_id, $meta_key, $meta_value)
    {
        if ($meta_key !== '_thumbnail_id' || Guard::active() || $check !== null) {
            return $check;
        }
        $attachment_id = is_numeric($meta_value) ? (int) $meta_value : 0;
        if (!self::is_generated($attachment_id) || self::state($attachment_id) !== 'pending') {
            return $check;
        }
        $post_id = (int) $object_id;
        if (self::person_may_pick($attachment_id, $post_id)) {
            return $check;
        }
        Plugin::logger()->notice('Refused to set a pending suggestion as a featured image outside Accept.', [
            'post_id' => $post_id,
            'attachment_id' => $attachment_id,
            'user_id' => get_current_user_id(),
        ]);
        return false;
    }

    private static function person_may_pick(int $attachment_id, int $post_id): bool
    {
        return get_current_user_id() > 0
            && (int) get_post_field('post_parent', $attachment_id) === $post_id
            && current_user_can('edit_post', $post_id)
            && current_user_can('upload_files');
    }

    /** Delete the private file when its attachment is deleted. */
    public static function on_delete_attachment(int $attachment_id): void
    {
        $file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        if ($file !== '' && Paths::is_private($file) && is_file($file)) {
            unlink($file);
        }
    }

    /** The private preview URL for a pending file, else the URL unchanged. */
    public static function filter_url(string $url, int $attachment_id): string
    {
        $file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        if ($file === '' || !Paths::is_private($file)) {
            return $url;
        }
        return Rest::preview_url($attachment_id);
    }

    public static function private_file(int $attachment_id): string
    {
        $file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        return $file !== '' && Paths::is_private($file) && is_file($file) ? $file : '';
    }

    private static function review_checks(int $attachment_id, int $user_id, int $post_id): ?string
    {
        $attachment = get_post($attachment_id);
        if (!$attachment instanceof \WP_Post || $attachment->post_type !== 'attachment' || !self::is_generated($attachment_id)) {
            return Reason::NOT_GENERATED;
        }
        $parent = (int) $attachment->post_parent;
        if ($parent <= 0 || ($post_id > 0 && $parent !== $post_id)) {
            return Reason::WRONG_POST;
        }
        if (!user_can($user_id, 'edit_post', $parent) || !user_can($user_id, 'upload_files')) {
            return Reason::FORBIDDEN;
        }
        $post = get_post($parent);
        if (!$post instanceof \WP_Post || $post->post_status === 'trash') {
            return Reason::POST_GONE;
        }
        if (self::state($attachment_id) !== 'pending') {
            return Reason::NOT_PENDING;
        }
        return null;
    }

    /**
     * The user holding the WordPress post lock, if it is someone else.
     * Same rule as wp_check_post_lock(), for any user rather than the current one.
     */
    private static function lock_holder(int $post_id, int $user_id): int
    {
        $lock = get_post_meta($post_id, '_edit_lock', true);
        if (!is_string($lock) || $lock === '') {
            return 0;
        }
        $parts = explode(':', $lock);
        $holder = isset($parts[1]) ? (int) $parts[1] : (int) get_post_meta($post_id, '_edit_last', true);
        $time = (int) $parts[0];
        $window = (int) apply_filters('wp_check_post_lock_window', 150);
        if ($holder > 0 && $holder !== $user_id && $time > Plugin::clock()->now() - $window) {
            return $holder;
        }
        return 0;
    }

    /** Move the file out of the private directory and build sizes. */
    private static function publish_file(int $attachment_id): ?string
    {
        $file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        if ($file === '' || !Paths::is_private($file)) {
            return null;
        }
        if (!is_file($file)) {
            return Reason::FILE_MISSING;
        }
        try {
            $target = Paths::publish($file, Plugin::clock()->now(), (string) get_post_mime_type($attachment_id));
        } catch (\RuntimeException $e) {
            Plugin::logger()->notice('Accept could not move the file into uploads.', ['attachment_id' => $attachment_id, 'error' => $e->getMessage()]);
            return Reason::STORE_FAILED;
        }
        Guard::run(static function () use ($attachment_id, $target): void {
            update_attached_file($attachment_id, $target);
            if (!function_exists('wp_generate_attachment_metadata') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/image.php')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }
            if (function_exists('wp_generate_attachment_metadata')) {
                wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $target));
            }
        });
        return null;
    }

    private static function finish_accept(int $attachment_id, int $post_id, int $previous, int $user_id, int $lock_user): void
    {
        self::set_state($attachment_id, 'accepted', $user_id, $lock_user > 0 ? 'accepted over post lock' : '');
        foreach (self::pending_for_post($post_id) as $other) {
            if ($other !== $attachment_id) {
                self::set_state($other, 'superseded', $user_id);
            }
        }
        $placeholder = get_post_meta($post_id, Placeholders::SET_META, true);
        if (is_array($placeholder)) {
            delete_post_meta($post_id, Placeholders::SET_META);
        }
        do_action('ffp_accepted', $post_id, $attachment_id, $previous, $user_id);
    }
}
