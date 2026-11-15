<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

use IndianOpinion\FeaturedFromPrompt\Provider\FixtureProvider;
use IndianOpinion\FeaturedFromPrompt\Provider\GenericHttpProvider;

/**
 * Service container and hook wiring.
 */
final class Plugin
{
    private static ?self $instance = null;

    /** @var list<string>|null */
    private ?array $missing = null;

    public function __construct(
        public readonly Storage $storage,
        public readonly Clock $clock,
        public readonly Registry $providers,
        public readonly Logger $logger,
    ) {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(new WpdbStorage(), new Clock(), new Registry(), new Logger());
        }
        return self::$instance;
    }

    public static function set_instance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    public static function storage(): Storage
    {
        return self::instance()->storage;
    }

    public static function clock(): Clock
    {
        return self::instance()->clock;
    }

    public static function providers(): Registry
    {
        return self::instance()->providers;
    }

    public static function logger(): Logger
    {
        return self::instance()->logger;
    }

    /**
     * Columns missing from the live tables. Checked once per process and
     * after every install; the plugin refuses to queue or run while any is
     * missing.
     *
     * @return list<string>
     */
    public static function schema_missing(bool $refresh = false): array
    {
        $self = self::instance();
        if ($refresh || $self->missing === null) {
            $self->missing = $self->storage->missing_columns();
        }
        return $self->missing;
    }

    /** Activation: tables, verification, private directory. */
    public static function activate(bool $network_wide = false): void
    {
        if (is_multisite() && $network_wide) {
            // Each site installs on first use (case 89).
            return;
        }
        self::install();
    }

    public static function install(): void
    {
        $storage = self::storage();
        $storage->install();
        $missing = self::schema_missing(true);
        if ($missing !== []) {
            self::logger()->notice('Schema check failed after install; the plugin will not run.', ['missing' => $missing]);
        }
        if (get_option(Settings::OPTION, null) === null) {
            add_option(Settings::OPTION, Settings::defaults(), '', true);
        }
        try {
            Paths::ensure_private_dir();
        } catch (\RuntimeException $e) {
            self::logger()->notice('Private directory could not be created.', ['error' => $e->getMessage()]);
        }
    }

    /** Install on sites that have not been set up yet (multisite network activation). */
    public static function maybe_install(): void
    {
        if ((int) get_option(WpdbStorage::SCHEMA_OPTION, 0) < WpdbStorage::SCHEMA_VERSION) {
            self::install();
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Queue::CRON_HOOK);
        wp_clear_scheduled_hook(Cleanup::CRON_HOOK);
    }

    /** Wire every hook. Called once on plugins_loaded. */
    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_install'], 5);
        add_action('ffp_register_providers', [self::class, 'register_builtin_providers'], 5);
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action(Queue::CRON_HOOK, [Queue::class, 'run_batch']);
        add_action(Cleanup::CRON_HOOK, [Cleanup::class, 'run_cron']);
        add_action('init', [Cleanup::class, 'ensure_scheduled']);

        Triggers::register();
        Labels::register();
        Rest::register();
        Privacy::register();
        SiteHealth::register();

        add_action('updated_post_meta', [self::class, 'on_meta'], 10, 4);
        add_action('added_post_meta', [self::class, 'on_meta'], 10, 4);
        add_action('delete_attachment', [Suggestions::class, 'on_delete_attachment']);
        add_filter('wp_get_attachment_url', [self::class, 'filter_attachment_url'], 10, 2);

        add_filter('gen_queue_job_handlers', [self::class, 'gen_queue_handlers']);

        if (is_admin()) {
            Admin\SettingsPage::register();
            Admin\PostPanel::register();
            Admin\MediaLibrary::register();
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
            \WP_CLI::add_command('featured-from-prompt', Cli\Command::class);
        }
    }

    /**
     * @param mixed $meta_value
     */
    public static function on_meta(int $meta_id, int $object_id, string $meta_key, $meta_value): void
    {
        Suggestions::on_thumbnail_meta($meta_id, $object_id, $meta_key, $meta_value);
    }

    /** @param mixed $url */
    public static function filter_attachment_url($url, int $attachment_id): string
    {
        return Suggestions::filter_url(is_string($url) ? $url : '', $attachment_id);
    }

    public static function register_builtin_providers(Registry $registry): void
    {
        $registry->register(new GenericHttpProvider());
        $registry->register(new FixtureProvider());
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function cron_schedules(array $schedules): array
    {
        $schedules[Queue::CRON_SCHEDULE] = ['interval' => 60, 'display' => 'Every minute (featured-from-prompt)'];
        return $schedules;
    }

    /**
     * @param mixed $handlers
     * @return array<string, callable>
     */
    public static function gen_queue_handlers($handlers): array
    {
        $handlers = is_array($handlers) ? $handlers : [];
        $handlers[Integrations::JOB_TYPE] = [Queue::class, 'handle_job'];
        return $handlers;
    }
}
