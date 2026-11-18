<?php

/**
 * A provider adapter for an image API with its own request shape.
 *
 * Drop this into a small plugin of your own. The adapter must call the API
 * only through wp_remote_request() or wp_safe_remote_request(), and must map
 * failures to the exception types the plugin understands.
 */

// phpcs:disable PSR1.Files.SideEffects, PSR1.Classes.ClassDeclaration.MissingNamespace

declare(strict_types=1);

use IndianOpinion\FeaturedFromPrompt\Provider\AuthException;
use IndianOpinion\FeaturedFromPrompt\Provider\BadResponseException;
use IndianOpinion\FeaturedFromPrompt\Provider\ConfigException;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageProvider;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageRequest;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageResult;
use IndianOpinion\FeaturedFromPrompt\Provider\ProviderCapabilities;
use IndianOpinion\FeaturedFromPrompt\Provider\RateLimitException;
use IndianOpinion\FeaturedFromPrompt\Provider\TransientException;
use IndianOpinion\FeaturedFromPrompt\Registry;

final class Example_Studio_Provider implements ImageProvider
{
    public function id(): string
    {
        return 'example-studio';
    }

    public function label(): string
    {
        return 'Example Studio';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            sizes: [[1024, 1024], [1792, 1024]],
            max_prompt_chars: 2000,
            negative_prompt: true,
            formats: ['png'],
            returns: 'bytes',
        );
    }

    public function settings_fields(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'secret', 'required' => true],
            ['key' => 'quality', 'label' => 'Quality', 'type' => 'select', 'options' => ['standard' => 'Standard', 'high' => 'High'], 'default' => 'standard'],
        ];
    }

    public function is_configured(array $settings): bool
    {
        return ($settings['api_key'] ?? '') !== '';
    }

    public function generate(ImageRequest $request, array $settings): ImageResult
    {
        if (($settings['api_key'] ?? '') === '') {
            throw new ConfigException('No API key.');
        }
        $response = wp_safe_remote_request('https://api.studio.example.com/v2/images', [
            'method' => 'POST',
            'timeout' => $request->timeout,
            'headers' => ['Authorization' => 'Bearer ' . $settings['api_key'], 'Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode([
                'prompt' => $request->prompt,
                'avoid' => $request->negative_prompt,
                'size' => $request->width . 'x' . $request->height,
                'quality' => $settings['quality'] ?? 'standard',
            ]),
        ]);
        if (is_wp_error($response)) {
            throw new TransientException($response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 401) {
            throw new AuthException('Bad key.');
        }
        if ($code === 429) {
            throw new RateLimitException('Slow down.', 60);
        }
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($json) || !isset($json['image']) || !is_string($json['image'])) {
            throw new BadResponseException('Unexpected answer, HTTP ' . $code . '.');
        }
        return new ImageResult(bytes: (string) base64_decode($json['image'], true), model: 'studio-v2');
    }
}

add_action('ffp_register_providers', static function (Registry $registry): void {
    $registry->register(new Example_Studio_Provider());
});
