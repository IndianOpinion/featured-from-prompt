<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

/**
 * The adapter interface every image API sits behind.
 *
 * An adapter turns one ImageRequest into one ImageResult or throws one of the
 * ProviderException subclasses. It must reach the network only through
 * wp_remote_request() or wp_safe_remote_request(), so the plugin can apply
 * the ffp_http_args filter, timeouts and test doubles.
 */
interface ImageProvider
{
    /** Stable slug matching ^[a-z0-9][a-z0-9_-]{0,39}$. */
    public function id(): string;

    /** Human label for the settings screen. */
    public function label(): string;

    public function capabilities(): ProviderCapabilities;

    /**
     * Fields the adapter needs on the settings screen.
     *
     * Each field: key, label, type (text, secret, url, select, number),
     * required (bool), help (string), and options (for select).
     *
     * @return list<array{key: string, label: string, type: string, required?: bool, help?: string, options?: array<string, string>, default?: string}>
     */
    public function settings_fields(): array;

    /**
     * @param array<string, string> $settings Field values, secrets included.
     */
    public function is_configured(array $settings): bool;

    /**
     * @param array<string, string> $settings Field values, secrets included.
     *
     * @throws ProviderException
     */
    public function generate(ImageRequest $request, array $settings): ImageResult;
}
