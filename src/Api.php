<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

use IndianOpinion\FeaturedFromPrompt\Provider\ImageProvider;

/**
 * The public PHP API (5.2). The namespace functions in functions.php and
 * the global ffp_* wrappers call these.
 */
final class Api
{
    /** @param array<string, mixed> $args */
    public static function request(int $post_id, array $args = []): RequestResult
    {
        return Queue::request($post_id, $args);
    }

    /** @param array<string, mixed> $args */
    public static function run_request(string $request_id, array $args = []): RequestRecord
    {
        return Worker::run($request_id, $args);
    }

    public static function preview_prompt(int $post_id, ?string $override = null): PromptPreview
    {
        $provider = Plugin::providers()->get(Settings::provider_id());
        return PromptBuilder::preview($post_id, $override, $provider?->capabilities());
    }

    public static function accept(int $attachment_id, int $user_id, int $post_id = 0, bool $confirm_lock = false): AcceptResult
    {
        return Suggestions::accept($attachment_id, $user_id, $post_id, $confirm_lock);
    }

    public static function reject(int $attachment_id, int $user_id, string $reason = ''): RejectResult
    {
        return Suggestions::reject($attachment_id, $user_id, $reason);
    }

    public static function is_generated(int $attachment_id): bool
    {
        return Suggestions::is_generated($attachment_id);
    }

    public static function is_placeholder(int $attachment_id): bool
    {
        return Placeholders::is_placeholder($attachment_id);
    }

    public static function register_provider(ImageProvider $provider): void
    {
        Plugin::providers()->register($provider);
    }

    /**
     * The post record, pending suggestions and the caps remaining.
     *
     * @return array<string, mixed>
     */
    public static function status(int $post_id): array
    {
        $thumb = (int) get_post_meta($post_id, '_thumbnail_id', true);
        $valid = Placeholders::valid_thumbnail_id($post_id);
        $last = get_post_meta($post_id, Queue::LAST_META, true);
        $last = is_array($last) ? $last : null;
        $marker = get_post_meta($post_id, Queue::MARKER_META, true);
        $active = Plugin::storage()->active_request_for_post($post_id);
        $provider_id = Settings::provider_id();
        $provider = $provider_id !== '' ? Plugin::providers()->get($provider_id) : null;

        $suggestions = [];
        foreach (Suggestions::pending_for_post($post_id) as $id) {
            $record = Suggestions::record($id);
            $suggestions[] = [
                'id' => $id,
                'preview_url' => Rest::preview_url($id),
                'prompt' => (string) ($record['prompt'] ?? ''),
                'revised_prompt' => (string) ($record['revised_prompt'] ?? ''),
                'provider' => (string) ($record['provider_id'] ?? ''),
                'model' => (string) ($record['model'] ?? ''),
                'width' => (int) ($record['real_width'] ?? 0),
                'height' => (int) ($record['real_height'] ?? 0),
                'created' => (int) ($record['created'] ?? 0),
                'state' => Suggestions::state($id),
            ];
        }

        return [
            'post_id' => $post_id,
            'enabled' => Settings::enabled(),
            'auto_mode' => (string) Settings::get('auto_mode'),
            'opted_out' => (string) get_post_meta($post_id, Eligibility::OPT_OUT_META, true) === '1',
            'featured' => [
                'id' => $thumb,
                'valid' => $valid > 0,
                'placeholder' => $valid > 0 && Placeholders::is_placeholder($valid),
                'generated' => Suggestions::is_generated($valid),
                'url' => $valid > 0 ? (string) wp_get_attachment_url($valid) : '',
            ],
            'last' => $last,
            'last_message' => $last !== null ? Reason::describe((string) ($last['reason'] ?? '')) : '',
            'marker' => is_array($marker) && $marker !== [] ? $marker : null,
            'active' => $active === null ? null : [
                'id' => (string) $active['id'],
                'state' => (string) $active['state'],
                'reason' => (string) $active['reason'],
                'message' => Reason::describe((string) $active['reason']),
                'next_run' => (int) $active['next_run'],
                'attempts' => (int) $active['attempts'],
            ],
            'suggestions' => $suggestions,
            'caps' => Caps::remaining($post_id),
            'provider' => [
                'id' => $provider_id,
                'label' => $provider !== null ? $provider->label() : '',
                'paused' => $provider_id !== '' && Pauses::is_paused($provider_id),
            ],
            'schema_ok' => Plugin::schema_missing() === [],
        ];
    }
}
