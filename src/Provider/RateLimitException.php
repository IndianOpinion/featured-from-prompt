<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

/**
 * HTTP 429 or the provider's equivalent.
 */
class RateLimitException extends ProviderException
{
    public function __construct(string $message = 'Rate limited.', private readonly ?int $retry_after = null)
    {
        parent::__construct($message);
    }

    /** Seconds to wait, from the provider's hint, or null when it gave none. */
    public function retryAfter(): ?int
    {
        return $this->retry_after;
    }
}
