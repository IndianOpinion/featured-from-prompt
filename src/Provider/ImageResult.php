<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

/**
 * What a provider returned. Hints are recorded but never trusted.
 */
final class ImageResult
{
    public function __construct(
        public readonly ?string $bytes = null,
        public readonly ?string $url = null,
        public readonly string $mime_hint = '',
        public readonly int $width_hint = 0,
        public readonly int $height_hint = 0,
        public readonly string $revised_prompt = '',
        public readonly string $model = '',
        public readonly float $units = 1.0,
        public readonly ?int $provider_cost = null,
        public readonly string $provider_request_id = '',
    ) {
    }
}
