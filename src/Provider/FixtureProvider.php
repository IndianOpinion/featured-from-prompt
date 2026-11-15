<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

/**
 * Offline test provider. No network, no cost, but still counted against
 * the caps so the caps can be tested.
 */
final class FixtureProvider implements ImageProvider
{
    public const ID = 'fixture';

    public const VARIANTS = [
        'wide' => 'wide-1536x864.png',
        'square' => 'square-1024x1024.png',
        'tiny' => 'tiny-512x512.png',
        'not_image' => 'not-image.html',
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Test fixture (no API calls)';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            sizes: [],
            aspect_ratios: [],
            max_prompt_chars: 4000,
            negative_prompt: true,
            formats: ['png'],
            returns: 'bytes',
        );
    }

    public function settings_fields(): array
    {
        return [
            [
                'key' => 'variant',
                'label' => 'Fixture image',
                'type' => 'select',
                'options' => [
                    'wide' => 'Valid 1536 by 864 PNG',
                    'square' => 'Square 1024 by 1024 PNG',
                    'tiny' => 'Tiny 512 by 512 PNG',
                    'not_image' => 'Not an image (HTML)',
                ],
                'default' => 'wide',
            ],
        ];
    }

    public function is_configured(array $settings): bool
    {
        return true;
    }

    public function generate(ImageRequest $request, array $settings): ImageResult
    {
        $variant = (string) ($settings['variant'] ?? 'wide');
        $file = self::VARIANTS[$variant] ?? self::VARIANTS['wide'];
        $path = self::fixture_dir() . '/' . $file;
        if (!is_readable($path)) {
            throw new ConfigException('Fixture file is missing: ' . $file);
        }
        $bytes = (string) file_get_contents($path);
        return new ImageResult(
            bytes: $bytes,
            mime_hint: $variant === 'not_image' ? 'image/png' : 'image/png',
            revised_prompt: '',
            model: 'fixture-' . $variant,
            units: 0.0,
            provider_cost: 0,
            provider_request_id: 'fixture-' . $request->request_id,
        );
    }

    public static function fixture_dir(): string
    {
        return dirname(__DIR__, 2) . '/assets/fixtures';
    }
}
