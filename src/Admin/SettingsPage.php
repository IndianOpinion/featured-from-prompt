<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Admin;

use IndianOpinion\FeaturedFromPrompt\Caps;
use IndianOpinion\FeaturedFromPrompt\Constants;
use IndianOpinion\FeaturedFromPrompt\Pauses;
use IndianOpinion\FeaturedFromPrompt\Plugin;
use IndianOpinion\FeaturedFromPrompt\Reason;
use IndianOpinion\FeaturedFromPrompt\Secrets;
use IndianOpinion\FeaturedFromPrompt\Settings;
use IndianOpinion\FeaturedFromPrompt\Worker;
use IndianOpinion\FeaturedFromPrompt\Queue;

/**
 * Settings screen under Settings (5.8).
 */
final class SettingsPage
{
    public const SLUG = 'featured-from-prompt';
    public const NONCE = 'ffp_settings';
    public const NOTICE_TRANSIENT = 'ffp_admin_message';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_ffp_save_settings', [self::class, 'handle_save']);
        add_action('admin_post_ffp_test_provider', [self::class, 'handle_test']);
        add_action('admin_post_ffp_resume_provider', [self::class, 'handle_resume']);
        add_action('admin_notices', [self::class, 'notices']);
    }

    public static function capability(): string
    {
        $cap = apply_filters('ffp_capability_settings', 'manage_options');
        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    public static function menu(): void
    {
        add_options_page('Featured from prompt', 'Featured from prompt', self::capability(), self::SLUG, [self::class, 'render']);
    }

    /**
     * Validate and store a settings form.
     *
     * @param array<string, mixed> $post Raw form data.
     * @return array{ok: bool, reason: string}
     */
    public static function process_save(array $post): array
    {
        if (!current_user_can(self::capability())) {
            return ['ok' => false, 'reason' => Reason::FORBIDDEN];
        }
        $nonce = isset($post['_ffp_nonce']) && is_string($post['_ffp_nonce']) ? $post['_ffp_nonce'] : '';
        if (wp_verify_nonce($nonce, self::NONCE . '_save') === false) {
            return ['ok' => false, 'reason' => Reason::BAD_NONCE];
        }
        $input = isset($post['ffp']) && is_array($post['ffp']) ? wp_unslash($post['ffp']) : [];
        $input = is_array($input) ? $input : [];

        $providers = Plugin::providers()->all();
        $secrets = isset($post['ffp_secret']) && is_array($post['ffp_secret']) ? $post['ffp_secret'] : [];
        $clear = isset($post['ffp_secret_clear']) && is_array($post['ffp_secret_clear']) ? $post['ffp_secret_clear'] : [];
        foreach ($providers as $id => $provider) {
            foreach ($provider->settings_fields() as $field) {
                if ($field['type'] !== 'secret') {
                    continue;
                }
                $key = $field['key'];
                if (!empty($clear[$id][$key])) {
                    Secrets::set($id, $key, '');
                    continue;
                }
                $value = $secrets[$id][$key] ?? '';
                if (is_string($value) && trim($value) !== '') {
                    Secrets::set($id, $key, trim(wp_unslash($value)));
                }
            }
        }
        Settings::save($input);
        return ['ok' => true, 'reason' => ''];
    }

    public static function handle_save(): void
    {
        $result = self::process_save($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in process_save.
        if (!$result['ok']) {
            wp_die(esc_html(Reason::describe($result['reason'])), 403);
        }
        self::redirect('saved');
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, reason: string, width: int, height: int, message: string}
     */
    public static function process_test(array $post): array
    {
        $fail = ['ok' => false, 'reason' => Reason::FORBIDDEN, 'width' => 0, 'height' => 0, 'message' => ''];
        if (!current_user_can(self::capability())) {
            return $fail;
        }
        $nonce = isset($post['_ffp_nonce']) && is_string($post['_ffp_nonce']) ? $post['_ffp_nonce'] : '';
        if (wp_verify_nonce($nonce, self::NONCE . '_test') === false) {
            return ['reason' => Reason::BAD_NONCE] + $fail;
        }
        $provider = isset($post['provider']) && is_string($post['provider']) ? sanitize_key($post['provider']) : '';
        return Worker::test_provider($provider);
    }

    public static function handle_test(): void
    {
        $result = self::process_test($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in process_test.
        if (in_array($result['reason'], [Reason::FORBIDDEN, Reason::BAD_NONCE], true)) {
            wp_die(esc_html(Reason::describe($result['reason'])), 403);
        }
        $message = $result['ok']
            ? sprintf('Test passed: %dx%d image. One request was counted.', $result['width'], $result['height'])
            : 'Test failed: ' . $result['reason'] . '. ' . $result['message'];
        set_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id(), $message, 120);
        self::redirect('tested');
    }

    public static function handle_resume(): void
    {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html(Reason::describe(Reason::FORBIDDEN)), 403);
        }
        $nonce = isset($_POST['_ffp_nonce']) && is_string($_POST['_ffp_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ffp_nonce'])) : '';
        if (wp_verify_nonce($nonce, self::NONCE . '_resume') === false) {
            wp_die(esc_html(Reason::describe(Reason::BAD_NONCE)), 403);
        }
        $provider = isset($_POST['provider']) && is_string($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
        Pauses::resume($provider);
        self::redirect('resumed');
    }

    private static function redirect(string $flag): void
    {
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'ffp' => $flag], admin_url('options-general.php')));
        exit;
    }

    public static function notices(): void
    {
        if (!current_user_can(self::capability())) {
            return;
        }
        $missing = Plugin::schema_missing();
        if ($missing !== []) {
            echo '<div class="notice notice-error"><p>' . esc_html('Featured from prompt: the database table is missing columns (' . implode(', ', $missing) . '). The plugin will not run. Deactivate and reactivate it.') . '</p></div>';
        }
        foreach (array_keys(Plugin::providers()->all()) as $id) {
            $pause = Pauses::get($id);
            if ($pause !== null) {
                $until = $pause['until'] > 0 ? ' until ' . wp_date('Y-m-d H:i', $pause['until']) : ' until an administrator resumes it';
                echo '<div class="notice notice-warning"><p>' . esc_html('Featured from prompt: provider "' . $id . '" is paused (' . $pause['reason'] . ')' . $until . '.') . '</p></div>';
            }
        }
        $message = get_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id());
        if (is_string($message) && $message !== '') {
            delete_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id());
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    public static function render(): void
    {
        if (!current_user_can(self::capability())) {
            return;
        }
        $s = Settings::all();
        $providers = Plugin::providers()->all();
        $caps = Caps::remaining();
        $storage = Plugin::storage();
        $queued = $storage->list_requests(['queued'], 200);
        $oldest = $queued !== [] ? end($queued) : null;
        $last_batch = (int) get_option(Queue::LAST_BATCH_OPTION, 0);

        echo '<div class="wrap"><h1>' . esc_html('Featured from prompt') . '</h1>';

        echo '<h2>' . esc_html('Status') . '</h2><table class="widefat striped" style="max-width:720px"><tbody>';
        self::row('Switch', Settings::enabled() ? 'On' : 'Off' . (Constants::get('FFP_DISABLED') === true ? ' (FFP_DISABLED is set)' : ''));
        self::row('Today', sprintf('%d of %d requests used (%s)', $caps['day_used'], $caps['day_cap'], $caps['day']));
        self::row('Queue', sprintf('%d waiting', count($queued)) . ($oldest !== null && $oldest !== false ? ', oldest from ' . wp_date('Y-m-d H:i', (int) $oldest['created_at']) : ''));
        self::row('Last batch', $last_batch > 0 ? (string) wp_date('Y-m-d H:i:s', $last_batch) : 'never');
        foreach (array_keys($providers) as $id) {
            $pause = Pauses::get($id);
            self::row('Provider ' . $id, $pause === null ? 'running' : 'paused: ' . $pause['reason']);
        }
        echo '</tbody></table>';

        foreach (array_keys($providers) as $id) {
            if (Pauses::get($id) === null) {
                continue;
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px">';
            echo '<input type="hidden" name="action" value="ffp_resume_provider" />';
            echo '<input type="hidden" name="provider" value="' . esc_attr($id) . '" />';
            wp_nonce_field(self::NONCE . '_resume', '_ffp_nonce');
            submit_button('Resume ' . $id, 'secondary', 'submit', false);
            echo '</form>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ffp_save_settings" />';
        wp_nonce_field(self::NONCE . '_save', '_ffp_nonce');
        echo '<h2>' . esc_html('Provider') . '</h2><table class="form-table" role="presentation"><tbody>';

        $constant_provider = Constants::get('FFP_PROVIDER');
        $choices = ['' => 'None'];
        foreach ($providers as $id => $provider) {
            $choices[$id] = $provider->label();
        }
        self::select('provider', 'Provider', $choices, (string) $s['provider'], is_string($constant_provider) && $constant_provider !== '' ? 'Set by FFP_PROVIDER: ' . $constant_provider : '');
        self::checkbox('enabled', 'Enabled', (bool) $s['enabled'], 'Off until a provider is chosen and saved. The kill switch: off stops every call at once.');
        echo '</tbody></table>';

        $stored = is_array($s['provider_settings']) ? $s['provider_settings'] : [];
        foreach ($providers as $id => $provider) {
            echo '<h3>' . esc_html($provider->label()) . '</h3><table class="form-table" role="presentation"><tbody>';
            foreach ($provider->settings_fields() as $field) {
                $key = $field['key'];
                $label = $field['label'];
                $help = $field['help'] ?? '';
                if ($field['type'] === 'secret') {
                    $from_constant = Secrets::from_constant($id, $key);
                    $state = $from_constant ? 'set by constant ' . Secrets::constant_name($id, $key) : (Secrets::is_set($id, $key) ? 'set' : 'not set');
                    echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
                    echo '<p><strong>' . esc_html($state) . '</strong></p>';
                    if (!$from_constant) {
                        echo '<input type="password" autocomplete="new-password" class="regular-text" name="ffp_secret[' . esc_attr($id) . '][' . esc_attr($key) . ']" value="" placeholder="' . esc_attr('Replace') . '" /> ';
                        echo '<label><input type="checkbox" name="ffp_secret_clear[' . esc_attr($id) . '][' . esc_attr($key) . ']" value="1" /> ' . esc_html('Clear') . '</label>';
                    }
                    if ($help !== '') {
                        echo '<p class="description">' . esc_html($help) . '</p>';
                    }
                    echo '</td></tr>';
                    continue;
                }
                $value = isset($stored[$id][$key]) && is_scalar($stored[$id][$key]) ? (string) $stored[$id][$key] : (string) ($field['default'] ?? '');
                $name = 'provider_settings][' . $id . '][' . $key;
                if ($field['type'] === 'select') {
                    self::select($name, $label, $field['options'] ?? [], $value, $help);
                } elseif ($key === 'body_template' || $key === 'extra_headers') {
                    self::textarea($name, $label, $value, $help);
                } else {
                    self::text($name, $label, $value, $help, $field['type'] === 'number' ? 'number' : ($field['type'] === 'url' ? 'url' : 'text'));
                }
            }
            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html('Triggers') . '</h2><table class="form-table" role="presentation"><tbody>';
        self::select('auto_mode', 'Auto mode', ['off' => 'Off', 'log' => 'Log only (no calls)', 'on' => 'On'], (string) $s['auto_mode'], 'Roll out with "Log only" first and read what it would have done.');
        self::checkbox('suggest_on_save', 'Suggest on save', (bool) $s['suggest_on_save']);
        self::checkbox('suggest_on_publish', 'Suggest on publish', (bool) $s['suggest_on_publish']);
        $types = [];
        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if ($type instanceof \WP_Post_Type && post_type_supports($type->name, 'thumbnail')) {
                $types[$type->name] = $type->label;
            }
        }
        echo '<tr><th scope="row">' . esc_html('Post types') . '</th><td>';
        foreach ($types as $name => $label) {
            $checked = in_array($name, (array) $s['post_types'], true) ? ' checked' : '';
            echo '<label style="margin-right:12px"><input type="checkbox" name="ffp[post_types][]" value="' . esc_attr($name) . '"' . $checked . ' /> ' . esc_html($label) . '</label>';
        }
        echo '</td></tr>';
        self::checkbox('include_private', 'Include private and protected posts', (bool) $s['include_private'], 'Off by default: their text would be sent to the provider.');
        echo '</tbody></table>';

        echo '<h2>' . esc_html('Prompt') . '</h2><table class="form-table" role="presentation"><tbody>';
        self::textarea('template', 'Template', (string) $s['template'], 'Variables: {title} {summary} {category} {tags} {post_type} {style} {site_language}.');
        self::text('style', 'Style', (string) $s['style']);
        self::textarea('negative_prompt', 'Negative prompt', (string) $s['negative_prompt'], 'Sent only to providers that accept one.');
        self::text('summary_words', 'Summary words', (string) $s['summary_words'], '0 to 200.', 'number');
        self::text('tags_in_prompt', 'Tags in prompt', (string) $s['tags_in_prompt'], '0 to 15.', 'number');
        self::text('prompt_limit', 'Prompt length limit', (string) $s['prompt_limit'], '100 to 4000 characters.', 'number');
        echo '</tbody></table>';

        echo '<h2>' . esc_html('Image checks') . '</h2><table class="form-table" role="presentation"><tbody>';
        self::text('target_aspect', 'Target aspect', (string) $s['target_aspect'], 'W:H, for example 16:9.');
        self::text('target_width', 'Target width (any-size providers)', (string) $s['target_width'], '', 'number');
        self::text('target_height', 'Target height (any-size providers)', (string) $s['target_height'], '', 'number');
        self::text('min_width', 'Minimum width', (string) $s['min_width'], '', 'number');
        self::text('min_height', 'Minimum height', (string) $s['min_height'], '', 'number');
        self::text('min_ratio', 'Lowest width/height ratio', (string) $s['min_ratio'], '', 'number');
        self::text('max_ratio', 'Highest width/height ratio', (string) $s['max_ratio'], '', 'number');
        self::checkbox('crop', 'Crop to target aspect', (bool) $s['crop']);
        self::select('output_format', 'Output format', ['keep' => 'Keep', 'jpeg' => 'JPEG', 'webp' => 'WebP'], (string) $s['output_format']);
        self::text('quality', 'Quality', (string) $s['quality'], '40 to 100.', 'number');
        self::text('max_mb', 'Maximum file size (MB)', (string) $s['max_mb'], '', 'number');
        self::text('max_mp', 'Maximum megapixels', (string) $s['max_mp'], '', 'number');
        echo '</tbody></table>';

        echo '<h2>' . esc_html('Limits') . '</h2><table class="form-table" role="presentation"><tbody>';
        self::text('day_cap', 'Requests per day (site)', (string) $s['day_cap'], '0 means none run. FFP_DAY_CAP can only lower it.', 'number');
        self::text('post_cap', 'Requests per post per day', (string) $s['post_cap'], '1 to 20.', 'number');
        self::text('timeout', 'Timeout (seconds)', (string) $s['timeout'], '', 'number');
        self::text('attempts', 'Attempts on transient errors', (string) $s['attempts'], '1 to 5.', 'number');
        self::text('quota_pause', 'Pause after a quota error (seconds)', (string) $s['quota_pause'], '', 'number');
        self::text('batch_size', 'Batch size', (string) $s['batch_size'], '', 'number');
        self::text('batch_budget', 'Batch time budget (seconds)', (string) $s['batch_budget'], '', 'number');
        self::text('queue_age', 'Queue age limit for automatic requests (seconds)', (string) $s['queue_age'], '', 'number');
        echo '</tbody></table>';

        echo '<h2>' . esc_html('Failure, labels and retention') . '</h2><table class="form-table" role="presentation"><tbody>';
        self::select('on_failure', 'On failure', ['none' => 'Leave the post as it is', 'placeholder' => 'Set the fallback placeholder when the post has no image'], (string) $s['on_failure']);
        self::text('fallback_placeholder', 'Fallback placeholder (attachment ID)', (string) $s['fallback_placeholder'], '', 'number');
        self::text('placeholder_ids', 'Other placeholder IDs', implode(', ', array_map('strval', (array) $s['placeholder_ids'])), 'Comma separated. Placeholders count as "no image".');
        self::select('label_mode', 'Label', ['caption' => 'Caption', 'append' => 'Paragraph after the image', 'both' => 'Both', 'off' => 'Off'], (string) $s['label_mode']);
        self::text('label_text', 'Label text', (string) $s['label_text']);
        self::text('alt_template', 'Alt text template', (string) $s['alt_template']);
        self::text('pending_days', 'Pending limit (days)', (string) $s['pending_days'], '', 'number');
        self::text('retention_days', 'Keep rejected images (days)', (string) $s['retention_days'], '-1 keeps them forever.', 'number');
        self::text('row_retention_days', 'Keep request rows (days)', (string) $s['row_retention_days'], '', 'number');
        self::checkbox('hide_pending_in_modal', 'Hide pending suggestions from the media picker', (bool) $s['hide_pending_in_modal']);
        self::checkbox('log_requests', 'Log every request', (bool) $s['log_requests']);
        self::checkbox('keep_on_uninstall', 'Keep everything on uninstall', (bool) $s['keep_on_uninstall']);
        echo '</tbody></table>';
        submit_button('Save settings');
        echo '</form>';

        echo '<h2>' . esc_html('Test') . '</h2>';
        foreach ([Settings::provider_id() => 'Test provider (one counted request)', 'fixture' => 'Test with fixture'] as $id => $label) {
            if ($id === '') {
                continue;
            }
            $confirm = $id === 'fixture' ? '' : ' onsubmit="return confirm(' . esc_attr((string) wp_json_encode('Send one real, counted request to the provider?')) . ');"';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px"' . $confirm . '>';
            echo '<input type="hidden" name="action" value="ffp_test_provider" />';
            echo '<input type="hidden" name="provider" value="' . esc_attr($id) . '" />';
            wp_nonce_field(self::NONCE . '_test', '_ffp_nonce');
            submit_button($label, 'secondary', 'submit', false);
            echo '</form>';
        }

        echo '<h2>' . esc_html('Recent requests') . '</h2><table class="widefat striped"><thead><tr>';
        foreach (['Time', 'Post', 'Trigger', 'Outcome', 'Reason', 'Provider', 'Size'] as $head) {
            echo '<th>' . esc_html($head) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (Plugin::logger()->recent() as $entry) {
            echo '<tr><td>' . esc_html(wp_date('Y-m-d H:i', (int) ($entry['time'] ?? 0)) ?: '') . '</td>'
                . '<td>' . esc_html((string) ($entry['post_id'] ?? '')) . '</td>'
                . '<td>' . esc_html((string) ($entry['trigger'] ?? '')) . '</td>'
                . '<td>' . esc_html((string) ($entry['outcome'] ?? '')) . '</td>'
                . '<td>' . esc_html((string) ($entry['reason'] ?? '')) . '</td>'
                . '<td>' . esc_html((string) ($entry['provider'] ?? '')) . '</td>'
                . '<td>' . esc_html((string) ($entry['size'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function row(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private static function text(string $key, string $label, string $value, string $help = '', string $type = 'text'): void
    {
        $id = 'ffp-' . sanitize_html_class(str_replace(['][', '[', ']'], '-', $key));
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="' . esc_attr($type) . '" step="any" class="regular-text" id="' . esc_attr($id) . '" name="ffp[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" />';
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function textarea(string $key, string $label, string $value, string $help = ''): void
    {
        $id = 'ffp-' . sanitize_html_class(str_replace(['][', '[', ']'], '-', $key));
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        echo '<textarea class="large-text code" rows="4" id="' . esc_attr($id) . '" name="ffp[' . esc_attr($key) . ']">' . esc_textarea($value) . '</textarea>';
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }

    /** @param array<string, string> $options */
    private static function select(string $key, string $label, array $options, string $value, string $help = ''): void
    {
        $id = 'ffp-' . sanitize_html_class(str_replace(['][', '[', ']'], '-', $key));
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        echo '<select id="' . esc_attr($id) . '" name="ffp[' . esc_attr($key) . ']">';
        foreach ($options as $option => $text) {
            echo '<option value="' . esc_attr((string) $option) . '"' . selected($value, (string) $option, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select>';
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function checkbox(string $key, string $label, bool $checked, string $help = ''): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="ffp[' . esc_attr($key) . ']" value="1"' . checked($checked, true, false) . ' /> ' . esc_html('On') . '</label>';
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }
}
