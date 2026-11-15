<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * A Site Health test: provider, switch, cron, backlog, image editor.
 */
final class SiteHealth
{
    public static function register(): void
    {
        add_filter('site_status_tests', [self::class, 'tests']);
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public static function tests(array $tests): array
    {
        $tests['direct']['featured_from_prompt'] = ['label' => 'Featured from prompt', 'test' => [self::class, 'run']];
        return $tests;
    }

    /** @return array<string, mixed> */
    public static function run(): array
    {
        $problems = [];
        $notes = [];
        $provider_id = Settings::provider_id();
        $provider = $provider_id !== '' ? Plugin::providers()->get($provider_id) : null;
        if ($provider === null || !$provider->is_configured(Registry::settings_for($provider))) {
            $problems[] = 'No image provider is configured.';
        } else {
            $notes[] = 'Provider: ' . $provider->label() . (Pauses::is_paused($provider_id) ? ' (paused)' : '') . '.';
        }
        $notes[] = 'Switch: ' . (Settings::enabled() ? 'on' : 'off') . '.';
        $missing = Plugin::schema_missing();
        if ($missing !== []) {
            $problems[] = 'Database columns missing: ' . implode(', ', $missing) . '.';
        }
        $backlog = Plugin::storage()->count_requests(['queued']);
        $notes[] = 'Queue: ' . $backlog . ' waiting.';
        $last = (int) get_option(Queue::LAST_BATCH_OPTION, 0);
        if ($backlog > 0 && $last < Plugin::clock()->now() - 900) {
            $problems[] = 'Requests are waiting but no batch has run in 15 minutes. Check WP-Cron.';
        }
        $editor = function_exists('wp_image_editor_supports') && wp_image_editor_supports(['methods' => ['crop', 'save']]);
        $notes[] = 'Image editor: ' . ($editor ? 'available' : 'none (no crop, no conversion)') . '.';

        return [
            'label' => $problems === [] ? 'Featured from prompt is working' : 'Featured from prompt needs attention',
            'status' => $problems === [] ? 'good' : 'recommended',
            'badge' => ['label' => 'Featured from prompt', 'color' => 'blue'],
            'description' => '<p>' . esc_html(implode(' ', array_merge($problems, $notes))) . '</p>',
            'test' => 'featured_from_prompt',
        ];
    }
}
