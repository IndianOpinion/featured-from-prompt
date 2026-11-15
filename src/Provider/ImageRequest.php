<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

/**
 * One request for one image. Count is always 1.
 */
final class ImageRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $negative_prompt,
        public readonly int $width,
        public readonly int $height,
        public readonly string $aspect_ratio,
        public readonly string $format,
        public readonly int $timeout,
        public readonly string $request_id,
        public readonly int $post_id,
        public readonly int $count = 1,
    ) {
    }
}
