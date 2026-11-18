<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * A rendered prompt and how it was made.
 */
final class PromptPreview
{
    /**
     * @param array<string, string> $variables
     */
    public function __construct(
        public readonly string $prompt,
        public readonly array $variables,
        public readonly bool $truncated,
        public readonly int $max_chars,
        public readonly bool $override,
        public readonly string $negative_prompt,
        public readonly string $title,
    ) {
    }

    /** @return array<string, mixed> */
    public function to_array(): array
    {
        return [
            'prompt' => $this->prompt,
            'variables' => $this->variables,
            'truncated' => $this->truncated,
            'max_chars' => $this->max_chars,
            'override' => $this->override,
            'negative_prompt' => $this->negative_prompt,
        ];
    }
}
