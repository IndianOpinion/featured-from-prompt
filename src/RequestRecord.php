<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * A request row after run_request(). The reason is what this run decided;
 * the row is the stored state.
 */
final class RequestRecord
{
    /** @param array<string, mixed> $row */
    public function __construct(
        public readonly string $reason,
        public readonly array $row = [],
    ) {
    }

    public function state(): string
    {
        return (string) ($this->row['state'] ?? '');
    }

    public function id(): string
    {
        return (string) ($this->row['id'] ?? '');
    }

    public function attachment_id(): int
    {
        return (int) ($this->row['attachment_id'] ?? 0);
    }

    public function is_failure(): bool
    {
        return in_array($this->state(), ['failed', 'skipped'], true);
    }

    /** @return array<string, mixed> */
    public function to_array(): array
    {
        $row = $this->row;
        return ['reason' => $this->reason] + $row;
    }
}
