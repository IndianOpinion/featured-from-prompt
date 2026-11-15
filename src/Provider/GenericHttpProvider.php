<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

use IndianOpinion\FeaturedFromPrompt\Constants;
use IndianOpinion\FeaturedFromPrompt\Downloader;

/**
 * A configurable JSON adapter for any image API that takes one JSON request
 * and answers with base64 image data or an image URL.
 */
final class GenericHttpProvider implements ImageProvider
{
    public const ID = 'generic-http';

    public const DEFAULT_BODY = '{"prompt": "{prompt}", "negative_prompt": "{negative_prompt}", "width": {width}, "height": {height}, "aspect_ratio": "{aspect_ratio}", "output_format": "{format}", "n": 1}';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Generic HTTP JSON';
    }

    public function capabilities(): ProviderCapabilities
    {
        return $this->capabilities_from(self::stored());
    }

    /** @param array<string, string> $settings */
    public function capabilities_from(array $settings): ProviderCapabilities
    {
        $sizes = [];
        foreach (preg_split('/[\s,]+/', (string) ($settings['sizes'] ?? '')) ?: [] as $size) {
            if (preg_match('/^(\d{2,5})x(\d{2,5})$/i', $size, $m) === 1) {
                $sizes[] = [(int) $m[1], (int) $m[2]];
            }
        }
        $ratios = [];
        foreach (preg_split('/[\s,]+/', (string) ($settings['aspect_ratios'] ?? '')) ?: [] as $ratio) {
            if (preg_match('/^\d{1,3}:\d{1,3}$/', $ratio) === 1) {
                $ratios[] = $ratio;
            }
        }
        $max = (int) ($settings['max_prompt'] ?? 1000);
        $local = [];
        $host = $this->endpoint_host((string) ($settings['endpoint'] ?? ''));
        if ($host !== '' && Constants::get('FFP_ALLOW_LOCAL_ENDPOINT') === true && Downloader::is_private_host($host)) {
            $local[] = $host;
        }
        return new ProviderCapabilities(
            sizes: $sizes,
            aspect_ratios: $ratios,
            max_prompt_chars: $max > 0 ? min(8000, $max) : 1000,
            negative_prompt: ($settings['negative_supported'] ?? 'no') === 'yes',
            formats: ['png', 'jpeg', 'webp'],
            returns: ($settings['image_kind'] ?? 'base64') === 'url' ? 'url' : 'bytes',
            local_hosts: $local,
        );
    }

    public function settings_fields(): array
    {
        return [
            ['key' => 'endpoint', 'label' => 'Endpoint URL', 'type' => 'url', 'required' => true, 'help' => 'HTTPS only.'],
            ['key' => 'method', 'label' => 'HTTP method', 'type' => 'select', 'options' => ['POST' => 'POST', 'PUT' => 'PUT'], 'default' => 'POST'],
            ['key' => 'auth_header', 'label' => 'Auth header name', 'type' => 'text', 'default' => 'Authorization', 'help' => 'For example Authorization or X-Api-Key.'],
            ['key' => 'auth_value', 'label' => 'Auth header value', 'type' => 'secret', 'required' => false, 'help' => 'For example "Bearer <key>". Stored privately and never shown again.'],
            ['key' => 'extra_headers', 'label' => 'Extra headers', 'type' => 'text', 'help' => 'One "Name: value" per line. Not for secrets.'],
            ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'help' => 'Available in the body template as {model}.'],
            ['key' => 'body_template', 'label' => 'Request body template (JSON)', 'type' => 'text', 'default' => self::DEFAULT_BODY, 'help' => 'Placeholders: {prompt} {negative_prompt} {width} {height} {aspect_ratio} {format} {model}. String values are JSON-escaped; put quotes around them in the template.'],
            ['key' => 'image_kind', 'label' => 'Response holds', 'type' => 'select', 'options' => ['base64' => 'Base64 image data', 'url' => 'An image URL'], 'default' => 'base64'],
            ['key' => 'image_path', 'label' => 'Path to the image in the response', 'type' => 'text', 'required' => true, 'default' => 'data.0.b64_json', 'help' => 'Dot path, for example data.0.b64_json or output.url.'],
            ['key' => 'revised_prompt_path', 'label' => 'Path to a revised prompt', 'type' => 'text'],
            ['key' => 'model_path', 'label' => 'Path to the model name', 'type' => 'text'],
            ['key' => 'sizes', 'label' => 'Supported sizes', 'type' => 'text', 'help' => 'For example "1024x1024, 1792x1024". Empty means any size.'],
            ['key' => 'aspect_ratios', 'label' => 'Supported aspect ratios', 'type' => 'text', 'help' => 'For APIs that take a ratio, for example "1:1, 16:9".'],
            ['key' => 'max_prompt', 'label' => 'Maximum prompt length', 'type' => 'number', 'default' => '1000'],
            ['key' => 'negative_supported', 'label' => 'Accepts a negative prompt', 'type' => 'select', 'options' => ['no' => 'No', 'yes' => 'Yes'], 'default' => 'no'],
            ['key' => 'refusal_marker', 'label' => 'Refusal marker', 'type' => 'text', 'help' => 'Text that appears in a 400 body when the prompt was refused, for example content_policy.'],
            ['key' => 'poll_url_path', 'label' => 'Asynchronous APIs: path to the job URL', 'type' => 'text', 'help' => 'Leave empty for APIs that answer at once. For submit-then-poll APIs: dot path in the submit answer to the URL to poll, for example urls.get.'],
            ['key' => 'poll_status_path', 'label' => 'Path to the job status', 'type' => 'text', 'default' => 'status'],
            ['key' => 'poll_done', 'label' => 'Status values meaning done', 'type' => 'text', 'default' => 'succeeded, completed, done'],
            ['key' => 'poll_failed', 'label' => 'Status values meaning failed', 'type' => 'text', 'default' => 'failed, canceled, cancelled, error'],
            ['key' => 'poll_interval', 'label' => 'Seconds between polls', 'type' => 'number', 'default' => '3'],
        ];
    }

    public function is_configured(array $settings): bool
    {
        return $this->endpoint_error((string) ($settings['endpoint'] ?? '')) === null
            && trim((string) ($settings['image_path'] ?? '')) !== '';
    }

    public function generate(ImageRequest $request, array $settings): ImageResult
    {
        $endpoint = (string) ($settings['endpoint'] ?? '');
        $error = $this->endpoint_error($endpoint);
        if ($error !== null) {
            throw new ConfigException($error);
        }
        $template = trim((string) ($settings['body_template'] ?? ''));
        $template = $template !== '' ? $template : self::DEFAULT_BODY;
        $body = self::render_body($template, [
            'prompt' => $request->prompt,
            'negative_prompt' => $request->negative_prompt,
            'width' => (string) $request->width,
            'height' => (string) $request->height,
            'aspect_ratio' => $request->aspect_ratio,
            'format' => $request->format,
            'model' => (string) ($settings['model'] ?? ''),
        ]);
        if (json_decode($body) === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new ConfigException('The body template does not give valid JSON.');
        }

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($settings['extra_headers'] ?? '')) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9-]{1,64}):\s*(.+)$/', trim($line), $m) === 1) {
                $headers[$m[1]] = trim($m[2]);
            }
        }
        $secret = (string) ($settings['auth_value'] ?? '');
        $auth_header = trim((string) ($settings['auth_header'] ?? 'Authorization'));
        if ($secret !== '' && preg_match('/^[A-Za-z0-9-]{1,64}$/', $auth_header) === 1) {
            $headers[$auth_header] = $secret;
        }

        $method = strtoupper((string) ($settings['method'] ?? 'POST')) === 'PUT' ? 'PUT' : 'POST';
        $args = [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $request->timeout,
            'redirection' => 0,
        ];
        $filtered = apply_filters('ffp_http_args', $args, $endpoint, 'generate');
        if (is_array($filtered)) {
            $args = $filtered;
        }
        $local = $this->capabilities_from($settings)->local_hosts !== [];
        $response = $local ? wp_remote_request($endpoint, $args) : wp_safe_remote_request($endpoint, $args);

        if (is_wp_error($response)) {
            $message = self::redact($response->get_error_message(), $secret);
            if (stripos($message, 'timed out') !== false || stripos($message, 'cURL error 28') !== false || $response->get_error_code() === 'timeout') {
                throw TransientException::timeout('Request timed out: ' . $message);
            }
            throw new TransientException('HTTP error: ' . $message);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $snippet = self::redact(mb_substr(wp_strip_all_tags($raw), 0, 300), $secret);
        if ($code === 401 || $code === 403) {
            throw new AuthException('Provider answered HTTP ' . $code . '.');
        }
        if ($code === 402) {
            throw new QuotaException('Provider answered HTTP 402.');
        }
        if ($code === 429) {
            $header = wp_remote_retrieve_header($response, 'retry-after');
            $retry = self::retry_after(is_array($header) ? (string) end($header) : $header);
            throw new RateLimitException('Provider answered HTTP 429.', $retry);
        }
        $marker = trim((string) ($settings['refusal_marker'] ?? ''));
        if ($code === 400 && $marker !== '' && stripos($raw, $marker) !== false) {
            throw new PromptRefusedException(self::redact(self::error_message($raw), $secret));
        }
        if ($code >= 500) {
            throw new TransientException('Provider answered HTTP ' . $code . ': ' . $snippet);
        }
        if ($code < 200 || $code >= 300) {
            throw new BadResponseException('Provider answered HTTP ' . $code . ': ' . $snippet);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new BadResponseException('Provider answered with malformed JSON.');
        }
        $poll_path = trim((string) ($settings['poll_url_path'] ?? ''));
        if ($poll_path !== '') {
            [$json, $response] = $this->poll($json, $poll_path, $endpoint, $headers, $request->timeout, $settings, $secret);
        }
        $value = self::path($json, (string) ($settings['image_path'] ?? ''));
        $revised = self::path($json, (string) ($settings['revised_prompt_path'] ?? ''));
        $model = self::path($json, (string) ($settings['model_path'] ?? ''));
        $common = [
            'revised_prompt' => is_string($revised) ? $revised : '',
            'model' => is_string($model) ? $model : (string) ($settings['model'] ?? ''),
            'provider_request_id' => self::request_id($response),
        ];
        if (!is_string($value) || $value === '') {
            return new ImageResult(null, null, '', 0, 0, $common['revised_prompt'], $common['model'], 1.0, null, $common['provider_request_id']);
        }
        if (($settings['image_kind'] ?? 'base64') === 'url') {
            return new ImageResult(null, $value, '', 0, 0, $common['revised_prompt'], $common['model'], 1.0, null, $common['provider_request_id']);
        }
        $mime = '';
        if (preg_match('#^data:([a-z0-9/+.-]+);base64,#i', $value, $m) === 1) {
            $mime = $m[1];
            $value = substr($value, strlen($m[0]));
        }
        $bytes = base64_decode($value, true);
        if ($bytes === false) {
            throw new BadResponseException('Image data is not valid base64.');
        }
        return new ImageResult($bytes, null, $mime, 0, 0, $common['revised_prompt'], $common['model'], 1.0, null, $common['provider_request_id']);
    }

    /** @var (callable(int): void)|null Test hook: replaces sleep() between polls. */
    private static $sleeper = null;

    /** @var (callable(): int)|null Test hook: replaces time() for the poll deadline. */
    private static $clock = null;

    /**
     * Test hooks for the poll loop.
     *
     * @param (callable(int): void)|null $sleeper
     * @param (callable(): int)|null     $clock
     */
    public static function set_poll_hooks(?callable $sleeper, ?callable $clock): void
    {
        self::$sleeper = $sleeper;
        self::$clock = $clock;
    }

    /**
     * Submit-then-poll: read the job URL from the submit answer and poll it
     * until the job is done, failed, or the request timeout runs out. Polls
     * are not billed; the submit was the attempt. The auth header goes only
     * to the endpoint's own host.
     *
     * @param array<mixed>          $submitted
     * @param array<string, string> $headers
     * @param array<string, string> $settings
     * @return array{0: array<mixed>, 1: array<mixed>}
     */
    private function poll(array $submitted, string $poll_path, string $endpoint, array $headers, int $timeout, array $settings, string $secret): array
    {
        $url = self::path($submitted, $poll_path);
        if (!is_string($url) || $url === '') {
            throw new BadResponseException('The submit answer has no job URL at ' . $poll_path . '.');
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new BadResponseException('The job URL must use HTTPS.');
        }
        $local = $this->capabilities_from($settings)->local_hosts;
        $same_host = strtolower((string) $parts['host']) === $this->endpoint_host($endpoint);
        if (!$same_host) {
            unset($headers[trim((string) ($settings['auth_header'] ?? 'Authorization'))]);
        }
        $status_path = trim((string) ($settings['poll_status_path'] ?? 'status'));
        $done = self::values((string) ($settings['poll_done'] ?? 'succeeded, completed, done'));
        $failed = self::values((string) ($settings['poll_failed'] ?? 'failed, canceled, cancelled, error'));
        $interval = max(1, min(30, (int) ($settings['poll_interval'] ?? 3)));
        $now = self::$clock ?? 'time';
        $sleep = self::$sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $deadline = $now() + $timeout;
        $marker = trim((string) ($settings['refusal_marker'] ?? ''));

        while (true) {
            if ($now() >= $deadline) {
                throw TransientException::timeout('The job did not finish within the timeout.');
            }
            $sleep($interval);
            $args = ['method' => 'GET', 'headers' => $headers, 'timeout' => max(5, min(30, $deadline - $now())), 'redirection' => 0];
            $filtered = apply_filters('ffp_http_args', $args, $url, 'poll');
            $args = is_array($filtered) ? $filtered : $args;
            $is_local = in_array(strtolower((string) $parts['host']), $local, true);
            $response = $is_local ? wp_remote_request($url, $args) : wp_safe_remote_request($url, $args);
            if (is_wp_error($response)) {
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code === 401 || $code === 403) {
                throw new AuthException('Job URL answered HTTP ' . $code . '.');
            }
            if ($code === 429 || $code >= 500) {
                continue;
            }
            if ($code < 200 || $code >= 300) {
                throw new BadResponseException('Job URL answered HTTP ' . $code . '.');
            }
            $raw = (string) wp_remote_retrieve_body($response);
            $json = json_decode($raw, true);
            if (!is_array($json)) {
                continue;
            }
            $status = self::path($json, $status_path);
            $status = is_scalar($status) ? strtolower((string) $status) : '';
            if (in_array($status, $done, true)) {
                return [$json, $response];
            }
            if (in_array($status, $failed, true)) {
                if ($marker !== '' && stripos($raw, $marker) !== false) {
                    throw new PromptRefusedException(self::redact(self::error_message($raw), $secret));
                }
                throw new BadResponseException('The job failed with status ' . $status . '.');
            }
        }
    }

    /** @return list<string> */
    private static function values(string $list): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower($list)) ?: [] as $value) {
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    /** @param array<string, string> $values */
    public static function render_body(string $template, array $values): string
    {
        return (string) preg_replace_callback('/\{(prompt|negative_prompt|width|height|aspect_ratio|format|model)\}/', static function (array $m) use ($values): string {
            $encoded = (string) wp_json_encode($values[$m[1]] ?? '');
            return substr($encoded, 1, -1);
        }, $template);
    }

    /**
     * Read a dot path such as data.0.b64_json.
     *
     * @param array<mixed> $data
     */
    public static function path(array $data, string $path): mixed
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        $node = $data;
        foreach (explode('.', $path) as $key) {
            if (is_array($node) && array_key_exists($key, $node)) {
                $node = $node[$key];
                continue;
            }
            if (is_array($node) && ctype_digit($key) && array_key_exists((int) $key, $node)) {
                $node = $node[(int) $key];
                continue;
            }
            return null;
        }
        return $node;
    }

    /** Retry-After in seconds or as an HTTP date. */
    public static function retry_after(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $time = strtotime($value);
        if ($time === false) {
            return null;
        }
        return max(0, $time - time());
    }

    private static function error_message(string $raw): string
    {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            foreach (['error.message', 'message', 'error', 'detail'] as $path) {
                $value = self::path($json, $path);
                if (is_string($value) && $value !== '') {
                    return mb_substr($value, 0, 500);
                }
            }
        }
        return mb_substr(wp_strip_all_tags($raw), 0, 500);
    }

    /** @param array<mixed>|\WP_Error $response */
    private static function request_id($response): string
    {
        foreach (['x-request-id', 'request-id', 'x-amzn-requestid'] as $name) {
            $value = wp_remote_retrieve_header($response, $name);
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 200);
            }
        }
        return '';
    }

    private static function redact(string $text, string $secret): string
    {
        if ($secret !== '') {
            $text = str_replace($secret, '[redacted]', $text);
            $parts = preg_split('/\s+/', $secret) ?: [];
            foreach ($parts as $part) {
                if (strlen($part) >= 8) {
                    $text = str_replace($part, '[redacted]', $text);
                }
            }
        }
        return $text;
    }

    private function endpoint_host(string $endpoint): string
    {
        $parts = wp_parse_url($endpoint);
        return is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';
    }

    private function endpoint_error(string $endpoint): ?string
    {
        $parts = wp_parse_url($endpoint);
        if ($endpoint === '' || !is_array($parts) || empty($parts['host'])) {
            return 'Endpoint URL is missing or invalid.';
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return 'Endpoint URL must use HTTPS.';
        }
        if (Downloader::is_private_host((string) $parts['host']) && Constants::get('FFP_ALLOW_LOCAL_ENDPOINT') !== true) {
            return 'Endpoint URL points to a private address. Define FFP_ALLOW_LOCAL_ENDPOINT to allow it.';
        }
        return null;
    }

    /** @return array<string, string> */
    private static function stored(): array
    {
        $all = (array) \IndianOpinion\FeaturedFromPrompt\Settings::get('provider_settings');
        $mine = isset($all[self::ID]) && is_array($all[self::ID]) ? $all[self::ID] : [];
        $out = [];
        foreach ($mine as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }
        return $out;
    }
}
