<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Case 38: fetch a result URL safely, and the URL checks shared with the
 * submit-then-poll adapter.
 *
 * Redirects are followed here, one hop at a time, so every hop gets the same
 * checks as the first URL: HTTPS, no private, loopback, link-local or
 * reserved address (literal or resolved). WordPress's own safe mode lets
 * link-local addresses through, so it is not relied on alone.
 */
final class Downloader
{
    public const MAX_REDIRECTS = 3;

    /** @var (callable(string): list<string>)|null Test hook: replaces DNS resolution. */
    private static $resolver = null;

    /** @param (callable(string): list<string>)|null $resolver */
    public static function set_resolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @param list<string> $local_hosts Hosts allowed over plain HTTP and on private addresses.
     * @throws CheckFailed
     */
    public static function fetch(string $url, int $max_bytes, int $timeout, array $local_hosts): string
    {
        $hops = 0;
        while (true) {
            $local = self::check_url($url, $local_hosts);

            $args = [
                'timeout' => $timeout,
                'redirection' => 0,
                'limit_response_size' => $max_bytes + 1,
                'reject_unsafe_urls' => true,
                'method' => 'GET',
            ];
            $filtered = apply_filters('ffp_http_args', $args, $url, 'download');
            if (is_array($filtered)) {
                $args = array_merge($args, $filtered);
            }
            // Safe mode, redirects and the limits cannot be changed by the filter.
            $args['reject_unsafe_urls'] = !$local || !empty($args['reject_unsafe_urls']);
            $args['redirection'] = 0;
            $args['limit_response_size'] = $max_bytes + 1;
            unset($args['stream'], $args['filename']);

            $response = $local ? wp_remote_request($url, $args) : wp_safe_remote_request($url, $args);
            if (is_wp_error($response)) {
                throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'Download failed: ' . $response->get_error_code());
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if (in_array($code, [301, 302, 303, 307, 308], true)) {
                $location = wp_remote_retrieve_header($response, 'location');
                $location = is_array($location) ? (string) end($location) : (string) $location;
                if ($location === '') {
                    throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'Redirect without a location.');
                }
                if (++$hops > self::MAX_REDIRECTS) {
                    throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'Too many redirects.');
                }
                $url = class_exists('\WP_Http') ? \WP_Http::make_absolute_url($location, $url) : $location;
                continue;
            }
            if ($code !== 200) {
                throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'Download returned HTTP ' . $code . '.');
            }
            $body = (string) wp_remote_retrieve_body($response);
            if (strlen($body) > $max_bytes) {
                throw new CheckFailed(Reason::FILE_TOO_LARGE, 'Image is larger than the byte limit.');
            }
            return $body;
        }
    }

    /**
     * Check one URL. Returns true when the host is a declared local host.
     *
     * @param list<string> $local_hosts
     * @throws CheckFailed
     */
    public static function check_url(string $url, array $local_hosts): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'URL is not a valid URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'URL must not carry credentials.');
        }
        $host = strtolower((string) $parts['host']);
        $scheme = strtolower((string) $parts['scheme']);
        $local = in_array($host, array_map('strtolower', $local_hosts), true);
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'URL must use HTTPS.');
        }
        if (!$local && self::resolves_private($host)) {
            throw new CheckFailed(Reason::DOWNLOAD_FAILED, 'URL points to a private address.');
        }
        return $local;
    }

    /** True for a private host, literal or by any address it resolves to. */
    public static function resolves_private(string $host): bool
    {
        if (self::is_private_host($host)) {
            return true;
        }
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        foreach (self::resolve($bare) as $ip) {
            if (self::is_private_host($ip)) {
                return true;
            }
        }
        return false;
    }

    /** True for literal private, loopback, link-local or reserved addresses and obvious local names. */
    public static function is_private_host(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if (
            $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
        ) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
            // IPv6 forms PHP's flags miss: unique local, link-local, IPv4-mapped.
            if (str_contains($host, ':')) {
                if (preg_match('/^(fc|fd|fe[89ab])/i', $host) === 1) {
                    return true;
                }
                if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $host, $m) === 1) {
                    return self::is_private_host($m[1]);
                }
            }
            return false;
        }
        // Decimal, octal or hex IPv4 spellings ("2130706433", "0x7f.1").
        if (preg_match('/^[0-9.x]+$/i', $host) === 1 && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host) !== 1) {
            return true;
        }
        return false;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }
        $ips = [];
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        if (function_exists('dns_get_record')) {
            $records = dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }
        return $ips;
    }
}
