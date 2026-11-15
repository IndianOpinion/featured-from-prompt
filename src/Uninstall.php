<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Uninstall clean-up, per site.
 */
final class Uninstall
{
    public static function run_all_sites(): void
    {
        if (is_multisite()) {
            $sites = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach (is_array($sites) ? $sites : [] as $site_id) {
                switch_to_blog((int) $site_id);
                self::run();
                restore_current_blog();
            }
            return;
        }
        self::run();
    }

    public static function run(): void
    {
        wp_clear_scheduled_hook(Queue::CRON_HOOK);
        wp_clear_scheduled_hook(Cleanup::CRON_HOOK);
        if ((bool) Settings::get('keep_on_uninstall')) {
            return;
        }

        // Generated attachments that were never accepted and are not featured anywhere.
        foreach (['pending', 'rejected', 'superseded', 'expired'] as $state) {
            $ids = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'meta_key' => Suggestions::META_STATE,
                'meta_value' => $state,
                'fields' => 'ids',
                'numberposts' => -1,
                'suppress_filters' => true,
            ]);
            foreach (is_array($ids) ? $ids : [] as $id) {
                $id = (int) $id;
                if (!Cleanup::is_featured_anywhere($id)) {
                    Suggestions::on_delete_attachment($id);
                    wp_delete_attachment($id, true);
                }
            }
        }

        // Plugin post meta on posts. Attachment meta of accepted images stays.
        foreach ([Eligibility::OPT_OUT_META, Queue::LAST_META, Queue::MARKER_META, Queue::SUGGESTION_META, Caps::POST_META, Placeholders::SET_META] as $key) {
            delete_post_meta_by_key($key);
        }

        $registry = Plugin::providers();
        $providers = $registry->all();
        foreach ([new Provider\GenericHttpProvider(), new Provider\FixtureProvider()] as $builtin) {
            $providers[$builtin->id()] ??= $builtin;
        }
        foreach ($providers as $id => $provider) {
            delete_option(Pauses::option((string) $id));
            foreach ($provider->settings_fields() as $field) {
                if ($field['type'] === 'secret') {
                    delete_option(Secrets::option_name((string) $id, $field['key']));
                }
            }
        }
        self::delete_options_like('ffp_secret_');
        self::delete_options_like('ffp_pause_');
        self::delete_options_like('_ffp_lock_');
        foreach ([Settings::OPTION, Logger::RECENT_OPTION, PromptBuilder::WARNED_OPTION, Queue::LAST_BATCH_OPTION, WpdbStorage::SCHEMA_OPTION] as $option) {
            delete_option($option);
        }

        Plugin::storage()->drop();
        Paths::remove_private_dir();
    }

    private static function delete_options_like(string $prefix): void
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return;
        }
        $sql = $wpdb->prepare('SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like($prefix) . '%');
        $names = is_string($sql) ? $wpdb->get_col($sql) : [];
        foreach (is_array($names) ? $names : [] as $name) {
            delete_option((string) $name);
        }
    }
}
