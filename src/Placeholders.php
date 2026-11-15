<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Placeholders are known by ID, never by size (cases 66 to 71).
 */
final class Placeholders
{
    public const SET_META = '_ffp_placeholder_set';

    public static function is_placeholder(int $attachment_id): bool
    {
        if ($attachment_id <= 0) {
            return false;
        }
        $ids = array_map('intval', (array) Settings::get('placeholder_ids'));
        $fallback = (int) Settings::get('fallback_placeholder');
        if ($fallback > 0) {
            $ids[] = $fallback;
        }
        $is = in_array($attachment_id, $ids, true);
        return (bool) apply_filters('ffp_is_placeholder', $is, $attachment_id);
    }

    /** The featured image ID when it is a real image and not a placeholder, else 0. */
    public static function usable_image_id(int $post_id): int
    {
        $thumb = self::valid_thumbnail_id($post_id);
        if ($thumb === 0 || self::is_placeholder($thumb)) {
            return 0;
        }
        return $thumb;
    }

    /** The featured image ID when it points to an existing image attachment, else 0 (case 3). */
    public static function valid_thumbnail_id(int $post_id): int
    {
        $thumb = (int) get_post_meta($post_id, '_thumbnail_id', true);
        if ($thumb <= 0 || !wp_attachment_is_image($thumb)) {
            return 0;
        }
        return $thumb;
    }

    /**
     * Apply the on_failure policy after a final failure.
     *
     * @param array<string, mixed> $row The finished request.
     * @return int The placeholder set, or 0.
     */
    public static function apply_policy(int $post_id, array $row): int
    {
        if ((string) Settings::get('on_failure') !== 'placeholder') {
            return 0;
        }
        // Never replaces an existing image, placeholder or not.
        if (self::valid_thumbnail_id($post_id) > 0) {
            return 0;
        }
        $configured = (int) Settings::get('fallback_placeholder');
        $id = apply_filters('ffp_placeholder_id', $configured, $post_id);
        $id = is_numeric($id) ? (int) $id : 0;
        if ($id <= 0) {
            return 0;
        }
        if (!wp_attachment_is_image($id) || Suggestions::is_generated($id)) {
            Plugin::logger()->notice('Placeholder ID is not an ordinary image attachment; ignored.', [
                'post_id' => $post_id,
                'attachment_id' => $id,
            ]);
            return 0;
        }
        Guard::run(static function () use ($post_id, $id): void {
            set_post_thumbnail($post_id, $id);
        });
        update_post_meta($post_id, self::SET_META, ['attachment_id' => $id, 'time' => Plugin::clock()->now()]);
        do_action('ffp_placeholder_set', $post_id, $id, $row);
        return $id;
    }
}
