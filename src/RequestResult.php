<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Outcome of request(): a queued request id, or a reason code.
 */
final class RequestResult
{
    public function __construct(
        public readonly bool $queued,
        public readonly ?string $request_id,
        public readonly string $reason,
    ) {
    }

    /** @return array{queued: bool, request_id: ?string, reason: string} */
    public function to_array(): array
    {
        return ['queued' => $this->queued, 'request_id' => $this->request_id, 'reason' => $this->reason];
    }
}
