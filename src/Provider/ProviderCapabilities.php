<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

/**
 * What a provider can do. Immutable.
 */
final class ProviderCapabilities
{
    /**
     * @param list<array{0: int, 1: int}> $sizes         Supported sizes, or empty for any size.
     * @param list<string>               $aspect_ratios Ratios such as "16:9", for APIs that take a ratio.
     * @param list<string>               $formats       Subset of png, jpeg, webp.
     * @param list<string>               $local_hosts   Hosts a result URL may use over plain HTTP.
     */
    public function __construct(
        public readonly array $sizes = [],
        public readonly array $aspect_ratios = [],
        public readonly int $max_prompt_chars = 1000,
        public readonly bool $negative_prompt = false,
        public readonly array $formats = ['png', 'jpeg', 'webp'],
        public readonly string $returns = 'both',
        public readonly array $local_hosts = [],
        public readonly int $min_side = 256,
        public readonly int $max_side = 4096,
    ) {
    }
}
