<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Support;

use IndianOpinion\FeaturedFromPrompt\Clock;

class FrozenClock extends Clock
{
    public function __construct(public int $time = 1794650400)
    {
    }

    public function now(): int
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }
}
