<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Case 38: fetch a result URL safely.
 */
final class Downloader
{
    /**
     * @param list<string> $local_hosts Hosts allowed over plain HTTP.
     * @throws CheckFailed
     */
    public static function fetch(string $url, int $max_bytes, int $timeout, array $local_hosts): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'Result URL is not a valid URL.');
        }
        $host = strtolower((string) $parts['host']);
        $scheme = strtolower((string) $parts['scheme']);
        $local = in_array($host, array_map('strtolower', $local_hosts), true);
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'Result URL must use HTTPS.');
        }
        if (!$local && self::is_private_host($host)) {
            throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'Result URL points to a private address.');
        }

        $args = [
            'timeout' => $timeout,
            'redirection' => 3,
            'limit_response_size' => $max_bytes + 1,
            'reject_unsafe_urls' => true,
            'method' => 'GET',
        ];
        $filtered = apply_filters('ffp_http_args', $args, $url, 'download');
        if (is_array($filtered)) {
            $args = array_merge($args, $filtered);
        }
        // Safe mode and the limits cannot be turned off by the filter.
        $args['reject_unsafe_urls'] = !$local || !empty($args['reject_unsafe_urls']);
        $args['redirection'] = min(3, max(0, (int) ($args['redirection'] ?? 3)));
        $args['limit_response_size'] = $max_bytes + 1;

        $response = $local ? wp_remote_request($url, $args) : wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'Download failed: ' . $response->get_error_code());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'Download returned HTTP ' . $code . '.');
        }
        $body = (string) wp_remote_retrieve_body($response);
        if (strlen($body) > $max_bytes) {
            throw new CheckFailed(Reason::FILE_TOO_LARGE, 'Image is larger than the byte limit.');
        }
        return $body;
    }

    /** True for literal private, loopback, link-local or reserved addresses and obvious local names. */
    public static function is_private_host(string $host): bool
    {
        $host = trim($host, '[]');
        if (
            $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
        ) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        return false;
    }
}
