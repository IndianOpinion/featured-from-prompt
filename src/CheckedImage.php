<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * An image that passed every check, as a temporary file.
 */
final class CheckedImage
{
    public function __construct(
        public readonly string $path,
        public readonly string $mime,
        public readonly string $ext,
        public readonly int $width,
        public readonly int $height,
        public readonly int $original_width,
        public readonly int $original_height,
        public readonly bool $cropped,
        public readonly bool $converted,
        public readonly bool $convert_skipped,
        public readonly bool $crop_skipped = false,
    ) {
    }
}
