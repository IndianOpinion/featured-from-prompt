<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Outcome of Accept or Reject.
 */
class ReviewResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason = '',
        public readonly int $attachment_id = 0,
        public readonly int $post_id = 0,
        public readonly int $previous_id = 0,
        public readonly int $lock_user = 0,
    ) {
    }

    /** @return array<string, mixed> */
    public function to_array(): array
    {
        return [
            'ok' => $this->ok,
            'reason' => $this->reason,
            'attachment_id' => $this->attachment_id,
            'post_id' => $this->post_id,
            'previous_id' => $this->previous_id,
            'lock_user' => $this->lock_user,
        ];
    }
}
