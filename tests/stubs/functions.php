<?php

// phpcs:ignoreFile -- WordPress function stubs for the unit tests.

declare(strict_types=1);

/**
 * In-memory WordPress state for the stubs.
 */
final class WP
{
    /** @var array<int, array<string, mixed>> blog => name => value */
    public static array $options = [];
    /** @var array<string, mixed> Simulated persistent object cache for options, blog-scoped key. */
    public static array $option_cache = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, array<string, mixed>> */
    public static array $meta = [];
    /** @var array<int, array<string, list<WP_Term>>> */
    public static array $terms = [];
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $filters = [];
    /** @var array<int, list<string>> user => caps */
    public static array $caps = [];
    /** @var array<int, WP_User> */
    public static array $users = [];
    public static int $current_user = 0;
    public static int $blog = 1;
    /** @var list<int> */
    public static array $blog_stack = [];
    public static bool $multisite = false;
    /** @var array<string, int> */
    public static array $cron = [];
    public static int $spawned = 0;
    /** @var (callable(string, array<string, mixed>, bool): (array<string, mixed>|WP_Error))|null */
    public static $http = null;
    /** @var list<array{url: string, args: array<string, mixed>, safe: bool}> */
    public static array $http_calls = [];
    public static bool $editor_available = true;
    public static int $editor_calls = 0;
    /** @var list<string> */
    public static array $editor_refuses = [];
    /** @var array<string, bool> */
    public static array $supports = ['post:thumbnail' => true, 'page:thumbnail' => true];
    /** @var array<string, mixed> */
    public static array $context = ['admin' => false, 'feed' => false, 'singular' => false, 'queried' => 0, 'sitemap' => ''];
    public static string $tz = 'UTC';
    public static int $next_id = 100;
    /** @var array<string, array<string, mixed>> */
    public static array $routes = [];
    /** @var array<string, array<string, mixed>> */
    public static array $rest_fields = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    public static string $root = '';

    public static function reset(): void
    {
        self::$options = [];
        self::$option_cache = [];
        self::$posts = [];
        self::$meta = [];
        self::$terms = [];
        self::$filters = [];
        self::$caps = [];
        self::$users = [];
        self::$current_user = 0;
        self::$blog = 1;
        self::$blog_stack = [];
        self::$multisite = false;
        self::$cron = [];
        self::$spawned = 0;
        self::$http = null;
        self::$http_calls = [];
        self::$editor_available = true;
        self::$editor_refuses = [];
        self::$supports = ['post:thumbnail' => true, 'page:thumbnail' => true];
        self::$context = ['admin' => false, 'feed' => false, 'singular' => false, 'queried' => 0, 'sitemap' => ''];
        self::$tz = 'UTC';
        self::$next_id = 100;
        self::$routes = [];
        self::$rest_fields = [];
        self::$transients = [];
    }

    /** @param array<string, mixed> $data */
    public static function add_post(array $data): int
    {
        $id = (int) ($data['ID'] ?? ++self::$next_id);
        self::$next_id = max(self::$next_id, $id);
        $data['ID'] = $id;
        self::$posts[$id] = new WP_Post($data);
        return $id;
    }

    public static function add_user(int $id, array $caps, string $email = ''): void
    {
        self::$users[$id] = new WP_User($id, 'User ' . $id, $email !== '' ? $email : 'user' . $id . '@example.org');
        self::$caps[$id] = $caps;
    }
}

const ARRAY_A = 'ARRAY_A';
const DAY_IN_SECONDS = 86400;
const HOUR_IN_SECONDS = 3600;

// Hooks.
function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    WP::$filters[$hook][$priority][] = [$callback, $accepted_args];
    return true;
}
function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}
function has_filter(string $hook, mixed $callback = false): bool|int
{
    return !empty(WP::$filters[$hook]);
}
function remove_all_filters(string $hook): bool
{
    unset(WP::$filters[$hook]);
    return true;
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if (empty(WP::$filters[$hook])) {
        return $value;
    }
    $byPriority = WP::$filters[$hook];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $value = $callback(...array_slice(array_merge([$value], $args), 0, max(1, $accepted)));
        }
    }
    return $value;
}
function do_action(string $hook, mixed ...$args): void
{
    if (empty(WP::$filters[$hook])) {
        return;
    }
    $byPriority = WP::$filters[$hook];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $callback(...array_slice($args, 0, $accepted));
        }
    }
}

// Options, with a simulated persistent object cache in front of get_option.
function get_option(string $name, mixed $default = false): mixed
{
    $key = WP::$blog . ':' . $name;
    if (array_key_exists($key, WP::$option_cache)) {
        return WP::$option_cache[$key];
    }
    return array_key_exists($name, WP::$options[WP::$blog] ?? []) ? WP::$options[WP::$blog][$name] : $default;
}
function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WP::$options[WP::$blog][$name] = $value;
    unset(WP::$option_cache[WP::$blog . ':' . $name]);
    return true;
}
function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = 'yes'): bool
{
    if (array_key_exists($name, WP::$options[WP::$blog] ?? [])) {
        return false;
    }
    WP::$options[WP::$blog][$name] = $value;
    return true;
}
function delete_option(string $name): bool
{
    unset(WP::$options[WP::$blog][$name], WP::$option_cache[WP::$blog . ':' . $name]);
    return true;
}
function get_transient(string $name): mixed
{
    return WP::$transients[$name] ?? false;
}
function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    WP::$transients[$name] = $value;
    return true;
}
function delete_transient(string $name): bool
{
    unset(WP::$transients[$name]);
    return true;
}
function maybe_unserialize(mixed $value): mixed
{
    if (is_string($value) && preg_match('/^(a|s|i|b|d):\d*[:;]/', $value) === 1) {
        return unserialize($value, ['allowed_classes' => false]);
    }
    return $value;
}

// Posts and meta.
function get_post(mixed $post = null): ?WP_Post
{
    $id = $post instanceof WP_Post ? $post->ID : (int) $post;
    return WP::$posts[$id] ?? null;
}
function get_post_field(string $field, mixed $post): mixed
{
    $p = get_post($post);
    return $p !== null && property_exists($p, $field) ? $p->$field : '';
}
function get_post_mime_type(mixed $post = null): string|false
{
    $p = get_post($post);
    return $p !== null ? $p->post_mime_type : false;
}
function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    if ($key === '') {
        return WP::$meta[$id] ?? [];
    }
    if (!array_key_exists($key, WP::$meta[$id] ?? [])) {
        return $single ? '' : [];
    }
    return $single ? WP::$meta[$id][$key] : [WP::$meta[$id][$key]];
}
function update_post_meta(int $id, string $key, mixed $value, mixed $prev = ''): int|bool
{
    $check = apply_filters('update_post_metadata', null, $id, $key, $value, $prev);
    if ($check !== null) {
        return (bool) $check;
    }
    $exists = array_key_exists($key, WP::$meta[$id] ?? []);
    if ($exists && WP::$meta[$id][$key] === $value) {
        return false;
    }
    WP::$meta[$id][$key] = $value;
    do_action($exists ? 'updated_post_meta' : 'added_post_meta', 1, $id, $key, $value);
    return $exists ? true : 1;
}
function add_post_meta(int $id, string $key, mixed $value, bool $unique = false): int|false
{
    $check = apply_filters('add_post_metadata', null, $id, $key, $value, $unique);
    if ($check !== null) {
        return $check === false ? false : (int) $check;
    }
    if ($unique && array_key_exists($key, WP::$meta[$id] ?? [])) {
        return false;
    }
    WP::$meta[$id][$key] = $value;
    do_action('added_post_meta', 1, $id, $key, $value);
    return 1;
}
function delete_post_meta(int $id, string $key, mixed $value = ''): bool
{
    unset(WP::$meta[$id][$key]);
    return true;
}
function delete_post_meta_by_key(string $key): bool
{
    foreach (WP::$meta as $id => $meta) {
        unset(WP::$meta[$id][$key]);
    }
    return true;
}
function set_post_thumbnail(mixed $post, int $thumbnail_id): int|bool
{
    $p = get_post($post);
    if ($p === null) {
        return false;
    }
    return update_post_meta($p->ID, '_thumbnail_id', $thumbnail_id);
}
function post_type_supports(string $type, string $feature): bool
{
    return WP::$supports[$type . ':' . $feature] ?? false;
}
function wp_attachment_is_image(mixed $post = null): bool
{
    $p = get_post($post);
    return $p !== null && $p->post_type === 'attachment' && str_starts_with($p->post_mime_type, 'image/');
}
function wp_is_post_autosave(mixed $post): int|false
{
    $p = get_post($post);
    return $p !== null && $p->post_type === 'revision' && str_contains($p->post_name, 'autosave') ? $p->post_parent : false;
}
function wp_is_post_revision(mixed $post): int|false
{
    $p = get_post($post);
    return $p !== null && $p->post_type === 'revision' ? $p->post_parent : false;
}
/**
 * @param array<string, mixed> $args
 * @return list<int|WP_Post>
 */
function get_posts(array $args = []): array
{
    $type = $args['post_type'] ?? 'post';
    $status = $args['post_status'] ?? 'publish';
    $out = [];
    foreach (WP::$posts as $post) {
        if ($type !== 'any' && !in_array($post->post_type, (array) $type, true)) {
            continue;
        }
        if ($type === 'any' && in_array($post->post_type, ['attachment', 'revision'], true)) {
            continue;
        }
        if ($status === 'any') {
            if (in_array($post->post_status, ['trash', 'auto-draft'], true)) {
                continue;
            }
        } elseif (!in_array($post->post_status, (array) $status, true)) {
            continue;
        }
        if (isset($args['post_parent']) && $post->post_parent !== (int) $args['post_parent']) {
            continue;
        }
        if (isset($args['meta_key'])) {
            $value = WP::$meta[$post->ID][$args['meta_key']] ?? null;
            if ($value === null) {
                continue;
            }
            if (isset($args['meta_value']) && (string) $value !== (string) $args['meta_value']) {
                continue;
            }
        }
        if (isset($args['date_query'][0])) {
            $dq = $args['date_query'][0];
            $time = strtotime($post->post_date);
            if (isset($dq['after']) && $time < strtotime((string) $dq['after'])) {
                continue;
            }
            if (isset($dq['before']) && $time > strtotime((string) $dq['before'] . ' 23:59:59')) {
                continue;
            }
        }
        $out[] = $post;
    }
    $orderby = $args['orderby'] ?? 'date';
    $desc = strtoupper((string) ($args['order'] ?? 'DESC')) === 'DESC';
    usort($out, static function (WP_Post $a, WP_Post $b) use ($orderby, $desc): int {
        $cmp = $orderby === 'date' ? [strtotime($a->post_date), $a->ID] <=> [strtotime($b->post_date), $b->ID] : $a->ID <=> $b->ID;
        return $desc ? -$cmp : $cmp;
    });
    $limit = (int) ($args['numberposts'] ?? 5);
    if ($limit > 0) {
        $out = array_slice($out, 0, $limit);
    }
    if (($args['fields'] ?? '') === 'ids') {
        return array_map(static fn (WP_Post $p): int => $p->ID, $out);
    }
    return $out;
}
/** @param array<string, mixed> $data */
function wp_insert_attachment(array $data, string|false $file = false, int $parent = 0, bool $wp_error = false): int|WP_Error
{
    $id = WP::add_post([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_mime_type' => (string) ($data['post_mime_type'] ?? ''),
        'post_title' => (string) ($data['post_title'] ?? ''),
        'post_excerpt' => (string) ($data['post_excerpt'] ?? ''),
        'post_parent' => $parent,
    ]);
    if ($file !== false) {
        update_attached_file($id, $file);
    }
    return $id;
}
function update_attached_file(int $id, string $file): bool
{
    $base = wp_upload_dir()['basedir'] . '/';
    if (str_starts_with($file, $base)) {
        $file = substr($file, strlen($base));
    }
    WP::$meta[$id]['_wp_attached_file'] = $file;
    return true;
}
function get_attached_file(int $id): string|false
{
    $file = WP::$meta[$id]['_wp_attached_file'] ?? '';
    if ($file === '') {
        return false;
    }
    return str_starts_with($file, '/') ? $file : wp_upload_dir()['basedir'] . '/' . $file;
}
function wp_delete_attachment(int $id, bool $force = false): WP_Post|false|null
{
    $post = get_post($id);
    if ($post === null) {
        return false;
    }
    do_action('delete_attachment', $id, $post);
    $file = get_attached_file($id);
    if (is_string($file) && str_starts_with($file, wp_upload_dir()['basedir']) && is_file($file)) {
        unlink($file);
    }
    unset(WP::$posts[$id], WP::$meta[$id]);
    return $post;
}
/** @return array<string, mixed> */
function wp_generate_attachment_metadata(int $id, string $file): array
{
    $info = getimagesize($file);
    return ['width' => $info[0] ?? 0, 'height' => $info[1] ?? 0, 'file' => basename($file), 'sizes' => []];
}
function wp_update_attachment_metadata(int $id, mixed $data): bool
{
    WP::$meta[$id]['_wp_attachment_metadata'] = $data;
    return true;
}
function wp_get_attachment_url(int $id): string|false
{
    $file = WP::$meta[$id]['_wp_attached_file'] ?? '';
    $url = 'https://example.org/wp-content/uploads/' . $file;
    return apply_filters('wp_get_attachment_url', $url, $id);
}
/** @return list<WP_Term>|WP_Error */
function wp_get_object_terms(int $id, string $taxonomy, array $args = []): array|WP_Error
{
    return WP::$terms[$id][$taxonomy] ?? [];
}
function get_bloginfo(string $show = ''): string
{
    return $show === 'language' ? 'en-GB' : 'Example';
}

// Users and capabilities.
function get_current_user_id(): int
{
    return WP::$current_user;
}
function user_can(mixed $user, string $cap, mixed ...$args): bool
{
    $id = $user instanceof WP_User ? $user->ID : (int) $user;
    $caps = WP::$caps[$id] ?? [];
    if ($cap === 'edit_post') {
        $post = get_post($args[0] ?? 0);
        return $post !== null && in_array('edit_post', $caps, true);
    }
    return in_array($cap, $caps, true);
}
function current_user_can(string $cap, mixed ...$args): bool
{
    return user_can(WP::$current_user, $cap, ...$args);
}
function get_userdata(int $id): WP_User|false
{
    return WP::$users[$id] ?? false;
}
function get_user_by(string $field, string $value): WP_User|false
{
    foreach (WP::$users as $user) {
        if ($field === 'email' && $user->user_email === $value) {
            return $user;
        }
    }
    return false;
}
function wp_create_nonce(string $action = ''): string
{
    return 'valid-' . $action;
}
function wp_verify_nonce(string $nonce, string $action = ''): int|false
{
    return $nonce === 'valid-' . $action ? 1 : false;
}

// Formatting.
function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}
function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}
function sanitize_text_field(string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
}
function sanitize_html_class(string $class): string
{
    return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $class);
}
function wp_strip_all_tags(string $text): string
{
    $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
    return trim(strip_tags($text));
}
function strip_shortcodes(string $content): string
{
    return (string) preg_replace('/\[\/?[a-z_-]+[^\]]*\]/i', '', $content);
}
function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function esc_url(string $url): string
{
    return $url;
}
function esc_textarea(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function wp_unslash(mixed $value): mixed
{
    return $value;
}
function selected(mixed $a, mixed $b = true, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' selected="selected"' : '';
}
function checked(mixed $a, mixed $b = true, bool $echo = true): string
{
    return $a === $b ? ' checked="checked"' : '';
}
function wp_generate_uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}
function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(WP::$tz);
}
function wp_date(string $format, ?int $timestamp = null): string
{
    return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone(wp_timezone())->format($format);
}
function wp_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}
function wp_mkdir_p(string $dir): bool
{
    return is_dir($dir) || mkdir($dir, 0777, true);
}
function wp_tempnam(string $name = ''): string
{
    $dir = WP::$root . '/tmp';
    wp_mkdir_p($dir);
    $file = $dir . '/' . $name . bin2hex(random_bytes(4)) . '.tmp';
    touch($file);
    return $file;
}
/** @return array<string, mixed> */
function wp_upload_dir(?string $time = null, bool $create_dir = true): array
{
    $base = WP::$root . '/uploads';
    $sub = $time !== null ? '/' . $time : '/2026/11';
    return ['basedir' => $base, 'path' => $base . $sub, 'baseurl' => 'https://example.org/wp-content/uploads', 'url' => 'https://example.org/wp-content/uploads' . $sub, 'error' => false];
}
function wp_unique_filename(string $dir, string $name): string
{
    $candidate = $name;
    $i = 1;
    while (file_exists($dir . '/' . $candidate)) {
        $candidate = pathinfo($name, PATHINFO_FILENAME) . '-' . $i++ . '.' . pathinfo($name, PATHINFO_EXTENSION);
    }
    return $candidate;
}

// Multisite.
function is_multisite(): bool
{
    return WP::$multisite;
}
function get_current_blog_id(): int
{
    return WP::$blog;
}
function switch_to_blog(int $id): bool
{
    WP::$blog_stack[] = WP::$blog;
    WP::$blog = $id;
    return true;
}
function restore_current_blog(): bool
{
    WP::$blog = (int) array_pop(WP::$blog_stack);
    return true;
}
/** @return list<int> */
function get_sites(array $args = []): array
{
    return [1];
}

// HTTP.
/** @return array<string, mixed>|WP_Error */
function ffp_test_http(string $url, array $args, bool $safe): array|WP_Error
{
    WP::$http_calls[] = ['url' => $url, 'args' => $args, 'safe' => $safe];
    if (WP::$http === null) {
        return new WP_Error('http_request_failed', 'No network in tests.');
    }
    return (WP::$http)($url, $args, $safe);
}
/** @return array<string, mixed>|WP_Error */
function wp_remote_request(string $url, array $args = []): array|WP_Error
{
    return ffp_test_http($url, $args, false);
}
/** @return array<string, mixed>|WP_Error */
function wp_safe_remote_request(string $url, array $args = []): array|WP_Error
{
    return ffp_test_http($url, $args, true);
}
function wp_remote_retrieve_response_code(mixed $response): int|string
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
}
function wp_remote_retrieve_body(mixed $response): string
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}
function wp_remote_retrieve_header(mixed $response, string $header): string|array
{
    if (!is_array($response)) {
        return '';
    }
    $headers = array_change_key_case((array) ($response['headers'] ?? []), CASE_LOWER);
    return $headers[strtolower($header)] ?? '';
}

// Cron.
function wp_next_scheduled(string $hook, array $args = []): int|false
{
    return WP::$cron[WP::$blog . ':' . $hook] ?? false;
}
function wp_schedule_event(int $time, string $recurrence, string $hook, array $args = []): bool
{
    WP::$cron[WP::$blog . ':' . $hook] = $time;
    return true;
}
function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    unset(WP::$cron[WP::$blog . ':' . $hook]);
    return 1;
}
function spawn_cron(int $gmt_time = 0): bool
{
    WP::$spawned++;
    return true;
}

// Request context.
function is_admin(): bool
{
    return (bool) WP::$context['admin'];
}
function is_feed(): bool
{
    return (bool) WP::$context['feed'];
}
function is_singular(): bool
{
    return (bool) WP::$context['singular'];
}
function get_queried_object_id(): int
{
    return (int) WP::$context['queried'];
}
function get_query_var(string $var, mixed $default = ''): mixed
{
    return $var === 'sitemap' ? WP::$context['sitemap'] : $default;
}

// REST.
function register_rest_route(string $ns, string $route, array $args = []): bool
{
    WP::$routes[$ns . $route] = $args;
    return true;
}
function register_rest_field(string $type, string $field, array $args = []): void
{
    WP::$rest_fields[$type . ':' . $field] = $args;
}
function rest_url(string $path = ''): string
{
    return 'https://example.org/wp-json/' . ltrim($path, '/');
}
function add_query_arg(mixed ...$args): string
{
    if (is_array($args[0])) {
        $url = (string) ($args[1] ?? '');
        $query = $args[0];
    } else {
        $url = (string) ($args[2] ?? '');
        $query = [(string) $args[0] => $args[1]];
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
}
function admin_url(string $path = ''): string
{
    return 'https://example.org/wp-admin/' . $path;
}

// Images.
function wp_get_image_editor(string $path, array $args = []): object
{
    WP::$editor_calls++;
    if (!WP::$editor_available) {
        return new WP_Error('image_no_editor', 'No editor.');
    }
    return new FFP_Test_Image_Editor($path);
}
function wp_image_editor_supports(array $args = []): bool
{
    return WP::$editor_available;
}
