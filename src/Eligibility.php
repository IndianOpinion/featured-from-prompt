<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Case 1 to 4: may this post get a request?
 */
final class Eligibility
{
    public const OPT_OUT_META = '_ffp_opt_out';

    /**
     * @return string|null A reason code, or null when the post is eligible.
     */
    public static function check(int $post_id, bool $auto, string $context = 'request'): ?string
    {
        if (!Settings::enabled()) {
            return Reason::DISABLED;
        }
        $post = get_post($post_id);
        if (
            !$post instanceof \WP_Post || in_array($post->post_status, ['trash', 'auto-draft'], true)
            || $post->post_type === 'revision'
        ) {
            return Reason::POST_GONE;
        }
        $types = (array) Settings::get('post_types');
        if (!in_array($post->post_type, $types, true)) {
            return Reason::POST_TYPE_NOT_ENABLED;
        }
        if (!post_type_supports($post->post_type, 'thumbnail')) {
            return Reason::NO_THUMBNAIL_SUPPORT;
        }
        $protected = $post->post_password !== '' || $post->post_status === 'private';
        if ($protected && !(bool) Settings::get('include_private')) {
            return Reason::POST_PROTECTED;
        }
        if ((string) get_post_meta($post_id, self::OPT_OUT_META, true) === '1') {
            return Reason::POST_OPTED_OUT;
        }
        if (!(bool) apply_filters('ffp_should_process', true, $post_id, $context)) {
            return Reason::FILTERED_OUT;
        }
        if ($auto && Placeholders::usable_image_id($post_id) > 0) {
            return Reason::HAS_IMAGE;
        }
        return null;
    }
}
